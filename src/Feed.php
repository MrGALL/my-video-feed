<?php

declare(strict_types=1);

namespace App;

final class Feed
{
    /** @param list<string> $stripPatterns */
    public function __construct(
        private readonly Repository $repo,
        private readonly FeedParser $parser,
        private readonly int $minDurationSeconds,
        private readonly string $feedTitle,
        private readonly string $feedUrl,
        private readonly string $hubUrl,
        private readonly array $stripPatterns = [],
        private readonly string $titlePrefix = '[{channel}] {title}',
        private readonly int $maxTitleLength = 78,
        private readonly bool $upgradeThumbnail = false,
        private readonly string $timezone = 'UTC',
    ) {}

    /**
     * @param array{title: string, duration?: ?string} $video
     * @param list<string> $blacklistTerms
     */
    public static function isExcluded(array $video, array $blacklistTerms, int $minDurationSeconds): bool
    {
        foreach ($blacklistTerms as $term) {
            if (stripos($video['title'], $term) !== false) {
                return true;
            }
        }
        if (!empty($video['duration'])) {
            // Parse HH:MM:SS as a UTC timestamp anchored at 1970-01-01 so it equals total seconds.
            $time = strtotime('1970-01-01 ' . $video['duration'] . 'UTC');
            if ($time > 0 && $time <= $minDurationSeconds) {
                return true;
            }
        }
        return false;
    }

    public static function minutes(string $time): int
    {
        preg_match('#^(?<hours>[\d]{2}):(?<mins>[\d]{2}):(?<secs>[\d]{2})$#', $time, $parse);
        return (int) $parse['hours'] * 60 + (int) $parse['mins'];
    }

    /** @return list<array<string, mixed>> Recent channel videos with the excluded ones removed. */
    private function visibleRecentVideos(): array
    {
        $blacklist = $this->repo->blacklistTerms();
        return array_values(array_filter(
            $this->repo->recentVideosForChannel(),
            fn (array $video): bool => !self::isExcluded($video, $blacklist, $this->minDurationSeconds),
        ));
    }

    public function renderAggregate(): string
    {
        $published = null;
        $content = '';
        foreach ($this->visibleRecentVideos() as $video) {
            $video['content'] = $this->decorateContent($video);
            $published ??= gmdate('c', strtotime($video['published']));
            $content .= ' ' . $video['content'] . "\n";
        }

        return $this->parser->renderAtomFeed(
            $content,
            $published,
            $this->feedTitle,
            $this->feedUrl,
            $this->hubUrl,
        );
    }

