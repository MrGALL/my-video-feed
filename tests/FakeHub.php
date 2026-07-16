<?php

declare(strict_types=1);

namespace App\Tests;

use App\Hub;

/** Hub with the network calls replaced by recording, so subscribe/publish loops can be tested offline. */
final class FakeHub extends Hub
{
    /** @var list<string> */
    public array $subscribed = [];
    public int $published = 0;

    /** Slug for which subscribe() should throw, to test loop resilience. */
    public ?string $throwOn = null;

    /** publish() returns this: false simulates an unconfigured publisher.url. */
    public bool $publishEnabled = true;

    /** When true, publish() throws instead of succeeding. */
    public bool $throwOnPublish = false;

    public function subscribe(string $channelSlug): void
    {
        if ($channelSlug === $this->throwOn) {
            throw new \RuntimeException("subscribe failed for {$channelSlug}");
        }
        $this->subscribed[] = $channelSlug;
    }

    public function publish(): bool
    {
        if ($this->throwOnPublish) {
            throw new \RuntimeException('publish failed');
        }
        if (!$this->publishEnabled) {
            return false;
        }
        $this->published++;
        return true;
    }
}
