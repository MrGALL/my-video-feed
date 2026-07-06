<?php

declare(strict_types=1);

// Copy to config.php and edit, or set the equivalent environment variables — every value falls back to getenv().

return [
    // Interprets cron.* hours/day below; storage stays UTC regardless.
    'timezone' => getenv('TZ') ?: 'UTC',

    // This app's own public base URL — the feed URL, id/self links, publisher URL,
    // subscriber callbacks, and inbound routing prefix are all derived from this.
    'base_url' => getenv('APP_URL') ?: 'https://example.com/',

    'db' => [
        'driver' => getenv('DB_DRIVER') ?: 'sqlite',       // 'sqlite' or 'mysql'
        'path' => getenv('DB_PATH') ?: __DIR__ . '/db/myvideofeed.sqlite',
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'name' => getenv('DB_NAME') ?: 'myvideofeed',
        'user' => getenv('DB_USER') ?: 'myvideofeed',
        'pass' => getenv('DB_PASS') ?: '',
    ],

    // Optional YouTube Data API v3 key; adds video duration and viewability. See README.
    'youtube_key' => getenv('YOUTUBE_API_KEY') ?: '',

    'feed' => [
        'title' => getenv('FEED_TITLE') ?: 'My Video Feed',
    ],

    // Subscriber (inbound): subscribe to YouTube updates via PubSubHubbub for near-instant ingest.
    // Set url to enable; leave empty to run poll-only.
    'subscriber' => [
        'url' => getenv('SUBSCRIBER_URL') ?: '',
        'topic_base' => getenv('SUBSCRIBER_TOPIC_BASE') ?: 'https://www.youtube.com/xml/feeds/videos.xml?channel_id=',
        'lease_seconds' => (int) (getenv('SUBSCRIBER_LEASE_SECONDS') ?: (3600 * 168) - 1),
    ],

    // Publisher (outbound): notify a hub (e.g. Superfeedr) that the aggregate feed changed.
    // Set url to enable; it's also declared in the feed as its <link rel="hub">.
    'publisher' => [
        'url' => getenv('PUBLISHER_URL') ?: '',
    ],

    'filter' => [
        'min_duration_seconds' => (int) (getenv('FILTER_MIN_DURATION_SECONDS') ?: 30),
        // Pipe-separated substrings stripped from titles before display'.
        'strip_patterns' => array_values(array_filter(explode('|', getenv('FILTER_STRIP_PATTERNS') ?: ''))),
        'max_title_length' => (int) (getenv('FILTER_MAX_TITLE_LENGTH') ?: 78),
        // Pipe-separated tags; a video tagged with any is skipped (needs API key).
        'exclude_tags' => array_values(array_filter(array_map('trim', explode('|', getenv('FILTER_EXCLUDE_TAGS') ?: '')))),
        'title_prefix' => getenv('FILTER_TITLE_PREFIX') ?: '[{channel}] {title}',
        'upgrade_thumbnail' => filter_var(getenv('FILTER_UPGRADE_THUMBNAIL') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    ],

    // Gating for `bin/myvideofeed cron` (invoked hourly).
    'cron' => [
        'ingest_hours' => array_map(
            'intval',
            array_filter(explode(',', getenv('CRON_INGEST_HOURS') ?: '9,12,15,18')),
        ),
        'subscribe_dow' => (int) (getenv('CRON_SUBSCRIBE_DOW') ?: 1), // ISO-8601: 1 = Monday
        'subscribe_hour' => (int) (getenv('CRON_SUBSCRIBE_HOUR') ?: 5),
    ],

    'audit_log' => getenv('AUDIT_LOG_PATH') ?: __DIR__ . '/logs/audit.log',
];
