<?php

declare(strict_types=1);

namespace App;

/** PubSubHubbub client: subscribe to channel updates (inbound) and notify a hub of feed changes (outbound); each side enabled by its URL. */
final class Hub
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
        $ch = curl_init($this->subscribeUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'hub.callback' => $this->callbackBase . $channelSlug,
            'hub.mode' => 'subscribe',
            'hub.topic' => $this->topicBase . $channelSlug,
            'hub.verify' => 'async',
            'hub.lease_seconds' => $this->leaseSeconds,
            'hub.secret' => '',
            'hub.verify_token' => '',
        ]);
        $ok = curl_exec($ch);
        $err = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        curl_close($ch);
        if ($ok === false || $err !== null) {
            throw new \RuntimeException("subscribe error for {$channelSlug}: " . ($err ?? 'curl_exec returned false'));
        }
    }

    public function publish(): void
    {
        if ($this->publishUrl === '') {
            return;
        }
        $ch = curl_init($this->publishUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt(
            $ch,
            CURLOPT_POSTFIELDS,
            'hub.mode=publish&hub.url=' . urlencode($this->feedUrl),
        );
        $ok = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        curl_close($ch);
        if ($ok === false || $err !== null) {
            throw new \RuntimeException('publish error: ' . ($err ?? 'curl_exec returned false'));
        }
        if ($httpcode !== 204) {
            throw new \RuntimeException("publish error (HTTP {$httpcode})");
        }
    }
}
