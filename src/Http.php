<?php

declare(strict_types=1);

namespace App;

use CurlHandle;

/** Every outbound curl call lives here, sharing one User-Agent and timeout. */
final class Http
{
    private const string USER_AGENT = 'Mozilla/5.0 (compatible; myvideofeed)';
    private const int TIMEOUT_SECONDS = 10;

    /** GET returning the body, or false on transport failure. */
    public static function get(string $url): string|false
    {
        $ch = self::handle($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // Keep 4xx/5xx bodies (e.g. Data API 403-quota) readable, not collapsed to false.
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        return curl_exec($ch);
    }

    /** HEAD returning the HTTP status; 0 on transport failure. */
    public static function head(string $url): int
    {
        $ch = self::handle($url);
        curl_setopt($ch, CURLOPT_NOBODY, true);           // HEAD
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);  // see the 3xx, don't follow it — else every video looks like 200
        curl_exec($ch);
        return (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    }

    /**
     * POST $fields.
     *
     * @param array<string, scalar>|string $fields
     * @return array{0: string|bool, 1: int, 2: ?string} [curl result, HTTP status, error or null]
     */
    public static function post(string $url, array|string $fields): array
    {
        $ch = self::handle($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        return [$result, $status, $err];
    }

    /** A curl handle for $url with the shared User-Agent + connect/total timeouts applied. */
    private static function handle(string $url): CurlHandle
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT_SECONDS);
        return $ch;
    }
}
