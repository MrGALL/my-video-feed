<?php

declare(strict_types=1);

namespace App\Tests;

use App\App;
use App\Db;
use App\Feed;
use App\FeedParser;
use App\Ingestor;
use App\Repository;
use PHPUnit\Framework\TestCase;

final class AppTest extends TestCase
{
    private const SLUG = 'UC_x5XG1OV2P6uZZ5FSM9Ttw';

    protected function tearDown(): void
    {
        unset($_GET['hub_challenge'], $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
        http_response_code(200);
    }

    public function testAcceptsAWellFormedChannelId(): void
    {
        $this->assertTrue(App::isChannelSlug('UC_x5XG1OV2P6uZZ5FSM9Ttw'));
    }

    public function testRejectsAnythingThatIsNotAChannelId(): void
    {
        $this->assertFalse(App::isChannelSlug('channels'));                 // a route, not an id
        $this->assertFalse(App::isChannelSlug('UCtooshort'));               // too short
        $this->assertFalse(App::isChannelSlug('UC_x5XG1OV2P6uZZ5FSM9Ttww')); // 23 id chars
        $this->assertFalse(App::isChannelSlug('XX_x5XG1OV2P6uZZ5FSM9Ttw'));  // wrong prefix
        $this->assertFalse(App::isChannelSlug('UC_x5XG1OV2P6uZZ5FSM9Tt.'));  // illegal char
    }

    // --- dispatch: routing, base-path stripping, and the reordered 404/challenge gate ---

    public function testRoutesChannelsToTheAtomFeed(): void
    {
        [$app] = $this->makeApp();
        $this->request('GET', '/channels');

        $this->assertStringContainsString('<feed', $this->capture($app));
    }

    public function testRoutesHomeToTheHtmlPage(): void
    {
        [$app] = $this->makeApp();
        $this->request('GET', '/');

        $this->assertStringContainsString('<!doctype html>', $this->capture($app));
    }

    public function testStripsConfiguredBasePathBeforeRouting(): void
    {
        [$app] = $this->makeApp('/youtube');
        $this->request('GET', '/youtube/channels');

        $this->assertStringContainsString('<feed', $this->capture($app));
    }

    public function testUnknownSlugIs404AndDoesNotReflectHubChallenge(): void
    {
        [$app] = $this->makeApp();
        $this->request('GET', '/not-a-valid-channel');
        $_GET['hub_challenge'] = 'PWNED';

        $out = $this->capture($app);

        $this->assertStringNotContainsString('PWNED', $out, 'the challenge must not reflect on an unknown route');
        $this->assertSame('', $out);
    }

    public function testValidSlugReflectsHubChallengeAsPlainText(): void
    {
        [$app] = $this->makeApp();
        $this->request('GET', '/' . self::SLUG);
        $_GET['hub_challenge'] = 'echo-me';

        $this->assertSame('echo-me', $this->capture($app));
    }

    /** @return array{App, Repository, FakeYoutubeApi} */
    private function makeApp(string $basePath = ''): array
    {
        $db = new Db('sqlite', 'sqlite::memory:');
        $db->runScript(file_get_contents(dirname(__DIR__) . '/db/schema.sqlite.sql'));
        $repo = new Repository($db);
        $api = new FakeYoutubeApi();
        $ingestor = new Ingestor($repo, $api, new FeedParser(), new FakeHub(), '', 'UTC', false);
        $feed = new Feed($repo, new FeedParser(), 30, 'Test Feed', 'https://example.com/channels', '');
        return [new App($ingestor, $feed, $basePath), $repo, $api];
    }

    private function request(string $method, string $uri): void
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
    }

    private function capture(App $app): string
    {
        ob_start();
        $app->run();
        return (string) ob_get_clean();
    }
}
