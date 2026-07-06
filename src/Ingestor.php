<?php

declare(strict_types=1);

namespace App;

/** Shared ingestion logic used by the CLI cron/ingest commands and the web PubSubHubbub callback route. */
final class Ingestor
{
    public function __construct(
        private readonly Repository $repo,
        private readonly YoutubeApi $api,
        private readonly FeedParser $parser,
        private readonly Hub $hub,
        private readonly string $auditLogPath = '',
        private readonly string $timezone = 'UTC',
    ) {}

    public function processActiveChannels(): void
    {
        $channels = $this->repo->activeChannelsToProcess();
        if (count($channels) === 0) {
            return;
        }
        foreach ($channels as $channel) {
            try {
                $this->processChannel($channel['slug']);
            } catch (\Throwable $e) {
                // One bad channel shouldn't abort the batch.
                error_log("[myvideofeed] channel {$channel['slug']}: " . $e->getMessage());
            }
        }
        $this->pingChannel();
    }

    public function processChannel(string $slug, ?string $pushBody = null): void
    {
        // Validate before any outbound work: no auto-insert, so an unknown/inactive slug does nothing.
        $channel = $this->repo->findChannel($slug);
        if ($channel === null || empty($channel['active'])) {
            throw new \RuntimeException("Channel {$slug} not active");
        }
        $channelId = (int) $channel['id'];

        if ($pushBody === null) {
            $this->processPoll($slug, $channelId, (string) $channel['title']);
        } else {
            $this->processPush($slug, $channelId, $pushBody);
        }
        $this->repo->touchChannel($slug);
        $this->repo->refreshChannelPublishedTimes();
    }

    /** Poll path: the feed is trusted (HTTPS from youtube.com), so its entry XML is stored as-is. */
    private function processPoll(string $slug, int $channelId, string $currentTitle): void
    {
        $content = $this->api->fetchChannelFeed($slug);
        // LIBXML_NONET: never resolve external references while parsing (defence in depth; no NOENT).
        $xml = simplexml_load_string($content, \SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false) {
            throw new \RuntimeException("Invalid feed XML for {$slug}");
        }
        $array = json_decode(json_encode($xml), true);

        // Fill the title from the feed only while it's still the slug placeholder (keeps healed/manual names).
        if ($currentTitle === $slug && !empty($array['author']['name'])) {
            $this->repo->updateChannelTitle($channelId, $array['author']['name']);
        }

        // Single-entry feeds come back as an associative array; normalise to a list.
        if (isset($array['entry']['id'])) {
            $array['entry'] = [$array['entry']];
        }
        foreach ($array['entry'] ?? [] as $entry) {
            $href = $entry['link']['@attributes']['href'] ?? '';
            if ($href === '' || stripos($href, 'watch') === false) {
                continue;
            }
            $videoId = str_replace('yt:video:', '', $entry['id']);
            if (!self::isValidVideoId($videoId)) {
                continue;
            }
            if (strtotime($entry['published']) < time() - 3600 * 24 * 14) {
                $content = $this->parser->deleteEntry($videoId, $content);
                continue;
            }
            $this->savePollEntry($videoId, $channelId, $entry, $content);
        }
    }