    /** Self-contained HTML page of the same recent videos, as a responsive card grid. */
    public function renderHtml(): string
    {
        $cards = '';
        foreach ($this->visibleRecentVideos() as $video) {
            $cards .= $this->card($video);
        }
        if ($cards === '') {
            $cards = '<p class="empty">No recent videos.</p>';
        }

        $title = htmlspecialchars($this->feedTitle, ENT_QUOTES, 'UTF-8');
        return '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="alternate" type="application/atom+xml" href="' . $this->feedUrl . '">
<title>' . $title . '</title>
<style>
:root { --bg:#fff; --fg:#111; --muted:#666; --card:#f4f4f5; --border:#e4e4e7; }
@media (prefers-color-scheme: dark) { :root { --bg:#0f0f10; --fg:#f2f2f3; --muted:#a1a1aa; --card:#1c1c1f; --border:#2a2a2e; } }
* { box-sizing: border-box; }
body { margin:0; background:var(--bg); color:var(--fg); font:16px/1.4 system-ui,-apple-system,Segoe UI,Roboto,sans-serif; }
.wrap { max-width:1200px; margin:0 auto; padding:24px 16px 48px; }
h1 { font-size:1.4rem; margin:0 0 20px; }
.grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:20px; }
.card { background:var(--card); border:1px solid var(--border); border-radius:12px; overflow:hidden; text-decoration:none; color:inherit; display:flex; flex-direction:column; transition:transform .1s ease; }
.thumb { aspect-ratio:16/9; width:100%; object-fit:cover; background:var(--border); display:block; }
.body { padding:10px 12px 14px; }
.title { font-weight:600; font-size:.95rem; margin:0 0 6px; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.channel { color:var(--muted); font-size:.85rem; }
.meta { color:var(--muted); font-size:.8rem; margin-top:4px; }
.empty { color:var(--muted); }
</style>
</head>
<body>
<div class="wrap">
<h1>' . $title . '</h1>
<div class="grid">
' . $cards . '</div>
</div>
</body>
</html>
';
    }

    /** @param array<string, mixed> $video */
    private function card(array $video): string
    {
        $slug = rawurlencode($video['slug']);
        $watch = 'https://www.youtube.com/watch?v=' . $slug;
        // Same quality the Atom feed uses (see decorateContent).
        $quality = $this->upgradeThumbnail ? 'maxres2' : 'hqdefault';
        $thumb = 'https://i.ytimg.com/vi/' . $slug . '/' . $quality . '.jpg';

        $title = htmlspecialchars(str_replace($this->stripPatterns, '', $video['title']), ENT_QUOTES, 'UTF-8');
        $channel = htmlspecialchars($video['channel'] ?? '', ENT_QUOTES, 'UTF-8');
        $date = (new \DateTimeImmutable($video['published'], new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone($this->timezone))
            ->format('M j, Y');
        $meta = $date;
        if (!empty($video['duration'])) {
            $meta .= ' &middot; ' . self::minutes($video['duration']) . 'm';
        }

        return '<a class="card" href="' . htmlspecialchars($watch, ENT_QUOTES, 'UTF-8') . '">'
            . '<img class="thumb" loading="lazy" src="' . htmlspecialchars($thumb, ENT_QUOTES, 'UTF-8') . '" alt="">'
            . '<div class="body">'
            . '<p class="title">' . $title . '</p>'
            . '<div class="channel">' . $channel . '</div>'
            . '<div class="meta">' . $meta . '</div>'
            . '</div></a>' . "\n";
    }

    /** @param array<string, mixed> $video */
    private function decorateContent(array $video): string
    {
        $content = $video['content'];

        // Inject the media:thumbnail URL as inline HTML so readers that strip media: still preview it.
        preg_match('#media\:thumbnail url="(.*)"#sU', $content, $match);
        if (!empty($match[1])) {
            $img = "<img src='" . htmlspecialchars($match[1], ENT_QUOTES, 'UTF-8') . "' />";
            $content = str_replace(
                '<media:group>',
                '<content type="html"><![CDATA[ ' . $img . " ]]></content>\n  <media:group>",
                $content,
            );
        }

        $rawTitle = str_replace($this->stripPatterns, '', $video['title']);
        $title = str_replace(['{channel}', '{title}'], [$video['channel'], $rawTitle], $this->titlePrefix);
        $title = preg_replace('/([\.]{2,6})/', '…', $title);
        $title = preg_replace('/([!]{2,6})/', '!', $title);
        if (mb_strlen($title, 'UTF-8') > $this->maxTitleLength) {
            $title = mb_substr($title, 0, $this->maxTitleLength, 'UTF-8') . '…';
        }
        $title = htmlspecialchars($title, ENT_NOQUOTES, 'UTF-8');
        // addcslashes guards $ and \ from preg_replace's backreference syntax.
        $content = preg_replace(
            '/<title>(.*)<\/title>/',
            '<title>' . addcslashes($title, '\\$') . '</title>',
            $content,
        );
        if (!empty($video['duration'])) {
            $content = str_replace(
                '</title>',
                ' (' . self::minutes($video['duration']) . 'm)</title>',
                $content,
            );
        }
        // Upgrade thumbnail resolution: hqdefault (~480p) -> maxres2 (1280p when available).
        if ($this->upgradeThumbnail) {
            $content = str_replace('hqdefault', 'maxres2', $content);
        }
        return $content;
    }

    public function renderExcluded(): string
    {
        return $this->summaryLines($this->repo->recentVideosByPublished30d(), keepExcluded: true, stripTitles: false);
    }

    public function renderIncluded(): string
    {
        return $this->summaryLines($this->repo->recentVideosByUpdated30d(), keepExcluded: false, stripTitles: true);
    }

    /**
     * One summary line per video, keeping either the excluded or the included set.
     *
     * @param list<array<string, mixed>> $videos
     */
    private function summaryLines(array $videos, bool $keepExcluded, bool $stripTitles): string
    {
        $blacklist = $this->repo->blacklistTerms();
        $out = '';
        foreach ($videos as $video) {
            if (self::isExcluded($video, $blacklist, $this->minDurationSeconds) !== $keepExcluded) {
                continue;
            }
            if ($stripTitles) {
                $video['title'] = str_replace($this->stripPatterns, '', $video['title']);
            }
            $out .= $this->summaryLine($video) . "\n";
        }
        return $out;
    }

    /** @param array<string, mixed> $video */
    private function summaryLine(array $video): string
    {
        $line = '(' . $video['published'] . ') [' . $video['channel'] . '] ' . $video['title'];
        if (!empty($video['duration'])) {
            $line .= ' (' . self::minutes($video['duration']) . 'm)';
        }
        return $line;
    }
}
