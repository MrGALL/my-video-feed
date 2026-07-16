<?php

declare(strict_types=1);

namespace App;

/** Shared ingestion logic used by the CLI cron/ingest commands and the web PubSubHubbub callback route. */
final class Ingestor
{
    private const int POLL_DEBOUNCE_SECONDS = 600;

    public function __construct(
        private readonly Repository $repo,
        private readonly YoutubeApi $api,
        private readonly FeedParser $parser,
        private readonly Hub $hub,
        private readonly string $auditLogPath = '',
        private readonly string $timezone = 'UTC',
        private readonly bool $detectShorts = false,
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
            // Debounce polls so a public GET can't force repeated fetches within the window; push is never debounced.
            if (self::updatedWithinSeconds($channel['updated'] ?? null, self::POLL_DEBOUNCE_SECONDS)) {
                return;
            }
            $this->processPoll($slug, $channelId, (string) $channel['title']);
        } else {
            $this->processPush($slug, $channelId, $pushBody);
        }
        $this->repo->touchChannel($slug);
        $this->repo->refreshChannelPublishedTimes();
    }

    /** True if $updated (stored UTC 'Y-m-d H:i:s') is within $seconds of now; null/empty never is. */
    private static function updatedWithinSeconds(?string $updated, int $seconds): bool
    {
        if ($updated === null || $updated === '') {
            return false;
        }
        return strtotime($updated . ' UTC') > time() - $seconds;
    }

    /** Poll path: the feed is trusted (HTTPS from youtube.com), so its entry XML is stored as-is. */
    private function processPoll(string $slug, int $channelId, string $currentTitle): void
    {
        $content = $this->api->fetchChannelFeed($slug);
        $array = $this->parseEntries($content, suppressWarnings: false);
        if ($array === null) {
            throw new \RuntimeException("Invalid feed XML for {$slug}");
        }

        // Fill the title from the feed only while it's still the slug placeholder (keeps healed/manual names).
        if ($currentTitle === $slug && !empty($array['author']['name'])) {
            $this->repo->updateChannelTitle($channelId, $array['author']['name']);
        }

        foreach ($array['entry'] ?? [] as $entry) {
            $href = $entry['link']['@attributes']['href'] ?? '';
            if ($href === '' || stripos($href, 'watch') === false) {
                continue;
            }
            $videoId = str_replace('yt:video:', '', self::stringField($entry, 'id'));
            if (!self::isValidVideoId($videoId)) {
                continue;
            }
            $published = self::stringField($entry, 'published');
            if ($published !== '' && strtotime($published) < time() - 3600 * 24 * 14) {
                $content = $this->parser->deleteEntry($videoId, $content);
                continue;
            }
            $this->savePollEntry($videoId, $channelId, $entry, $content, $published);
        }
    }

    /** @param array<string, mixed> $entry SimpleXML decodes an empty element to [], not '' — guard before a string cast. */
    private static function stringField(array $entry, string $key, string $default = ''): string
    {
        return isset($entry[$key]) && is_string($entry[$key]) ? $entry[$key] : $default;
    }

    /** Push path: body is untrusted, so content is always rebuilt via buildEntry (never stored raw); a key adds ownership verification. */
    private function processPush(string $slug, int $channelId, string $body): void
    {
        // A malformed hostile push is simply dropped (warnings suppressed), not logged.
        $array = $this->parseEntries($body, suppressWarnings: true);
        if ($array === null) {
            return;
        }
        foreach ($array['entry'] ?? [] as $entry) {
            $videoId = str_replace('yt:video:', '', self::stringField($entry, 'id'));
            if (!self::isValidVideoId($videoId)) {
                continue;
            }
            $title = self::stringField($entry, 'title', $videoId);
            $published = self::stringField($entry, 'published');
            $this->savePushEntry($slug, $channelId, $videoId, $title, $published);
        }
    }

    /**
     * Parse feed/push XML into a decoded array, normalising a single `<entry>` to a one-item list.
     * Poll passes false (throws upstream on null); push passes true to drop malformed bodies silently.
     *
     * @return array<string, mixed>|null null on parse failure
     */
    private function parseEntries(string $xml, bool $suppressWarnings): ?array
    {
        // LIBXML_NONET: never resolve external references while parsing (defence in depth; no NOENT).
        $parsed = $suppressWarnings
            ? @simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET)
            : simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        if ($parsed === false) {
            return null;
        }
        $array = json_decode(json_encode($parsed), true);
        // Single-entry feeds come back as an associative array; normalise to a list.
        if (isset($array['entry']['id'])) {
            $array['entry'] = [$array['entry']];
        }
        return $array;
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

        // Push already resolved viewability/duration above (the key also verified ownership).
        $this->persistEntry($channelId, $videoId, $title, $entryXml, gmdate('Y-m-d H:i:s', $ts), fn (): array => [$viewable, $duration]);
    }

    /** @param array<string, mixed> $entryData */
    private function savePollEntry(string $videoId, int $channelId, array $entryData, string $rawContent, string $rawPublished): void
    {
        $title = self::stringField($entryData, 'title', $videoId);
        $entryXml = $this->parser->findEntry($videoId, $rawContent);
        if ($rawPublished === '') {
            // Missing <published>: stamp as ingested now rather than drop a real video over it.
            $published = gmdate('Y-m-d H:i:s');
        } else {
            // Drop the +00:00 offset; treat the remainder as UTC.
            [$publishedNaive] = explode('+', str_replace('T', ' ', $rawPublished));
            $published = gmdate('Y-m-d H:i:s', strtotime($publishedNaive . ' UTC'));
        }

        // Enrich lazily: the poll feed re-lists every recent video, so only new ones should hit the API.
        $this->persistEntry($channelId, $videoId, $title, $entryXml, $published, function () use ($videoId): array {
            $info = $this->api->fetchVideoInfo($videoId);
            return [$info['viewable'], $info['duration']];
        });
    }

    /**
     * Shared save tail: update an existing row, else gate on viewability + Shorts before insert.
     * $enrich (run only for a new row) yields [viewable, duration], so poll updates skip the API.
     *
     * @param callable(): array{bool, ?string} $enrich
     */
    private function persistEntry(int $channelId, string $videoId, string $title, string $entryXml, string $published, callable $enrich): void
    {
        $video = $this->repo->findVideo($videoId);
        if ($video !== null) {
            $this->repo->updateVideo((int) $video['id'], $title, $entryXml);
            return;
        }
        [$viewable, $duration] = $enrich();
        if (!$viewable) {
            $this->appendAuditLog($videoId, $entryXml);
            return;
        }
        if ($this->detectShorts && $this->api->isShort($videoId)) {
            $this->appendAuditLog($videoId, $entryXml, 'short');
            return;
        }
        $this->repo->insertVideo(
            channelId: $channelId,
            slug: $videoId,
            title: $title,
            content: $entryXml,
            duration: $duration,
            published: $published,
        );
    }

    private static function isValidVideoId(string $videoId): bool
    {
        return preg_match('#^[A-Za-z0-9_-]{11}$#', $videoId) === 1;
    }

    private function appendAuditLog(string $videoId, string $entryXml, string $reason = 'not viewable'): void
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
            "{$now} - {$reason} ({$videoId})\n{$entryXml}\n\n",
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

    /** Ungated hub ping for the `publish` command; pingChannel() is the shouldPublish()-gated path. */
    public function publishNow(): bool
    {
        return $this->hub->publish();
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