    /** Push path: body is untrusted, so content is always rebuilt via buildEntry (never stored raw); a key adds ownership verification. */
    private function processPush(string $slug, int $channelId, string $body): void
    {
        // @-suppressed: a malformed hostile push is simply dropped, not logged as a warning.
        $xml = @simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false) {
            return;
        }
        $array = json_decode(json_encode($xml), true);
        if (isset($array['entry']['id'])) {
            $array['entry'] = [$array['entry']];
        }
        foreach ($array['entry'] ?? [] as $entry) {
            $videoId = str_replace('yt:video:', '', (string) ($entry['id'] ?? ''));
            if (!self::isValidVideoId($videoId)) {
                continue;
            }
            $title = isset($entry['title']) && is_string($entry['title']) ? $entry['title'] : $videoId;
            $published = isset($entry['published']) && is_string($entry['published']) ? $entry['published'] : '';
            $this->savePushEntry($slug, $channelId, $videoId, $title, $published);
        }
    }

    private function savePushEntry(string $slug, int $channelId, string $videoId, string $title, string $published): void
    {
        $duration = null;
        $viewable = true;
        if ($this->api->hasKey()) {
            $info = $this->api->fetchVideoInfo($videoId);
            if ($info['channelId'] !== $slug) {
                return; // unknown to the API yet, or a forged push for another channel's video.
            }
            $title = $info['title'] ?? $title;
            $published = $info['published'] ?? $published;
            $duration = $info['duration'];
            $viewable = $info['viewable'];
        }

        // Normalise to a safe canonical timestamp: attacker text never reaches the feed raw, nor can a future date pin the entry to the top.
        $ts = min($published !== '' ? (strtotime($published) ?: time()) : time(), time());
        if ($ts < time() - 3600 * 24 * 14) {
            return; // older than the poll window; not worth storing.
        }
        $entryXml = $this->parser->buildEntry($videoId, $title, gmdate('Y-m-d\TH:i:s\Z', $ts));

        $video = $this->repo->findVideo($videoId);
        if ($video !== null) {
            $this->repo->updateVideo((int) $video['id'], $title, $entryXml);
            return;
        }
        if (!$viewable) {
            $this->appendAuditLog($videoId, $entryXml);
            return;
        }
        $this->repo->insertVideo(
            channelId: $channelId,
            slug: $videoId,
            title: $title,
            content: $entryXml,
            duration: $duration,
            published: gmdate('Y-m-d H:i:s', $ts),
        );
    }

    private function savePollEntry(string $videoId, int $channelId, array $entryData, string $rawContent): void
    {
        $video = $this->repo->findVideo($videoId);
        // Drop the +00:00 offset; treat the remainder as UTC.
        [$publishedNaive] = explode('+', str_replace('T', ' ', $entryData['published']));
        $entryXml = $this->parser->findEntry($videoId, $rawContent);

        if ($video !== null) {
            $this->repo->updateVideo((int) $video['id'], $entryData['title'], $entryXml);
            return;
        }

        $info = $this->api->fetchVideoInfo($videoId);
        if (!$info['viewable']) {
            $this->appendAuditLog($videoId, $entryXml);
            return;
        }
        $this->repo->insertVideo(
            channelId: $channelId,
            slug: $videoId,
            title: $entryData['title'],
            content: $entryXml,
            duration: $info['duration'],
            published: gmdate('Y-m-d H:i:s', strtotime($publishedNaive . ' UTC')),
        );
    }

    private static function isValidVideoId(string $videoId): bool
    {
        return preg_match('#^[A-Za-z0-9_-]{11}$#', $videoId) === 1;
    }

    private function appendAuditLog(string $videoId, string $entryXml): void
    {
        if ($this->auditLogPath === '') {
            return;
        }
        $dir = dirname($this->auditLogPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $now = (new \DateTimeImmutable('now', new \DateTimeZone($this->timezone)))->format('r');
        file_put_contents(
            $this->auditLogPath,
            "{$now} - not viewable ({$videoId})\n{$entryXml}\n\n",
            FILE_APPEND,
        );
    }

    public function pingChannel(): void
    {
        if (self::shouldPublish($this->repo->recentVideosForPing(), $this->repo->blacklistTerms())) {
            $this->hub->publish();
        }
        $this->repo->clearOldVideoContent();
    }

    /**
     * Ping the hub only if the newest non-blacklisted video was updated within the window.
     *
     * @param list<array{title: string, updated: string}> $videos newest-updated first
     * @param list<string> $blacklistTerms
     */
    public static function shouldPublish(array $videos, array $blacklistTerms, int $windowSeconds = 600): bool
    {
        foreach ($videos as $video) {
            foreach ($blacklistTerms as $term) {
                if (stripos($video['title'], $term) !== false) {
                    continue 2; // blacklisted; look at the next-newest video.
                }
            }
            return (time() - strtotime($video['updated'])) < $windowSeconds;
        }
        return false;
    }

    public function subscribeAll(): void
    {
        foreach ($this->repo->subscribableChannels() as $channel) {
            try {
                $this->hub->subscribe($channel['slug']);
            } catch (\Throwable $e) {
                // Don't let one bad subscription block the rest.
                error_log("[myvideofeed] subscribe {$channel['slug']}: " . $e->getMessage());
            }
        }
    }
}
