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
        $content = $pushBody === null
            ? $this->api->fetchChannelFeed($slug)
            : $this->parser->rewriteInboundPush($pushBody);

        $xml = simplexml_load_string($content);
        if ($xml === false) {
            throw new \RuntimeException("Invalid feed XML for {$slug}");
        }
        $array = json_decode(json_encode($xml), true);

        // Poll bodies are trusted (HTTPS from youtube.com); push videos are API-verified below.
        $verify = $pushBody !== null;
        $channelId = $this->resolveChannel($slug, $array, trusted: !$verify);

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
            if (strtotime($entry['published']) < time() - 3600 * 24 * 14) {
                $content = $this->parser->deleteEntry($videoId, $content);
                continue;
            }
            $this->saveEntry($videoId, $channelId, $entry, $content, $verify, $slug);
        }
        $this->repo->touchChannel($slug);
        $this->repo->refreshChannelPublishedTimes();
    }

    private function resolveChannel(string $slug, array $array, bool $trusted): int
    {
        // No auto-insert: channels come only from `channel:add`, so a web hit to an unknown slug can't create one.
        $channel = $this->repo->findChannel($slug);
        if ($channel === null || empty($channel['active'])) {
            throw new \RuntimeException("Channel {$slug} not active");
        }
        $channelId = (int) $channel['id'];
        // Fill from the feed only while the title is still the slug placeholder (keeps healed/manual names); trusted poll only.
        if ($trusted && $channel['title'] === $slug && !empty($array['author']['name'])) {
            $this->repo->updateChannelTitle($channelId, $array['author']['name']);
        }
        return $channelId;
    }

    private function saveEntry(
        string $videoId,
        int $channelId,
        array $entryData,
        string $rawContent,
        bool $verify,
        string $expectedSlug,
    ): void {
        $info = null;
        // Push path: verify the video exists and is owned by this channel, and trust the API's title over the body's.
        if ($verify && $this->api->hasKey()) {
            $info = $this->api->fetchVideoInfo($videoId);
            if ($info['channelId'] === null) {
                return; // unknown to the API yet; the next poll catches a real one.
            }
            if ($info['channelId'] !== $expectedSlug) {
                return; // forged push: video belongs to another channel.
            }
            if ($info['title'] !== null) {
                $entryData['title'] = $info['title'];
            }
        }

        $video = $this->repo->findVideo($videoId);
        // Drop the +00:00 offset; treat the remainder as UTC.
        [$publishedNaive] = explode('+', str_replace('T', ' ', $entryData['published']));
        $entryXml = $this->parser->findEntry($videoId, $rawContent);

        if ($video !== null) {
            $this->repo->updateVideo((int) $video['id'], $entryData['title'], $entryXml);
            return;
        }

        $info ??= $this->api->fetchVideoInfo($videoId);
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
        $videos = $this->repo->recentVideosForPing();
        $blacklist = $this->repo->blacklistTerms();

        // Ping the hub only if the newest non-blacklisted video landed in the last 10 minutes.
        $latestUpdated = null;
        foreach ($videos as $video) {
            $excluded = false;
            foreach ($blacklist as $term) {
                if (stripos($video['title'], $term) !== false) {
                    $excluded = true;
                    break;
                }
            }
            if (!$excluded) {
                $latestUpdated = strtotime($video['updated']);
                break;
            }
        }
        if ($latestUpdated !== null && (time() - $latestUpdated) < 600) {
            $this->hub->publish();
        }
        $this->repo->clearOldVideoContent();
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
