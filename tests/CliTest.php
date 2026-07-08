<?php

declare(strict_types=1);

namespace App\Tests;

use App\Cli;
use PHPUnit\Framework\TestCase;

/** The hourly cron gate is split into a pure decision (Cli::cronAction) so it can be tested without the clock. */
final class CliTest extends TestCase
{
    private const INGEST_HOURS = [9, 12, 15, 18];

    public function testSubscribesOnLeaseDayAndHour(): void
    {
        // Monday (ISO 1) at 05:00 — the default subscribe schedule.
        $this->assertSame('subscribe', Cli::cronAction(1, 5, 1, 5, self::INGEST_HOURS));
    }

    public function testIngestsOnAConfiguredIngestHour(): void
    {
        $this->assertSame('ingest', Cli::cronAction(3, 12, 1, 5, self::INGEST_HOURS));
    }

    public function testNoOpsOffSchedule(): void
    {
        $this->assertSame('none', Cli::cronAction(3, 8, 1, 5, self::INGEST_HOURS));
    }

    public function testSubscribeWinsWhenItCollidesWithAnIngestHour(): void
    {
        // dow/hour match subscribe and the hour is also an ingest hour: subscribe takes precedence.
        $this->assertSame('subscribe', Cli::cronAction(1, 12, 1, 12, self::INGEST_HOURS));
    }

    public function testSameHourOnANonSubscribeDayIngestsInstead(): void
    {
        $this->assertSame('ingest', Cli::cronAction(2, 5, 1, 5, [5, 9]));
    }
}
