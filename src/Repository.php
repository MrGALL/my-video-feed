<?php

declare(strict_types=1);

namespace App;

final class Repository
{
    // Shared by every recentVideos* read; each caller appends its own window + ORDER BY.
    private const string FROM_VIDEOS =
        'FROM myvideofeed_videos AS v LEFT JOIN myvideofeed_channels AS c ON c.id=channel_id';

    public function __construct(private readonly Db $db) {}

    /** @return list<array<string, mixed>> */
    public function activeChannelsToProcess(): array
    {
        // Never-processed channels (updated IS NULL) are due immediately, like ones idle 10+ minutes.
        $cutoff = gmdate('Y-m-d H:i:s', time() - 600);
        return $this->db->fetchAll(
            'SELECT slug FROM myvideofeed_channels WHERE active = ? AND (updated IS NULL OR updated < ?) ORDER BY updated ASC',
            [1, $cutoff],
        );
    }

    /** @return list<array<string, mixed>> */
    public function subscribableChannels(): array
    {
        return $this->db->fetchAll(
            'SELECT slug FROM myvideofeed_channels WHERE active = ? AND subscribe = ? ORDER BY id',
            [1, 1],
        );
    }

    /** @return array<string, mixed>|null */
    public function findChannel(string $slug): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, active, title, updated FROM myvideofeed_channels WHERE slug = ?',
            [$slug],
        );
    }

    /** @return list<array<string, mixed>> */
    public function allChannels(): array
    {
        return $this->db->fetchAll(
            'SELECT id, slug, title, active, subscribe, published, updated FROM myvideofeed_channels ORDER BY id',
        );
    }

    public function insertChannel(string $slug, string $title): void
    {
        $this->db->insertIgnore(
            'INTO myvideofeed_channels (slug, title) VALUES (?, ?)',
            [$slug, $title],
        );
    }

    public function updateChannelTitle(int $id, string $title): void
    {
        $this->db->execute(
            'UPDATE myvideofeed_channels SET title = ? WHERE id = ?',
            [$title, $id],
        );
    }

    public function touchChannel(string $slug): void
    {
        $this->db->execute(
            'UPDATE myvideofeed_channels SET updated = ? WHERE slug = ?',
            [gmdate('Y-m-d H:i:s'), $slug],
        );
    }

    public function refreshChannelPublishedTimes(): void
    {
        // Correlated subquery (not a multi-table join) for MySQL+SQLite portability.
        $this->db->execute(
            'UPDATE myvideofeed_channels SET published = ('
            . 'SELECT MAX(v.published) FROM myvideofeed_videos v WHERE v.channel_id = myvideofeed_channels.id'
            . ') WHERE active = ?',
            [1],
        );
    }

    /** @return array<string, mixed>|null */
    public function findVideo(string $slug): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, duration FROM myvideofeed_videos WHERE slug = ?',
            [$slug],
        );
    }

    public function insertVideo(
        int $channelId,
        string $slug,
        string $title,
        string $content,
        ?string $duration,
        string $published,
    ): void {
        $this->db->insertIgnore(
            'INTO myvideofeed_videos (channel_id, slug, title, content, duration, published, updated) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$channelId, $slug, $title, $content, $duration, $published, gmdate('Y-m-d H:i:s')],
        );
    }

    public function updateVideo(int $id, string $title, string $content): void
    {
        $this->db->execute(
            'UPDATE myvideofeed_videos SET title = ?, content = ? WHERE id = ?',
            [$title, $content, $id],
        );
    }

    public function clearOldVideoContent(int $days = 14): void
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $this->db->execute(
            'UPDATE myvideofeed_videos SET content = NULL WHERE published < ?',
            [$cutoff],
        );
    }

    /** @return list<array<string, mixed>> */
    public function recentVideosForChannel(): array
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - 5 * 86400);
        return $this->db->fetchAll(
            'SELECT v.id, v.slug, v.duration, v.title, c.title as channel, content, v.published '
            . self::FROM_VIDEOS
            . ' WHERE active = ? AND v.updated > ? ORDER BY v.published DESC',
            [1, $cutoff],
        );
    }

    /** @return list<array<string, mixed>> */
    public function recentVideosByPublished30d(): array
    {
        return $this->recentVideos30d('v.published');
    }

    /** @return list<array<string, mixed>> */
    public function recentVideosByUpdated30d(): array
    {
        return $this->recentVideos30d('v.updated');
    }

    /**
     * The 30-day listing for /excluded and /included; they differ only by sort column.
     *
     * @param string $orderColumn trusted internal literal ('v.published'|'v.updated'), never user input
     * @return list<array<string, mixed>>
     */
    private function recentVideos30d(string $orderColumn): array
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        return $this->db->fetchAll(
            'SELECT v.id, v.title, v.duration, c.title as channel, v.published '
            . self::FROM_VIDEOS
            . ' WHERE active = ? AND v.published > ? ORDER BY ' . $orderColumn . ' DESC',
            [1, $cutoff],
        );
    }

    /** @return list<array<string, mixed>> */
    public function recentVideosForPing(): array
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - 14 * 86400);
        return $this->db->fetchAll(
            'SELECT v.id, v.title, v.updated '
            . self::FROM_VIDEOS
            . ' WHERE active = ? AND v.published > ? ORDER BY v.updated DESC',
            [1, $cutoff],
        );
    }

    /** @return list<string> */
    public function blacklistTerms(): array
    {
        $rows = $this->db->fetchAll('SELECT term FROM myvideofeed_blacklist');
        return array_column($rows, 'term');
    }

    public function addBlacklistTerm(string $term): void
    {
        $this->db->execute('INSERT INTO myvideofeed_blacklist (term) VALUES (?)', [$term]);
    }
}
