<?php

declare(strict_types=1);

namespace App\Tests;

use App\FeedParser;
use PHPUnit\Framework\TestCase;

final class FeedParserTest extends TestCase
{
    public function testRewriteInboundPushSynthesisesMediaGroupWhenMissing(): void
    {
        $content = "<feed>\n<entry>\n <id>yt:video:abc12345678</id>\n <title>Test Video</title>\n</entry>\n</feed>";

        $result = (new FeedParser())->rewriteInboundPush($content);

        $this->assertStringContainsString('<media:group>', $result);
        $this->assertStringContainsString('<media:title>Test Video</media:title>', $result);
        $this->assertStringContainsString('abc12345678/hqdefault.jpg', $result);
    }

    public function testRewriteInboundPushLeavesExistingMediaGroupAlone(): void
    {
        $content = "<entry>\n <id>yt:video:abc12345678</id>\n <title>Test Video</title>\n"
            . " <media:group>\n  <media:title>Test Video</media:title>\n </media:group>\n</entry>";

        $result = (new FeedParser())->rewriteInboundPush($content);

        $this->assertSame(1, substr_count($result, '<media:group>'));
    }

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
            '2024-01-01T00:00:00+00:00',
            'My Feed',
            'https://example.com/channels',
            'https://hub.example.com/',
        );

        $this->assertStringContainsString('<title>My Feed</title>', $xml);
        $this->assertStringContainsString('rel="hub" href="https://hub.example.com/"', $xml);
    }

    public function testRenderAtomFeedUsesSameUrlForIdAndSelfLink(): void
    {
        $xml = (new FeedParser())->renderAtomFeed(
            '<entry/>',
            null,
            'My Feed',
            'https://example.com/channels',
            '',
        );

        $this->assertStringContainsString('<id>https://example.com/channels</id>', $xml);
        $this->assertStringContainsString('rel="self" href="https://example.com/channels"', $xml);
        $this->assertStringNotContainsString('rel="hub"', $xml);
    }
}
