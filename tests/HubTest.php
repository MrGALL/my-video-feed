<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/** Exercises the real subscribe/publish logic (field building + HTTP-204 check) with the network seam stubbed. */
final class HubTest extends TestCase
{
    private const SLUG = 'UC_x5XG1OV2P6uZZ5FSM9Ttw';

    public function testSubscribePostsPubSubHubbubFields(): void
    {
        $hub = $this->recordingHub(subscribeUrl: 'https://hub.example/');

        $hub->subscribe(self::SLUG);

        $this->assertSame('https://hub.example/', $hub->lastUrl);
        $this->assertIsArray($hub->lastFields);
        $this->assertSame('subscribe', $hub->lastFields['hub.mode']);
        $this->assertSame('https://cb.example/' . self::SLUG, $hub->lastFields['hub.callback']);
        $this->assertSame('https://topic.example/' . self::SLUG, $hub->lastFields['hub.topic']);
    }

    public function testSubscribeThrowsOnTransportFailure(): void
    {
        $hub = $this->recordingHub(subscribeUrl: 'https://hub.example/');
        $hub->response = [false, 0, 'connection refused'];

        $this->expectException(\RuntimeException::class);
        $hub->subscribe(self::SLUG);
    }

    public function testSubscribeNoOpsWithoutAUrl(): void
    {
        $hub = $this->recordingHub(); // empty subscribeUrl => poll-only

        $hub->subscribe(self::SLUG);

        $this->assertNull($hub->lastUrl, 'no configured url => no outbound call');
    }

    public function testPublishAcceptsHttp204(): void
    {
        $hub = $this->recordingHub(publishUrl: 'https://hub.example/', feedUrl: 'https://me.example/channels');
        $hub->response = [true, 204, null];

        $hub->publish();

        $this->assertSame('hub.mode=publish&hub.url=https%3A%2F%2Fme.example%2Fchannels', $hub->lastFields);
    }

    public function testPublishThrowsOnNon204(): void
    {
        $hub = $this->recordingHub(publishUrl: 'https://hub.example/', feedUrl: 'https://me.example/channels');
        $hub->response = [true, 500, null];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500');
        $hub->publish();
    }

    private function recordingHub(string $subscribeUrl = '', string $publishUrl = '', string $feedUrl = ''): RecordingHub
    {
        return new RecordingHub($subscribeUrl, $publishUrl, $feedUrl);
    }
}
