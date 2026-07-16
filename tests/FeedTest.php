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

    public function testAggregateOmitsVideoWithNulledContentInsteadOfCrashing(): void
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
            timezone: 'UTC',
        );
        $this->seedVideo($repo);
        // Simulate a backfilled video whose content has already been pruned (Feed must not crash on it).
        $db->execute('UPDATE myvideofeed_videos SET content = NULL WHERE slug = ?', ['dQw4w9WgXcQ']);

        $atom = $feed->renderAggregate();

        $this->assertStringNotContainsString('Great Video', $atom);
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

    /** @param array<string, mixed> $opts */
    private function feedWith(Repository $repo, array $opts = []): Feed
    {
        return new Feed(
            repo: $repo,
            parser: new FeedParser(),
            minDurationSeconds: 30,
            feedTitle: 'T',
            feedUrl: 'https://example.com/channels',
            hubUrl: '',
            stripPatterns: $opts['stripPatterns'] ?? [],
            titlePrefix: $opts['titlePrefix'] ?? '[{channel}] {title}',
            maxTitleLength: $opts['maxTitleLength'] ?? 78,
            upgradeThumbnail: false,
            timezone: 'UTC',
        );
    }

    private function seedContentVideo(Repository $repo, string $title, string $channel = 'Cool Channel', ?string $duration = null): void
    {
        $repo->insertChannel('UCabc', $channel);
        $id = (int) $repo->findChannel('UCabc')['id'];
        $content = '<entry><title>placeholder</title>'
            . '<media:group><media:thumbnail url="https://i.ytimg.com/vi/x/hqdefault.jpg"/></media:group></entry>';
        $repo->insertVideo($id, 'dQw4w9WgXcQ', $title, $content, $duration, gmdate('Y-m-d H:i:s'));
    }

    public function testAggregateAppliesChannelAndTitleTemplateWithDurationSuffix(): void
    {
        $db = new Db('sqlite', 'sqlite::memory:');
        $db->runScript(file_get_contents(dirname(__DIR__) . '/db/schema.sqlite.sql'));
        $repo = new Repository($db);
        $this->seedContentVideo($repo, 'Great Video', duration: '00:10:00');

        $atom = $this->feedWith($repo)->renderAggregate();

        $this->assertStringContainsString('<title>[Cool Channel] Great Video (10m)</title>', $atom);
    }

    public function testAggregateStripsPatternsFromTitle(): void
    {
        $db = new Db('sqlite', 'sqlite::memory:');
        $db->runScript(file_get_contents(dirname(__DIR__) . '/db/schema.sqlite.sql'));
        $repo = new Repository($db);
        $this->seedContentVideo($repo, '[SPONSORED] Real Title');

        $atom = $this->feedWith($repo, ['titlePrefix' => '{title}', 'stripPatterns' => ['[SPONSORED] ']])->renderAggregate();

        $this->assertStringContainsString('<title>Real Title</title>', $atom);
        $this->assertStringNotContainsString('SPONSORED', $atom);
    }

    public function testAggregateTruncatesTitleToMaxLength(): void
    {
        $db = new Db('sqlite', 'sqlite::memory:');
        $db->runScript(file_get_contents(dirname(__DIR__) . '/db/schema.sqlite.sql'));
        $repo = new Repository($db);
        $this->seedContentVideo($repo, str_repeat('A', 30));

        $atom = $this->feedWith($repo, ['titlePrefix' => '{title}', 'maxTitleLength' => 20])->renderAggregate();

        $this->assertStringContainsString('<title>' . str_repeat('A', 20) . '…</title>', $atom);
    }

    public function testAggregateKeepsDollarBackreferenceCharactersLiterally(): void
    {
        $db = new Db('sqlite', 'sqlite::memory:');
        $db->runScript(file_get_contents(dirname(__DIR__) . '/db/schema.sqlite.sql'));
        $repo = new Repository($db);
        // Without the addcslashes guard, '$5' would be read as a preg_replace backreference and vanish.
        $this->seedContentVideo($repo, 'Cost $5 for a \\o/');

        $atom = $this->feedWith($repo, ['titlePrefix' => '{title}'])->renderAggregate();

        $this->assertStringContainsString('Cost $5 for a \\o/', $atom);
    }
}
