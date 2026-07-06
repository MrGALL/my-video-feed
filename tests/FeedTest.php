<?php

declare(strict_types=1);

namespace App\Tests;

use App\Db;
use App\Feed;
use App\FeedParser;
use App\Repository;
use PHPUnit\Framework\TestCase;

final class FeedTest extends TestCase
{
    /** @return array{Repository, Feed} */
    private function makeFeed(bool $upgradeThumbnail = true): array
    {
        $db = new Db('sqlite', 'sqlite::memory:');
        $db->runScript(file_get_contents(dirname(__DIR__) . '/db/schema.sqlite.sql'));
        $repo = new Repository($db);
        $feed = new Feed(
            repo: $repo,
            parser: new FeedParser(),
            minDurationSeconds: 30,
            feedTitle: 'My Videos',
            feedUrl: 'https://example.com/channels',
            hubUrl: '',
            stripPatterns: [],
            upgradeThumbnail: $upgradeThumbnail,
            timezone: 'UTC',
        );
        return [$repo, $feed];
    }

    private function seedVideo(Repository $repo): void
    {
        $repo->insertChannel('UCabc', 'Cool Channel');
        $channel = $repo->findChannel('UCabc');
        $repo->insertVideo((int) $channel['id'], 'dQw4w9WgXcQ', 'Great Video', '<entry/>', '00:10:00', gmdate('Y-m-d H:i:s'));
    }

    public function testRenderHtmlShowsVideoCards(): void
    {
        [$repo, $feed] = $this->makeFeed();
        $this->seedVideo($repo);

        $html = $feed->renderHtml();

        $this->assertStringContainsString('<!doctype html>', $html);
        $this->assertStringContainsString('<style>', $html);
        $this->assertStringContainsString('Great Video', $html);
        $this->assertStringContainsString('Cool Channel', $html);
        // Default upgrade_thumbnail=true -> same maxres2 quality as the Atom feed.
        $this->assertStringContainsString('i.ytimg.com/vi/dQw4w9WgXcQ/maxres2.jpg', $html);
        $this->assertStringContainsString('watch?v=dQw4w9WgXcQ', $html);
    }

    public function testRenderHtmlUsesHqDefaultWhenUpgradeDisabled(): void
    {
        [$repo, $feed] = $this->makeFeed(upgradeThumbnail: false);
        $this->seedVideo($repo);

        $html = $feed->renderHtml();
        $this->assertStringContainsString('i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $html);
        $this->assertStringNotContainsString('maxres2.jpg', $html);
    }

    public function testAggregateEscapesThumbnailUrlToPreventInjection(): void
    {
        [$repo, $feed] = $this->makeFeed(upgradeThumbnail: false);
        $repo->insertChannel('UCabc', 'Cool Channel');
        $channel = $repo->findChannel('UCabc');
        // A thumbnail URL crafted to break out of the src='' attribute the feed builds.
        $content = "<entry><title>Vid</title>"
            . "<media:group><media:thumbnail url=\"x' onerror='alert(1)\" /></media:group>"
            . "</entry>";
        $repo->insertVideo((int) $channel['id'], 'dQw4w9WgXcQ', 'Vid', $content, '00:10:00', gmdate('Y-m-d H:i:s'));

        $atom = $feed->renderAggregate();

        $this->assertStringContainsString('&#039; onerror=&#039;', $atom);
        $this->assertStringNotContainsString("src='x' onerror='", $atom);
    }

    public function testRenderHtmlEmptyState(): void
    {
        [, $feed] = $this->makeFeed();

        $this->assertStringContainsString('No recent videos', $feed->renderHtml());
    }

    public function testExcludesTitleMatchingBlacklistTermCaseInsensitively(): void
    {
        $video = ['title' => 'Some SPAM Video', 'duration' => '00:10:00'];

        $this->assertTrue(Feed::isExcluded($video, ['spam'], 30));
    }

    public function testIncludesTitleNotMatchingBlacklist(): void
    {
        $video = ['title' => 'Regular Video', 'duration' => '00:10:00'];

        $this->assertFalse(Feed::isExcluded($video, ['spam'], 30));
    }

    public function testExcludesVideoAtOrUnderMinDuration(): void
    {
        $video = ['title' => 'Short', 'duration' => '00:00:25'];

        $this->assertTrue(Feed::isExcluded($video, [], 30));
    }

    public function testIncludesVideoOverMinDuration(): void
    {
        $video = ['title' => 'Long enough', 'duration' => '00:00:35'];

        $this->assertFalse(Feed::isExcluded($video, [], 30));
    }

    public function testIncludesVideoWithNoDurationInfo(): void
    {
        $video = ['title' => 'Unknown duration', 'duration' => null];

        $this->assertFalse(Feed::isExcluded($video, [], 30));
    }

    public function testMinutesParsesHoursAndMinutes(): void
    {
        $this->assertSame(90, Feed::minutes('01:30:00'));
        $this->assertSame(5, Feed::minutes('00:05:59'));
    }
}
