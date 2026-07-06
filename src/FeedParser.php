<?php

declare(strict_types=1);

namespace App;

final class FeedParser
{
    /** Synthesises a <media:group> from id + title when a push payload omits one; the rest of the pipeline requires it. */
    public function rewriteInboundPush(string $content): string
    {
        preg_match('#<entry[^>]*>(.*)</entry>#sU', $content, $matches);
        if (!isset($matches[0])) {
            return $content;
        }
        $entry = trim($matches[0]);
        $entry = preg_replace("#  <link rel=\"alternate\" hreflang=\"(.*)\"/>\n#sU", '', $entry);

        if (stripos($entry, 'media:group') === false) {
            preg_match('#<id>yt:video:(.*)</id>#sU', $entry, $idMatch);
            preg_match('#<title>(.*)</title>#sU', $entry, $titleMatch);
            if (isset($idMatch[1], $titleMatch[1])) {
                $videoId = $idMatch[1];
                $title = $titleMatch[1];
                $thumbHost = rand(1, 4);
                $entry = str_replace(
                    '</entry>',
                    ' <media:group>'
                    . "\n   <media:title>{$title}</media:title>"
                    . "\n   <media:content url=\"https://www.youtube.com/v/{$videoId}?version=3\" type=\"application/x-shockwave-flash\" width=\"640\" height=\"390\"/>"
                    . "\n   <media:thumbnail url=\"https://i{$thumbHost}.ytimg.com/vi/{$videoId}/hqdefault.jpg\" width=\"480\" height=\"360\"/>"
                    . "\n  </media:group>"
                    . "\n</entry>",
                    $entry,
                );
            }
        }
        return '<feed>' . $entry . '</feed>';
    }

    public function findEntry(string $videoId, string $content): string
    {
        preg_match(
            "#<entry[^>]*>\n\s+<id>yt:video:" . $videoId . "</id>(.*)</entry>#sU",
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
        return preg_replace(
            "#\s+<entry[^>]*>\n\s+<id>yt:video:" . $videoId . "</id>(.*)</entry>#sU",
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
