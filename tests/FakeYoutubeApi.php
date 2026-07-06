<?php

declare(strict_types=1);

namespace App\Tests;

use App\YoutubeApi;

/** YoutubeApi with its one HTTP boundary stubbed, so the surrounding logic can be tested offline. */
final class FakeYoutubeApi extends YoutubeApi
{
    /** Body returned for channel-feed (poll) URLs. */
    public string $feedBody = '';

    /** @var array<string, string> videoId => raw videos.list JSON body */
    public array $videoJson = [];

    public ?string $lastUrl = null;

    protected function httpGet(string $url): string|false
    {
        $this->lastUrl = $url;
        if (str_contains($url, 'googleapis.com')) {
            preg_match('#[?&]id=([A-Za-z0-9_-]{11})#', $url, $m);
            // Unknown id => a valid 200 with no items (the API's "removed/unknown video" shape).
            return $this->videoJson[$m[1] ?? ''] ?? '{"items":[]}';
        }
        return $this->feedBody !== '' ? $this->feedBody : false;
    }

    /** Builds a one-item videos.list JSON body; omit a field to simulate its absence. */
    public static function videoInfoJson(
        string $channelId,
        string $title = 'API Title',
        string $publishedAt = '2024-06-01T00:00:00Z',
        ?string $duration = 'PT10M5S',
        string $liveBroadcastContent = 'none',
        bool $hasViewCount = true,
        array $tags = [],
    ): string {
        $snippet = compact('channelId', 'title', 'publishedAt', 'liveBroadcastContent');
        if ($tags !== []) {
            $snippet['tags'] = $tags;
        }
        $item = ['snippet' => $snippet];
        if ($duration !== null) {
            $item['contentDetails'] = ['duration' => $duration];
        }
        if ($hasViewCount) {
            $item['statistics'] = ['viewCount' => '100'];
        }
        return json_encode(['items' => [$item]], JSON_THROW_ON_ERROR);
    }
}
