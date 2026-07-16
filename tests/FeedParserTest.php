<?php

declare(strict_types=1);

namespace App\Tests;

use App\FeedParser;
use PHPUnit\Framework\TestCase;

final class FeedParserTest extends TestCase
{
    public function testFindEntryStripsDescriptionAndCommunityNodes(): void
    {
        // The stripping regex requires exact 3-space indentation before <media:description>.
        $content = "<feed>\n<entry>\n <id>yt:video:abc12345678</id>\n <title>Test</title>\n"
            . "   <media:description>long description</media:description>\n</entry>\n</feed>";

        $entry = (new FeedParser())->findEntry('abc12345678', $content);

        $this->assertStringContainsString('<title>Test</title>', $entry);
        $this->assertStringNotContainsString('media:description', $entry);
    }

    public function testDeleteEntryRemovesMatchingEntryOnly(): void
    {
        $content = "<feed>\n <entry>\n  <id>yt:video:keepme0001</id>\n  <title>Keep</title>\n </entry>\n"
            . " <entry>\n  <id>yt:video:dropme0001</id>\n  <title>Drop</title>\n </entry>\n</feed>";

        $result = (new FeedParser())->deleteEntry('dropme0001', $content);

        $this->assertStringContainsString('keepme0001', $result);
        $this->assertStringNotContainsString('dropme0001', $result);
    }

    public function testRenderAtomFeedIncludesHubLinkWhenConfigured(): void
    {
        $xml = (new FeedParser())->renderAtomFeed(
            '<entry/>',
            '2024-01-02T00:00:00+00:00',
            '2024-01-01T00:00:00+00:00',
            'My Feed',
            'https://example.com/channels',
            'https://hub.example.com/',
        );

        $this->assertStringContainsString('<title>My Feed</title>', $xml);
        $this->assertStringContainsString('rel="hub" href="https://hub.example.com/"', $xml);
        $this->assertStringContainsString('<updated>2024-01-02T00:00:00+00:00</updated>', $xml);
        $this->assertStringContainsString('<published>2024-01-01T00:00:00+00:00</published>', $xml);
    }

    public function testRenderAtomFeedUsesSameUrlForIdAndSelfLink(): void
    {
        $xml = (new FeedParser())->renderAtomFeed(
            '<entry/>',
            null,
            null,
            'My Feed',
            'https://example.com/channels',
            '',
        );

        $this->assertStringContainsString('<id>https://example.com/channels</id>', $xml);
        $this->assertStringContainsString('rel="self" href="https://example.com/channels"', $xml);
        $this->assertStringNotContainsString('rel="hub"', $xml);
    }

    public function testBuildEntryEmbedsVideoIdAndThumbnail(): void
    {
        $entry = (new FeedParser())->buildEntry('abc12345678', 'Test Video', '2024-01-01T00:00:00Z');

        $this->assertStringContainsString('<id>yt:video:abc12345678</id>', $entry);
        $this->assertStringContainsString('abc12345678/hqdefault.jpg', $entry);
        $this->assertStringContainsString('<published>2024-01-01T00:00:00Z</published>', $entry);
        $this->assertStringContainsString('<media:title>Test Video</media:title>', $entry);
    }

    public function testBuildEntryEscapesTitleSoPushBodiesCannotInjectMarkup(): void
    {
        // A rebuilt entry never carries raw markup: even a hostile "title" is XML-escaped.
        $entry = (new FeedParser())->buildEntry('abc12345678', '</title><script>alert(1)</script>', '2024-01-01T00:00:00Z');

        $this->assertStringNotContainsString('<script>', $entry);
        $this->assertStringContainsString('&lt;script&gt;', $entry);
    }

    public function testFindEntryTreatsVideoIdLiterallyNotAsRegex(): void
    {
        // Without preg_quote, the '.' would match any char and pull the wrong entry.
        $content = "<feed>\n<entry>\n <id>yt:video:axc12345678</id>\n <title>Wrong</title>\n</entry>\n"
            . "<entry>\n <id>yt:video:a.c12345678</id>\n <title>Right</title>\n</entry>\n</feed>";

        $entry = (new FeedParser())->findEntry('a.c12345678', $content);

        $this->assertStringContainsString('<title>Right</title>', $entry);
        $this->assertStringNotContainsString('Wrong', $entry);
    }
}
