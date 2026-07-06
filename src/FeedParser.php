<?php

declare(strict_types=1);

namespace App;

final class FeedParser
{
    /** Synthesises a trusted <entry> from API-verified fields; used for push, where the body itself isn't trusted. */
    public function buildEntry(string $videoId, string $title, string $published): string
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $thumbHost = rand(1, 4);
        return '<entry>'
            . "\n  <id>yt:video:{$videoId}</id>"
            . "\n  <title>{$safeTitle}</title>"
            . "\n  <link rel=\"alternate\" href=\"https://www.youtube.com/watch?v={$videoId}\"/>"
            . "\n  <published>{$published}</published>"
            . "\n  <media:group>"
            . "\n   <media:title>{$safeTitle}</media:title>"
            . "\n   <media:content url=\"https://www.youtube.com/v/{$videoId}?version=3\" type=\"application/x-shockwave-flash\" width=\"640\" height=\"390\"/>"
            . "\n   <media:thumbnail url=\"https://i{$thumbHost}.ytimg.com/vi/{$videoId}/hqdefault.jpg\" width=\"480\" height=\"360\"/>"
            . "\n  </media:group>"
            . "\n </entry>";
    }

    public function findEntry(string $videoId, string $content): string
    {
        $id = preg_quote($videoId, '#');
        preg_match(
            "#<entry[^>]*>\n\s+<id>yt:video:" . $id . "</id>(.*)</entry>#sU",
            $content,
            $matches,
        );
        $entry = trim($matches[0] ?? '');
        $entry = preg_replace("#   <media:description>.*</media:description>\n#sU", '', $entry);
        $entry = preg_replace("#   <media:community>.*</media:community>\n#sU", '', $entry);
        return $entry;
    }

    public function deleteEntry(string $videoId, string $content): string
    {
        $id = preg_quote($videoId, '#');
        return preg_replace(
            "#\s+<entry[^>]*>\n\s+<id>yt:video:" . $id . "</id>(.*)</entry>#sU",
            '',
            $content,
        );
    }

    public function renderAtomFeed(
        string $entries,
        ?string $published,
        string $title,
        string $url,
        string $hubUrl,
    ): string {
        $hubLink = $hubUrl !== ''
            ? ' <link rel="hub" href="' . htmlspecialchars($hubUrl, ENT_QUOTES, 'UTF-8') . '" />' . "\n"
            : '';
        return '<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns:yt="http://www.youtube.com/xml/schemas/2015" xmlns:media="http://search.yahoo.com/mrss/" xmlns="http://www.w3.org/2005/Atom">
 <id>' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '</id>
 <link rel="self" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" />
' . $hubLink . ' <title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>
 <updated>' . $published . '</updated>
 <published>' . $published . '</published>
' . $entries . '</feed>';
    }
}
