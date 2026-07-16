<?php

declare(strict_types=1);

namespace App\Tests;

use App\Db;
use App\Repository;
use PHPUnit\Framework\TestCase;

final class RepositoryTest extends TestCase
{
    private Db $db;

    private function makeRepo(): Repository
    {
        $this->db = new Db('sqlite', 'sqlite::memory:');
        $this->db->runScript(file_get_contents(dirname(__DIR__) . '/db/schema.sqlite.sql'));
        return new Repository($this->db);
    }

    private function setUpdated(string $slug, string $updated): void
    {
        $this->db->execute('UPDATE myvideofeed_videos SET updated = ? WHERE slug = ?', [$updated, $slug]);
    }

    public function testInsertChannelIgnoresDuplicateSlug(): void
    {
        $repo = $this->makeRepo();
        $repo->insertChannel('UC123', 'Title A');
        $repo->insertChannel('UC123', 'Title B');

        $this->assertCount(1, $repo->allChannels());
        $this->assertSame('Title A', $repo->allChannels()[0]['title']);
    }

    public function testFindChannelExposesTitleForPlaceholderCheck(): void
    {
        $repo = $this->makeRepo();
        $repo->insertChannel('UC123', 'UC123');

        // Ingestor::processPoll relies on this to fill only the slug placeholder.
        $this->assertSame('UC123', $repo->findChannel('UC123')['title']);
    }

    public function testInsertVideoIgnoresDuplicateSlug(): void
    {
        $repo = $this->makeRepo();
        $repo->insertChannel('UC123', 'Title');
        $channel = $repo->findChannel('UC123');

        $repo->insertVideo((int) $channel['id'], 'vid00000001', 'First', '<entry/>', null, '2024-01-01 00:00:00');
        $repo->insertVideo((int) $channel['id'], 'vid00000001', 'Duplicate', '<entry/>', null, '2024-01-02 00:00:00');

        $video = $repo->findVideo('vid00000001');
        $this->assertNotNull($video);
    }

    public function testRefreshChannelPublishedTimesUsesCorrelatedMax(): void
    {
        $repo = $this->makeRepo();
        $repo->insertChannel('UC123', 'Title');
        $channel = $repo->findChannel('UC123');
        $channelId = (int) $channel['id'];

        $repo->insertVideo($channelId, 'vidold000001', 'Old', '<entry/>', null, '2024-01-01 00:00:00');
        $repo->insertVideo($channelId, 'vidnew000001', 'New', '<entry/>', null, '2024-06-01 00:00:00');
        $repo->refreshChannelPublishedTimes();

        $channels = $repo->allChannels();
        $this->assertSame('2024-06-01 00:00:00', $channels[0]['published']);
    }

    public function testActiveChannelsToProcessIncludesNeverProcessedChannels(): void
    {
        $repo = $this->makeRepo();
        $repo->insertChannel('UC_NEW', 'Never processed');
        $repo->insertChannel('UC_FRESH', 'Just touched');
        $repo->touchChannel('UC_FRESH');

        $due = array_column($repo->activeChannelsToProcess(), 'slug');

        $this->assertContains('UC_NEW', $due);
        $this->assertNotContains('UC_FRESH', $due);
    }

    public function testUpdateChannelTitleOverwritesPlaceholder(): void
    {
        $repo = $this->makeRepo();
        $repo->insertChannel('UC123', 'UC123');
        $channel = $repo->findChannel('UC123');

        $repo->updateChannelTitle((int) $channel['id'], 'Real Channel Name');

        $this->assertSame('Real Channel Name', $repo->allChannels()[0]['title']);
    }

    public function testBlacklistTermsRoundTrip(): void
    {
        $repo = $this->makeRepo();
        $repo->addBlacklistTerm('spam');
        $repo->addBlacklistTerm('unboxing');

        $this->assertSame(['spam', 'unboxing'], $repo->blacklistTerms());
    }

    public function testClearOldVideoContentKeepsContentWhenOnlyPublishedIsStale(): void
    {
        // Backfilled video: published is already old but updated is fresh (ingested on first subscribe).
        $repo = $this->makeRepo();
        $repo->insertChannel('UC123', 'Title');
        $channelId = (int) $repo->findChannel('UC123')['id'];

        $old = gmdate('Y-m-d H:i:s', time() - 15 * 86400);
        $repo->insertVideo($channelId, 'vidold000002', 'Old', '<content-old/>', null, $old);

        $repo->clearOldVideoContent(14);

        $bySlug = array_column($repo->recentVideosForChannel(), 'content', 'slug');
        $this->assertSame('<content-old/>', $bySlug['vidold000002']);
    }

