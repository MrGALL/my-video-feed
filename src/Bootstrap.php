<?php

declare(strict_types=1);

namespace App;

/** Composition root: wires the object graph and exposes the web (App) and CLI (Cli) entrypoints. */
final class Bootstrap
{
    private function __construct(
        public readonly App $app,
        public readonly Cli $cli,
    ) {}

    public static function build(?string $configPath = null): self
    {
        // Both files read purely from getenv(), so config.example.php works as the fallback.
        $root = dirname(__DIR__);
        $configPath ??= is_file("{$root}/config.php") ? "{$root}/config.php" : "{$root}/config.example.php";
        if (!is_file($configPath)) {
            throw new \RuntimeException("Config file not found: {$configPath}");
        }
        /** @var array<string, mixed> $config */
        $config = require $configPath;

        $db = self::connectDb($config['db']);
        $repo = new Repository($db);
        $api = new YoutubeApi($config['youtube_key'], $config['filter']['exclude_tags']);
        $parser = new FeedParser();

        $base = rtrim($config['base_url'], '/') . '/';
        $feedUrl = $base . 'channels';
        $basePath = rtrim((string) parse_url($base, PHP_URL_PATH), '/');
        $publishUrl = $config['publisher']['url'];

        $hub = new Hub(
            subscribeUrl: $config['subscriber']['url'],
            callbackBase: $base,
            topicBase: $config['subscriber']['topic_base'],
            leaseSeconds: $config['subscriber']['lease_seconds'],
            publishUrl: $publishUrl,
            feedUrl: $feedUrl,
        );
        $ingestor = new Ingestor($repo, $api, $parser, $hub, $config['audit_log'], $config['timezone']);
        $feed = new Feed(
            repo: $repo,
            parser: $parser,
            minDurationSeconds: $config['filter']['min_duration_seconds'],
            feedTitle: $config['feed']['title'],
            feedUrl: $feedUrl,
            // The feed's <link rel="hub"> is the same hub the publisher notifies.
            hubUrl: $publishUrl,
            stripPatterns: $config['filter']['strip_patterns'],
            titlePrefix: $config['filter']['title_prefix'],
            maxTitleLength: $config['filter']['max_title_length'],
            upgradeThumbnail: $config['filter']['upgrade_thumbnail'],
            timezone: $config['timezone'],
        );

        $app = new App($ingestor, $feed, $basePath);
        $cli = new Cli(
            $db,
            $repo,
            $ingestor,
            $api,
            $config['timezone'],
            $config['cron']['ingest_hours'],
            $config['cron']['subscribe_dow'],
            $config['cron']['subscribe_hour'],
        );

        return new self($app, $cli);
    }

    /** @param array<string, mixed> $db */
    private static function connectDb(array $db): Db
    {
        if ($db['driver'] === 'sqlite') {
            $dir = dirname($db['path']);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            return new Db('sqlite', "sqlite:{$db['path']}");
        }
        if ($db['driver'] === 'mysql') {
            $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4";
            return new Db('mysql', $dsn, $db['user'], $db['pass']);
        }
        throw new \InvalidArgumentException("Unsupported db.driver: {$db['driver']}");
    }
}
