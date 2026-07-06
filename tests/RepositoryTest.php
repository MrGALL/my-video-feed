<?php

declare(strict_types=1);

namespace App\Tests;

use App\Db;
use App\Repository;
use PHPUnit\Framework\TestCase;

final class RepositoryTest extends TestCase
{
    private function makeRepo(): Repository
    {
        $db = new Db('sqlite', 'sqlite::memory:');
        $db->runScript(file_get_contents(dirname(__DIR__) . '/db/schema.sqlite.sql'));
        return new Repository($db);
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

    public function testClearOldVideoContentNullsOutStaleContentOnly(): void
    {
        $repo = $this->makeRepo();
        $repo->insertChannel('UC123', 'Title');
        $channel = $repo->findChannel('UC123');
        $channelId = (int) $channel['id'];

        $old = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $recent = gmdate('Y-m-d H:i:s', time() - 1 * 86400);
        $repo->insertVideo($channelId, 'vidold000002', 'Old', '<content-old/>', null, $old);
        $repo->insertVideo($channelId, 'vidnew000002', 'Recent', '<content-new/>', null, $recent);

        $repo->clearOldVideoContent(14);

        // Both were just inserted (updated = now), so both are within the 5-day window; only content differs.
        $bySlug = array_column($repo->recentVideosForChannel(), 'content', 'slug');
        $this->assertNull($bySlug['vidold000002']);
        $this->assertSame('<content-new/>', $bySlug['vidnew000002']);
    }
}
