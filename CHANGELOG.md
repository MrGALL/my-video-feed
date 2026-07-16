# Changelog

## 0.5.4 - 2026-07-16
Order videos by ingest time, not publish time.

## 0.5.3 - 2026-07-16
Add a `publish` command to force a hub ping.

## 0.5.2 - 2026-07-16
Fix the feed crashing on pruned content, and the over-eager pruning behind it.

## 0.5.1 - 2026-07-12
Make `channel:add` ingest immediately instead of at the next cron.

## 0.5 - 2026-07-07
Consolidate HTTP into one class, validate config, debounce polls, add lint + static analysis.

## 0.4.3 - 2026-07-06
Add `ucbcb=1` to the Shorts probe to skip the EU consent redirect.

## 0.4.2 - 2026-07-06
Make `video:info` print parsed info, not raw API JSON.

## 0.4.1 - 2026-07-06
Add optional Shorts detection via a HEAD probe.

## 0.4 - 2026-07-06
Rebuild push entries from trusted data, 404 bad channel ids, expand the test suite.

## 0.3.1 - 2026-07-05
Fill a channel title from the feed only while it's the slug placeholder.

## 0.3 - 2026-07-05
Harden the push path: verify video ownership, no auto-created channels, escape reflected output.

## 0.2 - 2026-07-05
Add tag-based exclusion and a `video:info` command.

## 0.1 - 2026-07-05
Initial release.