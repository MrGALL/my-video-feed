<?php

declare(strict_types=1);

namespace App;

class YoutubeApi
{
    // Playlist form (UULF + UC suffix) per https://stackoverflow.com/a/76602819
    private const string FEED_URL = 'https://www.youtube.com/feeds/videos.xml?channel_id=%s';
    private const string VIDEO_INFO_URL = 'https://content-youtube.googleapis.com/youtube/v3/videos'
        . '?id=%s&part=contentDetails,statistics,snippet,liveStreamingDetails&key=%s';
    // ucbcb=1 skips the EU cookie-consent 302 so the probe sees the real 200/3xx.
    private const string SHORTS_URL = 'https://www.youtube.com/shorts/%s?ucbcb=1';

    /** @var list<string> Lowercased for case-insensitive matching. */
    private readonly array $excludeTags;

    /** @param list<string> $excludeTags */
    public function __construct(private readonly string $apiKey = '', array $excludeTags = [])
    {
        $this->excludeTags = array_map('mb_strtolower', $excludeTags);
    }

    public function hasKey(): bool
    {
        return $this->apiKey !== '';
    }

    public function fetchChannelFeed(string $slug): string
    {
        $url = sprintf(self::FEED_URL, $slug);
        $url = str_replace('channel_id=UC', 'playlist_id=UULF', $url);
        $body = $this->httpGet($url);
        if ($body === false) {
            throw new \RuntimeException("Failed to fetch YouTube feed for {$slug}");
        }
        return $body;
    }

    /** @return array{duration: ?string, viewable: bool, channelId: ?string, title: ?string, published: ?string} */
    public function fetchVideoInfo(string $videoId): array
    {
        if ($this->apiKey === '') {
            // No key: skip enrichment. Videos still ingest, minus duration and livestream/private detection.
            return ['duration' => null, 'viewable' => true, 'channelId' => null, 'title' => null, 'published' => null];
        }

        $info = $this->fetchVideoJson($videoId);

        $duration = null;
        $viewable = true;

        $rawDuration = $info['items'][0]['contentDetails']['duration'] ?? null;
        if (!empty($rawDuration)) {
            $duration = (new \DateInterval($rawDuration))->format('%H:%I:%S');
        }
        $hasViewCount = isset($info['items'][0]['statistics']['viewCount']);
        $broadcast = $info['items'][0]['snippet']['liveBroadcastContent'] ?? '';
        if (!$hasViewCount || $broadcast !== 'none' || $this->hasExcludedTag($info)) {
            $viewable = false;
        }
        // channelId/title/published are null when the response has no item (unknown/removed video).
        return [
            'duration' => $duration,
            'viewable' => $viewable,
            'channelId' => $info['items'][0]['snippet']['channelId'] ?? null,
            'title' => $info['items'][0]['snippet']['title'] ?? null,
            'published' => $info['items'][0]['snippet']['publishedAt'] ?? null,
        ];
    }

    /** @param array<string, mixed> $info */
    private function hasExcludedTag(array $info): bool
    {
        if ($this->excludeTags === []) {
            return false;
        }
        foreach ($info['items'][0]['snippet']['tags'] ?? [] as $tag) {
            if (in_array(mb_strtolower($tag), $this->excludeTags, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Raw decoded videos.list response. Requires an API key.
     *
     * @return array<string, mixed>
     */
    public function fetchVideoJson(string $videoId): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('YOUTUBE_API_KEY is required to fetch video info');
        }
        $url = sprintf(self::VIDEO_INFO_URL, $videoId, $this->apiKey);
        $body = $this->httpGet($url);
        if ($body === false) {
            throw new \RuntimeException("Failed to fetch video info for {$videoId}");
        }
        // No @-suppression on decode: a malformed response should fail loudly, not be silently swallowed.
        return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    }

    /** /shorts/{id}?ucbcb=1 answers 200 for a Short; a regular video 3xx-redirects to /watch. */
    public function isShort(string $videoId): bool
    {
        return $this->httpHead(sprintf(self::SHORTS_URL, $videoId)) === 200;
    }

    /** The one HTTP boundary; tests override this to serve canned bodies. */
    protected function httpGet(string $url): string|false
    {
        return Http::get($url);
    }

    /** HTTP status of a HEAD request, 0 on failure; second HTTP seam, tests override it. */
    protected function httpHead(string $url): int
    {
        return Http::head($url);
    }
}
