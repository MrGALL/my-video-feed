<?php

declare(strict_types=1);

namespace App;

/** PubSubHubbub client: subscribe to channel updates (inbound) and notify a hub of feed changes (outbound); each side enabled by its URL. */
class Hub
{
    public function __construct(
        private readonly string $subscribeUrl = '',
        private readonly string $callbackBase = '',
        private readonly string $topicBase = '',
        private readonly int $leaseSeconds = (3600 * 168) - 1,
        private readonly string $publishUrl = '',
        private readonly string $feedUrl = '',
    ) {}

    public function subscribe(string $channelSlug): void
    {
        if ($this->subscribeUrl === '') {
            return;
        }
        [$ok, , $err] = $this->post($this->subscribeUrl, [
            'hub.callback' => $this->callbackBase . $channelSlug,
            'hub.mode' => 'subscribe',
            'hub.topic' => $this->topicBase . $channelSlug,
            'hub.verify' => 'async',
            'hub.lease_seconds' => $this->leaseSeconds,
            'hub.secret' => '',
            'hub.verify_token' => '',
        ]);
        if ($ok === false || $err !== null) {
            throw new \RuntimeException("subscribe error for {$channelSlug}: " . ($err ?? 'curl_exec returned false'));
        }
    }

    public function publish(): void
    {
        if ($this->publishUrl === '') {
            return;
        }
        [$ok, $httpcode, $err] = $this->post($this->publishUrl, 'hub.mode=publish&hub.url=' . urlencode($this->feedUrl));
        if ($ok === false || $err !== null) {
            throw new \RuntimeException('publish error: ' . ($err ?? 'curl_exec returned false'));
        }
        if ($httpcode !== 204) {
            throw new \RuntimeException("publish error (HTTP {$httpcode})");
        }
    }

    /**
     * The one network boundary; tests override this to record calls and return canned responses.
     *
     * @param array<string, scalar>|string $fields
     * @return array{0: string|bool, 1: int, 2: ?string} [curl result, HTTP status, error or null]
     */
    protected function post(string $url, array|string $fields): array
    {
        return Http::post($url, $fields);
    }
}
