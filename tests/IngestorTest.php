<?php

declare(strict_types=1);

namespace App\Tests;

use App\Db;
use App\FeedParser;
use App\Hub;
use App\Ingestor;
use App\Repository;
use App\YoutubeApi;
use PHPUnit\Framework\TestCase;

/**
 * Ingest tests. These run offline: keyless mode makes no API calls, the keyed path uses
 * FakeYoutubeApi (canned bodies), and FakeHub records instead of calling out — so both the
 * untrusted push handling and the trusted poll path are exercised end to end.
 */
final class IngestorTest extends TestCase
{
    private const SLUG = 'UC_x5XG1OV2P6uZZ5FSM9Ttw';

    /** @return array{Db, Repository, Ingestor, YoutubeApi, Hub} */
    private function makeSetup(?YoutubeApi $api = null, ?Hub $hub = null, string $auditPath = ''): array
    {
        $db = new Db('sqlite', 'sqlite::memory:');
        $db->runScript(file_get_contents(dirname(__DIR__) . '/db/schema.sqlite.sql'));
        $repo = new Repository($db);
        $repo->insertChannel(self::SLUG, self::SLUG);
        // Defaults: keyless (no network) + no-op Hub.
        $api ??= new YoutubeApi('', []);
        $hub ??= new Hub();
        $ingestor = new Ingestor($repo, $api, new FeedParser(), $hub, $auditPath, 'UTC');
        return [$db, $repo, $ingestor, $api, $hub];
    }

    private function contentOf(Db $db, string $slug): string
    {
        return (string) $db->fetchOne('SELECT content FROM myvideofeed_videos WHERE slug = ?', [$slug])['content'];
    }

    private function pushBody(string $videoId, string $title, string $published, string $extra = ''): string
    {
        return "<feed xmlns:yt=\"http://www.youtube.com/xml/schemas/2015\">\n"
            . "<entry>\n <id>yt:video:{$videoId}</id>\n <yt:videoId>{$videoId}</yt:videoId>\n"
            . " <title>{$title}</title>\n <published>{$published}</published>\n{$extra}</entry>\n</feed>";
    }

    public function testKeylessPushIngestsButRebuildsContentInsteadOfTrustingBody(): void
    {
        [$db, $repo, $ingestor] = $this->makeSetup();

        // A well-formed but hostile push: a rogue <content> blob that must NOT reach the feed.
        $rogue = " <content type=\"html\"><![CDATA[<script>alert(document.cookie)</script>]]></content>\n";
        $ingestor->processChannel(self::SLUG, $this->pushBody('HACK1234567', 'Totally Legit', gmdate('Y-m-d\TH:i:s\Z'), $rogue));

        $this->assertNotNull($repo->findVideo('HACK1234567'), 'keyless push should still ingest (works out of the box)');

        $content = $db->fetchOne('SELECT content FROM myvideofeed_videos WHERE slug = ?', ['HACK1234567'])['content'];
        $this->assertStringContainsString('HACK1234567/hqdefault.jpg', $content, 'content is rebuilt via buildEntry');
        $this->assertStringNotContainsString('<script>', $content, 'the rogue body content must never be stored');
        $this->assertStringNotContainsString('CDATA', $content);
    }

    public function testKeylessPushEscapesTitleAndDropsRawMarkup(): void
    {
        [$db, $repo, $ingestor] = $this->makeSetup();

        // Title carrying markup, XML-escaped so the push body stays well-formed.
        $ingestor->processChannel(self::SLUG, $this->pushBody('TITL1234567', '&lt;script&gt;x&lt;/script&gt;', gmdate('Y-m-d\TH:i:s\Z')));

        $content = $db->fetchOne('SELECT content FROM myvideofeed_videos WHERE slug = ?', ['TITL1234567'])['content'];
        $this->assertStringNotContainsString('<script>', $content);
        $this->assertNotFalse(simplexml_load_string('<f xmlns:media="urn:m">' . $content . '</f>'), 'stored entry stays well-formed');
    }

    public function testPushClampsFuturePublishedToNow(): void
    {
        [$db, $repo, $ingestor] = $this->makeSetup();

        $ingestor->processChannel(self::SLUG, $this->pushBody('FUTR1234567', 'Future', '2999-01-01T00:00:00Z'));

        $published = $db->fetchOne('SELECT published FROM myvideofeed_videos WHERE slug = ?', ['FUTR1234567'])['published'];
        $this->assertLessThanOrEqual(gmdate('Y-m-d H:i:s'), $published);
    }

    public function testPushSkipsMalformedVideoId(): void
    {
        [, $repo, $ingestor] = $this->makeSetup();

        // 'shortid' is not 11 chars, so the shape check drops it.
        $ingestor->processChannel(self::SLUG, $this->pushBody('shortid', 'Bad', '2024-06-01T00:00:00Z'));

        $this->assertNull($repo->findVideo('shortid'));
    }

