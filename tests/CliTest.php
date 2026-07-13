<?php

declare(strict_types=1);

namespace App\Tests;

use App\Cli;
use App\Db;
use App\FeedParser;
use App\Ingestor;
use App\Repository;
use PHPUnit\Framework\TestCase;

/** The hourly cron gate is split into a pure decision (Cli::cronAction) so it can be tested without the clock. */
final class CliTest extends TestCase
{
    private const INGEST_HOURS = [9, 12, 15, 18];
    private const SLUG = 'UC_x5XG1OV2P6uZZ5FSM9Ttw';

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

    // --- channel:add: adding a channel should ingest it immediately, not wait for the next cron ---

    /** @return array{Cli, Repository, FakeYoutubeApi} */
    private function makeCli(?FakeYoutubeApi $api = null): array
    {
        $db = new Db('sqlite', 'sqlite::memory:');
        $db->runScript(file_get_contents(dirname(__DIR__) . '/db/schema.sqlite.sql'));
        $repo = new Repository($db);
        $api ??= new FakeYoutubeApi();
        $ingestor = new Ingestor($repo, $api, new FeedParser(), new FakeHub(), '', 'UTC', false);
        $cli = new Cli($db, $repo, $ingestor, $api, 'UTC', self::INGEST_HOURS, 1, 5);
        return [$cli, $repo, $api];
    }

    private function pollFeed(string $videoId, string $href): string
    {
        return "<feed xmlns:yt=\"http://www.youtube.com/xml/schemas/2015\" xmlns:media=\"http://search.yahoo.com/mrss/\">\n"
            . " <author><name>Ch</name></author>\n"
            . " <entry>\n  <id>yt:video:{$videoId}</id>\n  <title>Poll Video</title>\n"
            . "  <link rel=\"alternate\" href=\"{$href}\"/>\n  <published>" . gmdate('Y-m-d\TH:i:s\Z') . "</published>\n"
            . "  <media:group>\n   <media:thumbnail url=\"https://i1.ytimg.com/vi/{$videoId}/hqdefault.jpg\"/>\n  </media:group>\n"
            . " </entry>\n</feed>";
    }

    public function testChannelAddImmediatelyIngestsTheNewChannel(): void
    {
        $api = new FakeYoutubeApi();
        $api->feedBody = $this->pollFeed('POLL1234567', 'https://www.youtube.com/watch?v=POLL1234567');
        [$cli, $repo] = $this->makeCli($api);

        ob_start();
        $exit = $cli->run(['bin/myvideofeed', 'channel:add', self::SLUG]);
        ob_end_clean();

        $this->assertSame(0, $exit);
        $this->assertNotNull($repo->findVideo('POLL1234567'), 'the new channel should be polled without waiting for cron/ingest');
        $this->assertNotNull($repo->findChannel(self::SLUG)['updated'], 'processing should touch the channel');
    }

    public function testChannelAddStillSucceedsWhenInitialProcessingFails(): void
    {
        $api = new FakeYoutubeApi(); // feedBody left empty => YoutubeApi::fetchChannelFeed throws
        [$cli, $repo] = $this->makeCli($api);

        ob_start();
        $exit = $cli->run(['bin/myvideofeed', 'channel:add', self::SLUG]);
        ob_end_clean();

        $this->assertSame(0, $exit, 'the channel is added even if the immediate ingest fails');
        $this->assertNotNull($repo->findChannel(self::SLUG), 'the channel row still exists');
    }
}
