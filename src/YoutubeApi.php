<?php

declare(strict_types=1);

namespace App;

final class YoutubeApi
{
    // Playlist form (UULF + UC suffix) per https://stackoverflow.com/a/76602819
    private const string FEED_URL = 'https://www.youtube.com/feeds/videos.xml?channel_id=%s';
    private const string VIDEO_INFO_URL = 'https://content-youtube.googleapis.com/youtube/v3/videos'
        . '?id=%s&part=contentDetails,statistics,snippet,liveStreamingDetails&key=%s';

    /** @var list<string> Lowercased for case-insensitive matching. */
    private readonly array $excludeTags;

    /** @param list<string> $excludeTags */
    public function __construct(private readonly string $apiKey = '', array $excludeTags = [])
    {
        $this->excludeTags = array_map('mb_strtolower', $excludeTags);
    }

    public function fetchChannelFeed(string $slug): string
    {
        $url = sprintf(self::FEED_URL, $slug);
        $url = str_replace('channel_id=UC', 'playlist_id=UULF', $url);
        $body = @file_get_contents($url);
        if ($body === false) {
            throw new \RuntimeException("Failed to fetch YouTube feed for {$slug}");
        }
        return $body;
    }

    /** @return array{duration: ?string, viewable: bool} */
    public function fetchVideoInfo(string $videoId): array
    {
        if ($this->apiKey === '') {
            // No key: skip enrichment. Videos still ingest, minus duration and livestream/private detection.
            return ['duration' => null, 'viewable' => true];
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
        return ['duration' => $duration, 'viewable' => $viewable];
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
        $body = @file_get_contents($url);
        if ($body === false) {
            throw new \RuntimeException("Failed to fetch video info for {$videoId}");
        }
        // No @-suppression: a malformed response should fail loudly, not be silently swallowed.
        return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    }
}
