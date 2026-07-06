<?php

declare(strict_types=1);

namespace App;

final class YoutubeApi
{
    // Playlist form (UULF + UC suffix) per https://stackoverflow.com/a/76602819
    private const string FEED_URL = 'https://www.youtube.com/feeds/videos.xml?channel_id=%s';
    private const string VIDEO_INFO_URL = 'https://content-youtube.googleapis.com/youtube/v3/videos'
        . '?id=%s&part=contentDetails,statistics,snippet&key=%s';

    public function __construct(private readonly string $apiKey = '') {}

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

        $url = sprintf(self::VIDEO_INFO_URL, $videoId, $this->apiKey);
        $body = @file_get_contents($url);
        if ($body === false) {
            throw new \RuntimeException("Failed to fetch video info for {$videoId}");
        }
        // No @-suppression: a malformed response should fail loudly, not be silently marked unviewable.
        $info = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        $duration = null;
        $viewable = true;

        $rawDuration = $info['items'][0]['contentDetails']['duration'] ?? null;
        if (!empty($rawDuration)) {
            $duration = (new \DateInterval($rawDuration))->format('%H:%I:%S');
        }
        $hasViewCount = isset($info['items'][0]['statistics']['viewCount']);
        $broadcast = $info['items'][0]['snippet']['liveBroadcastContent'] ?? '';
        if (!$hasViewCount || $broadcast !== 'none') {
            $viewable = false;
        }
        return ['duration' => $duration, 'viewable' => $viewable];
    }
}