    public function testMalformedPushIsDroppedSilently(): void
    {
        [, $repo, $ingestor] = $this->makeSetup();

        $ingestor->processChannel(self::SLUG, '<feed><entry><id>yt:video:BADXXXXXXXX</id><title>x</title></entry></feed');

        $this->assertNull($repo->findVideo('BADXXXXXXXX'));
    }

    public function testPushToUnknownChannelDoesNothing(): void
    {
        [, $repo, $ingestor] = $this->makeSetup();

        $this->expectException(\RuntimeException::class);
        $ingestor->processChannel('UCzzzzzzzzzzzzzzzzzzzzzz', $this->pushBody('OTHR1234567', 'x', '2024-06-01T00:00:00Z'));
    }

    // --- keyed push: the API verifies ownership and supplies the trusted fields ---

    public function testKeyedPushStoresApiValuesNotBodyValues(): void
    {
        $api = new FakeYoutubeApi('key');
        $api->videoJson['VID12345678'] = FakeYoutubeApi::videoInfoJson(
            channelId: self::SLUG,
            title: 'Real API Title',
            publishedAt: gmdate('Y-m-d\TH:i:s\Z'),
        );
        [$db, $repo, $ingestor] = $this->makeSetup($api);

        $ingestor->processChannel(self::SLUG, $this->pushBody('VID12345678', 'Body Spoof Title', '2024-01-01T00:00:00Z'));

        $row = $db->fetchOne('SELECT title, duration FROM myvideofeed_videos WHERE slug = ?', ['VID12345678']);
        $this->assertSame('Real API Title', $row['title'], 'API title overrides the body title');
        $this->assertSame('00:10:05', $row['duration'], 'duration comes from PT10M5S');
        $this->assertStringContainsString('VID12345678/hqdefault.jpg', $this->contentOf($db, 'VID12345678'));
        $this->assertStringNotContainsString('Body Spoof Title', $this->contentOf($db, 'VID12345678'));
    }

    public function testKeyedPushDropsVideoOwnedByAnotherChannel(): void
    {
        $api = new FakeYoutubeApi('key');
        $api->videoJson['VID12345678'] = FakeYoutubeApi::videoInfoJson(
            channelId: 'UCsomeoneelsesomeoneelse1',
            publishedAt: gmdate('Y-m-d\TH:i:s\Z'),
        );
        [, $repo, $ingestor] = $this->makeSetup($api);

        $ingestor->processChannel(self::SLUG, $this->pushBody('VID12345678', 'x', gmdate('Y-m-d\TH:i:s\Z')));

        $this->assertNull($repo->findVideo('VID12345678'), 'a forged push for another channel is rejected');
    }

    public function testKeyedPushDropsVideoUnknownToTheApi(): void
    {
        $api = new FakeYoutubeApi('key'); // no videoJson entry => empty items => channelId null
        [, $repo, $ingestor] = $this->makeSetup($api);

        $ingestor->processChannel(self::SLUG, $this->pushBody('UNKN1234567', 'x', gmdate('Y-m-d\TH:i:s\Z')));

        $this->assertNull($repo->findVideo('UNKN1234567'));
    }

    public function testKeyedPushSkipsNotViewableAndWritesAudit(): void
    {
        $auditPath = sys_get_temp_dir() . '/mvf_audit_' . uniqid() . '.log';
        $api = new FakeYoutubeApi('key');
        $api->videoJson['LIVE1234567'] = FakeYoutubeApi::videoInfoJson(
            channelId: self::SLUG,
            publishedAt: gmdate('Y-m-d\TH:i:s\Z'),
            liveBroadcastContent: 'live', // an ongoing livestream is not viewable
        );
        [, $repo, $ingestor] = $this->makeSetup($api, null, $auditPath);

        $ingestor->processChannel(self::SLUG, $this->pushBody('LIVE1234567', 'x', gmdate('Y-m-d\TH:i:s\Z')));

        $this->assertNull($repo->findVideo('LIVE1234567'));
        $this->assertStringContainsString('LIVE1234567', file_get_contents($auditPath));
        unlink($auditPath);
    }

    public function testKeyedPushUpdatesExistingVideoContent(): void
    {
        $api = new FakeYoutubeApi('key');
        $api->videoJson['UPDT1234567'] = FakeYoutubeApi::videoInfoJson(
            channelId: self::SLUG,
            title: 'Fresh Title',
            publishedAt: gmdate('Y-m-d\TH:i:s\Z'),
        );
        [$db, $repo, $ingestor] = $this->makeSetup($api);
        $repo->insertVideo((int) $repo->findChannel(self::SLUG)['id'], 'UPDT1234567', 'Stale', '<stale/>', null, gmdate('Y-m-d H:i:s'));

        $ingestor->processChannel(self::SLUG, $this->pushBody('UPDT1234567', 'body', gmdate('Y-m-d\TH:i:s\Z')));

        $this->assertSame('Fresh Title', $db->fetchOne('SELECT title FROM myvideofeed_videos WHERE slug = ?', ['UPDT1234567'])['title']);
        $this->assertStringNotContainsString('<stale/>', $this->contentOf($db, 'UPDT1234567'), 'content is rebuilt, not left stale');
    }

