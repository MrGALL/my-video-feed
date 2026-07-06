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

    public function subscribe(string $channelSlug): void
    {
        if ($channelSlug === $this->throwOn) {
            throw new \RuntimeException("subscribe failed for {$channelSlug}");
        }
        $this->subscribed[] = $channelSlug;
    }

    public function publish(): void
    {
        $this->published++;
    }
}