    public function testClearOldVideoContentNullsOutContentWhenBothColumnsAreStale(): void
    {
        $repo = $this->makeRepo();
        $repo->insertChannel('UC123', 'Title');
        $channelId = (int) $repo->findChannel('UC123')['id'];

        $old = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $repo->insertVideo($channelId, 'vidold000002', 'Old', '<content-old/>', null, $old);
        $this->setUpdated('vidold000002', $old);

        $repo->clearOldVideoContent(14);

        $row = $this->db->fetchOne('SELECT content FROM myvideofeed_videos WHERE slug = ?', ['vidold000002']);
        $this->assertNull($row['content']);
    }

    public function testClearOldVideoContentKeepsContentWhenBothColumnsAreRecent(): void
    {
        $repo = $this->makeRepo();
        $repo->insertChannel('UC123', 'Title');
        $channelId = (int) $repo->findChannel('UC123')['id'];

        $recent = gmdate('Y-m-d H:i:s', time() - 1 * 86400);
        $repo->insertVideo($channelId, 'vidnew000002', 'Recent', '<content-new/>', null, $recent);

        $repo->clearOldVideoContent(14);

        $bySlug = array_column($repo->recentVideosForChannel(), 'content', 'slug');
        $this->assertSame('<content-new/>', $bySlug['vidnew000002']);
    }

    public function testRecentVideosForChannelSkipsNullContentRow(): void
    {
        $repo = $this->makeRepo();
        $repo->insertChannel('UC123', 'Title');
        $channelId = (int) $repo->findChannel('UC123')['id'];

        $recent = gmdate('Y-m-d H:i:s', time() - 1 * 86400);
        $repo->insertVideo($channelId, 'vidnulled0002', 'Nulled', '<content/>', null, $recent);
        $this->db->execute('UPDATE myvideofeed_videos SET content = NULL WHERE slug = ?', ['vidnulled0002']);

        $slugs = array_column($repo->recentVideosForChannel(), 'slug');
        $this->assertNotContains('vidnulled0002', $slugs);
    }

    public function testRecentVideosForChannelOrdersByUpdatedNotPublished(): void
    {
        // A newly subscribed channel's backfilled video sorts above an older ingest, regardless of publish date.
        $repo = $this->makeRepo();
        $repo->insertChannel('UC123', 'Title');
        $channelId = (int) $repo->findChannel('UC123')['id'];
        $publishedOld = gmdate('Y-m-d H:i:s', time() - 10 * 86400);
        $publishedRecent = gmdate('Y-m-d H:i:s', time() - 1 * 86400);
        $ingestedFirst = gmdate('Y-m-d H:i:s', time() - 2 * 86400);
        $ingestedSecond = $publishedRecent;

        $repo->insertVideo($channelId, 'vidpubold001', 'Old', '<a/>', null, $publishedOld);
        $this->setUpdated('vidpubold001', $ingestedFirst);
        $repo->insertVideo($channelId, 'vidpubnew001', 'New', '<b/>', null, $publishedRecent);
        $this->setUpdated('vidpubnew001', $ingestedSecond);

        $slugs = array_column($repo->recentVideosForChannel(), 'slug');

        $this->assertSame(['vidpubnew001', 'vidpubold001'], $slugs);
    }

    public function testRecentVideosForChannelTieBreaksSameUpdatedSecondByPublished(): void
    {
        // A backfill batch shares one `updated` second; within that tie, newest-published-first applies.
        $repo = $this->makeRepo();
        $repo->insertChannel('UC123', 'Title');
        $channelId = (int) $repo->findChannel('UC123')['id'];
        $sameIngest = gmdate('Y-m-d H:i:s', time() - 1 * 86400);
        $olderPublished = gmdate('Y-m-d H:i:s', time() - 10 * 86400);
        $newerPublished = gmdate('Y-m-d H:i:s', time() - 5 * 86400);

        $repo->insertVideo($channelId, 'vidbackfill01', 'Older upload', '<a/>', null, $olderPublished);
        $this->setUpdated('vidbackfill01', $sameIngest);
        $repo->insertVideo($channelId, 'vidbackfill02', 'Newer upload', '<b/>', null, $newerPublished);
        $this->setUpdated('vidbackfill02', $sameIngest);

        $slugs = array_column($repo->recentVideosForChannel(), 'slug');

        $this->assertSame(['vidbackfill02', 'vidbackfill01'], $slugs);
    }
}
