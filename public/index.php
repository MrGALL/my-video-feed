<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Bootstrap;

date_default_timezone_set('UTC');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    Bootstrap::build()->app->run();
} catch (\Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
    }
    error_log('[myvideofeed] ' . $e->getMessage());
}