    // --- poll path: the fetched feed is trusted, so its entry XML is stored as-is ---

    private function pollFeed(string $videoId, string $author, string $published, string $href): string
    {
        return "<feed xmlns:yt=\"http://www.youtube.com/xml/schemas/2015\" xmlns:media=\"http://search.yahoo.com/mrss/\">\n"
            . " <author><name>{$author}</name></author>\n"
            . " <entry>\n  <id>yt:video:{$videoId}</id>\n  <title>Poll Video</title>\n"
            . "  <link rel=\"alternate\" href=\"{$href}\"/>\n  <published>{$published}</published>\n"
            . "  <media:group>\n   <media:thumbnail url=\"https://i1.ytimg.com/vi/{$videoId}/hqdefault.jpg\"/>\n  </media:group>\n"
            . " </entry>\n</feed>";
    }

    public function testPollStoresTrustedEntryAndHealsChannelTitle(): void
    {
        $api = new FakeYoutubeApi(); // keyless: no per-video API call on the poll path
        $api->feedBody = $this->pollFeed('POLL1234567', 'Real Channel Name', gmdate('Y-m-d\TH:i:s\Z'), 'https://www.youtube.com/watch?v=POLL1234567');
        [$db, $repo, $ingestor] = $this->makeSetup($api);

        $ingestor->processChannel(self::SLUG); // no push body => poll

        $this->assertNotNull($repo->findVideo('POLL1234567'));
        $this->assertStringContainsString('Poll Video', $this->contentOf($db, 'POLL1234567'), 'stored content is the trusted feed entry');
        $this->assertSame('Real Channel Name', $repo->findChannel(self::SLUG)['title'], 'title healed from the feed author');
    }

    public function testPollSkipsEntryOlderThanFourteenDays(): void
    {
        $api = new FakeYoutubeApi();
        $api->feedBody = $this->pollFeed('POLL1234567', 'Ch', gmdate('Y-m-d\TH:i:s\Z', time() - 20 * 86400), 'https://www.youtube.com/watch?v=POLL1234567');
        [, $repo, $ingestor] = $this->makeSetup($api);

        $ingestor->processChannel(self::SLUG);

        $this->assertNull($repo->findVideo('POLL1234567'));
    }

    public function testPollSkipsEntryWhoseHrefIsNotAWatchLink(): void
    {
        $api = new FakeYoutubeApi();
        $api->feedBody = $this->pollFeed('POLL1234567', 'Ch', gmdate('Y-m-d\TH:i:s\Z'), 'https://www.youtube.com/channel/UCabc');
        [, $repo, $ingestor] = $this->makeSetup($api);

        $ingestor->processChannel(self::SLUG);

        $this->assertNull($repo->findVideo('POLL1234567'));
    }

    // --- shouldPublish: the pure hub-ping decision ---

    public function testShouldPublishTrueForRecentNonBlacklistedVideo(): void
    {
        $videos = [['title' => 'Fresh', 'updated' => gmdate('Y-m-d H:i:s')]];
        $this->assertTrue(Ingestor::shouldPublish($videos, []));
    }

    public function testShouldPublishSkipsBlacklistedNewestThenChecksNext(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $videos = [['title' => 'spam ad', 'updated' => $now], ['title' => 'Good', 'updated' => $now]];
        $this->assertTrue(Ingestor::shouldPublish($videos, ['spam']));
    }

    public function testShouldPublishFalseWhenNewestNonBlacklistedIsStale(): void
    {
        $videos = [['title' => 'Good', 'updated' => gmdate('Y-m-d H:i:s', time() - 3600)]];
        $this->assertFalse(Ingestor::shouldPublish($videos, []));
    }

    // --- subscribeAll: one failing subscription must not block the rest ---

    public function testSubscribeAllContinuesAfterOneFailure(): void
    {
        $hub = new FakeHub();
        $hub->throwOn = 'UCbbbbbbbbbbbbbbbbbbbbbb';
        [, $repo, $ingestor] = $this->makeSetup(null, $hub);
        $repo->insertChannel('UCbbbbbbbbbbbbbbbbbbbbbb', 'B'); // this one throws
        $repo->insertChannel('UCcccccccccccccccccccccc', 'C');

        $ingestor->subscribeAll();

        $this->assertContains(self::SLUG, $hub->subscribed);
        $this->assertContains('UCcccccccccccccccccccccc', $hub->subscribed);
        $this->assertNotContains('UCbbbbbbbbbbbbbbbbbbbbbb', $hub->subscribed);
    }
}
