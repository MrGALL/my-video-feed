<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/** Uses FakeYoutubeApi to stub the HTTP boundary and exercise the response mapping. */
final class YoutubeApiTest extends TestCase
{
    public function testFetchVideoInfoMapsAllFields(): void
    {
        $api = new FakeYoutubeApi('key');
        $api->videoJson['VID12345678'] = FakeYoutubeApi::videoInfoJson(
            channelId: 'UCchannelchannelchannel1',
            title: 'A Title',
            publishedAt: '2024-06-01T12:00:00Z',
            duration: 'PT1H2M3S',
        );

        $info = $api->fetchVideoInfo('VID12345678');

        $this->assertSame('01:02:03', $info['duration']);
        $this->assertTrue($info['viewable']);
        $this->assertSame('UCchannelchannelchannel1', $info['channelId']);
        $this->assertSame('A Title', $info['title']);
        $this->assertSame('2024-06-01T12:00:00Z', $info['published']);
    }

    public function testNotViewableWhenLiveBroadcast(): void
    {
        $api = new FakeYoutubeApi('key');
        $api->videoJson['VID12345678'] = FakeYoutubeApi::videoInfoJson(channelId: 'UCx', liveBroadcastContent: 'live');

        $this->assertFalse($api->fetchVideoInfo('VID12345678')['viewable']);
    }

    public function testNotViewableWhenNoViewCount(): void
    {
        $api = new FakeYoutubeApi('key');
        $api->videoJson['VID12345678'] = FakeYoutubeApi::videoInfoJson(channelId: 'UCx', hasViewCount: false);

        $this->assertFalse($api->fetchVideoInfo('VID12345678')['viewable']);
    }

    public function testNotViewableWhenTagIsExcludedCaseInsensitively(): void
    {
        $api = new FakeYoutubeApi('key', ['blocked']);
        $api->videoJson['VID12345678'] = FakeYoutubeApi::videoInfoJson(channelId: 'UCx', tags: ['Fine', 'BLOCKED']);

        $this->assertFalse($api->fetchVideoInfo('VID12345678')['viewable']);
    }

    public function testKeylessReturnsDefaultsWithoutAnyHttpCall(): void
    {
        $api = new FakeYoutubeApi(); // no key

        $info = $api->fetchVideoInfo('VID12345678');

        $this->assertNull($info['published']);
        $this->assertNull($info['channelId']);
        $this->assertTrue($info['viewable']);
        $this->assertNull($api->lastUrl, 'keyless must not touch the network');
    }

    public function testIsShortTrueOnlyForA200Probe(): void
    {
        $api = new FakeYoutubeApi();
        $api->shortStatus = ['SHRT1234567' => 200, 'NORM1234567' => 303, 'GONE1234567' => 0];

        $this->assertTrue($api->isShort('SHRT1234567'), '200 => a Short');
        $this->assertFalse($api->isShort('NORM1234567'), '3xx redirect => a regular video');
        $this->assertFalse($api->isShort('GONE1234567'), 'network error/timeout => not treated as a Short');
    }

    public function testFetchChannelFeedSwapsChannelIdForPlaylistId(): void
    {
        $api = new FakeYoutubeApi();
        $api->feedBody = '<feed/>';

        $api->fetchChannelFeed('UC_x5XG1OV2P6uZZ5FSM9Ttw');

        $this->assertStringContainsString('playlist_id=UULF', (string) $api->lastUrl);
        $this->assertStringNotContainsString('channel_id=UC', (string) $api->lastUrl);
    }
}
