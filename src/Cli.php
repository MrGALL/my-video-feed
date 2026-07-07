<?php

declare(strict_types=1);

namespace App;

final class Cli
{
    /** @param list<int> $ingestHours */
    public function __construct(
        private readonly Db $db,
        private readonly Repository $repo,
        private readonly Ingestor $ingestor,
        private readonly YoutubeApi $api,
        private readonly string $timezone,
        private readonly array $ingestHours,
        private readonly int $subscribeDow,
        private readonly int $subscribeHour,
    ) {}

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? '';
        $args = array_slice($argv, 2);

        return match ($command) {
            'cron' => $this->cron(),
            'ingest' => $this->ingest(),
            'subscribe' => $this->subscribe(),
            'video:info' => $this->videoInfo($args),
            'channel:add' => $this->channelAdd($args),
            'channel:list' => $this->channelList(),
            'blacklist:add' => $this->blacklistAdd($args),
            'blacklist:list' => $this->blacklistList(),
            'db:init' => $this->dbInit(),
            default => $this->usage(),
        };
    }

    /** Hourly entrypoint: refreshes leases on the subscribe day/hour, ingests on the configured hours, else no-ops. */
    private function cron(): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone($this->timezone));
        $dow = (int) $now->format('N');
        $hour = (int) $now->format('G');

        if ($dow === $this->subscribeDow && $hour === $this->subscribeHour) {
            return $this->subscribe();
        }
        if (in_array($hour, $this->ingestHours, true)) {
            $this->ingestor->processActiveChannels();
        }
        return 0;
    }

    private function ingest(): int
    {
        $this->ingestor->processActiveChannels();
        return 0;
    }

    private function subscribe(): int
    {
        $this->ingestor->subscribeAll();
        return 0;
    }

    /** @param list<string> $args */
    private function videoInfo(array $args): int
    {
        $videoId = $args[0] ?? '';
        if ($videoId === '') {
            fwrite(STDERR, "Usage: bin/myvideofeed video:info <video_id>\n");
            return 1;
        }
        $info = $this->api->fetchVideoInfo($videoId);
        $info['short'] = $this->api->isShort($videoId);
        echo json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        return 0;
    }

    /** @param list<string> $args */
    private function channelAdd(array $args): int
    {
        $slug = $args[0] ?? '';
        if ($slug === '') {
            fwrite(STDERR, "Usage: bin/myvideofeed channel:add <channel_id>\n");
            return 1;
        }
        $this->repo->insertChannel($slug, $slug);
        echo "Added channel {$slug}\n";
        return 0;
    }

    private function channelList(): int
    {
        foreach ($this->repo->allChannels() as $channel) {
            $updated = $channel['updated'] !== null
                ? (new \DateTimeImmutable($channel['updated'], new \DateTimeZone('UTC')))
                    ->setTimezone(new \DateTimeZone($this->timezone))
                    ->format('Y-m-d H:i:s')
                : 'never';
            printf(
                "%-26s active=%d subscribe=%d updated=%-19s %s\n",
                $channel['slug'],
                (int) $channel['active'],
                (int) $channel['subscribe'],
                $updated,
                $channel['title'] ?? '',
            );
        }
        return 0;
    }

    /** @param list<string> $args */
    private function blacklistAdd(array $args): int
    {
        $term = $args[0] ?? '';
        if ($term === '') {
            fwrite(STDERR, "Usage: bin/myvideofeed blacklist:add <term>\n");
            return 1;
        }
        $this->repo->addBlacklistTerm($term);
        echo "Added blacklist term \"{$term}\"\n";
        return 0;
    }

    private function blacklistList(): int
    {
        foreach ($this->repo->blacklistTerms() as $term) {
            echo $term . "\n";
        }
        return 0;
    }

    private function dbInit(): int
    {
        $driver = $this->db->driver();
        $schemaFile = dirname(__DIR__) . "/db/schema.{$driver}.sql";
        if (!is_file($schemaFile)) {
            fwrite(STDERR, "No schema file for driver '{$driver}'\n");
            return 1;
        }
        $this->db->runScript(file_get_contents($schemaFile));
        echo "Initialized {$driver} schema.\n";
        return 0;
    }

    private function usage(): int
    {
        fwrite(STDERR, <<<TXT
        Usage: bin/myvideofeed <command> [args]

        Commands:
          db:init                  Create the database schema for the configured driver
          cron                     Hourly entrypoint: ingest at configured hours, subscribe on the configured day/hour
          ingest                   Force-process all active channels now
          subscribe                Force-refresh PubSubHubbub subscriptions now
          video:info <video_id>    Print the video info (duration, viewability, channel, short flag)
          channel:add <id>         Add a channel by its YouTube channel id (UC...)
          channel:list             List channels
          blacklist:add <term>     Add a title-match term to the blacklist
          blacklist:list           List blacklist terms

        TXT);
        return 1;
    }
}
