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
        $config = require $configPath;
        if (!is_array($config)) {
            throw new \RuntimeException("Config file did not return an array: {$configPath}");
        }
        // Default absent optional keys, then fail fast on a missing required key with a clear message.
        $config = self::deepMerge(self::defaults(), $config);
        self::validateRequired($config);

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
        $ingestor = new Ingestor(
            $repo,
            $api,
            $parser,
            $hub,
            $config['audit_log'],
            $config['timezone'],
            $config['filter']['detect_shorts'],
        );
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

    /**
     * Fallbacks for every optional key; `base_url` and `db.driver` are absent here, so required.
     *
     * @return array<string, mixed>
     */
    private static function defaults(): array
    {
        $root = dirname(__DIR__);
        return [
            'timezone' => 'UTC',
            'youtube_key' => '',
            'db' => [
                'path' => "{$root}/db/myvideofeed.sqlite",
                'host' => 'localhost',
                'port' => 3306,
                'name' => 'myvideofeed',
                'user' => 'myvideofeed',
                'pass' => '',
            ],
            'feed' => ['title' => 'My Video Feed'],
            'subscriber' => [
                'url' => '',
                'topic_base' => 'https://www.youtube.com/xml/feeds/videos.xml?channel_id=',
                'lease_seconds' => (3600 * 168) - 1,
            ],
            'publisher' => ['url' => ''],
            'filter' => [
                'min_duration_seconds' => 30,
                'detect_shorts' => false,
                'strip_patterns' => [],
                'max_title_length' => 78,
                'exclude_tags' => [],
                'title_prefix' => '[{channel}] {title}',
                'upgrade_thumbnail' => false,
            ],
            'cron' => [
                'ingest_hours' => [9, 12, 15, 18],
                'subscribe_dow' => 1,
                'subscribe_hour' => 5,
            ],
            'audit_log' => "{$root}/logs/audit.log",
        ];
    }

    /**
     * Overlay $overrides onto $defaults: config wins per key, nested sections merge, extra keys pass through.
     *
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function deepMerge(array $defaults, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key])) {
                $defaults[$key] = self::deepMerge($defaults[$key], $value);
            } else {
                $defaults[$key] = $value;
            }
        }
        return $defaults;
    }

    /** @param array<string, mixed> $config */
    private static function validateRequired(array $config): void
    {
        if (empty($config['base_url'])) {
            throw new \RuntimeException('Config error: required key "base_url" is missing or empty.');
        }
        if (empty($config['db']['driver'])) {
            throw new \RuntimeException('Config error: required key "db.driver" is missing or empty.');
        }
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
