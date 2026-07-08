<?php

declare(strict_types=1);

namespace App\Tests;

use App\Hub;

/** Hub with only the network seam (post) stubbed, so the real subscribe/publish logic runs offline; FakeHub instead replaces them wholesale. */
final class RecordingHub extends Hub
{
    public ?string $lastUrl = null;
    /** @var array<string, scalar>|string|null */
    public array|string|null $lastFields = null;

    /** @var array{0: string|bool, 1: int, 2: ?string} Canned [result, status, error] returned by post(). */
    public array $response = [true, 204, null];

    public function __construct(string $subscribeUrl = '', string $publishUrl = '', string $feedUrl = '')
    {
        parent::__construct(
            subscribeUrl: $subscribeUrl,
            callbackBase: 'https://cb.example/',
            topicBase: 'https://topic.example/',
            publishUrl: $publishUrl,
            feedUrl: $feedUrl,
        );
    }

    protected function post(string $url, array|string $fields): array
    {
        $this->lastUrl = $url;
        $this->lastFields = $fields;
        return $this->response;
    }
}
