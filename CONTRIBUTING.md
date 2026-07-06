# Contributing

This is a small, dependency-light PHP app; the goal is to keep it that way.

## Setup and tests

Requires PHP 8.3+ with `pdo_sqlite` (or `pdo_mysql`), `curl`, `simplexml`,
`mbstring`, plus [Composer](https://getcomposer.org/).

```
composer install
vendor/bin/phpunit
```

Tests use in-memory SQLite, so they need no database or network. CI runs the same
suite on PHP 8.3 and 8.4. Code that would hit the network is tested by subclassing
`YoutubeApi` (overriding `httpGet()`) or `Hub` — see `tests/FakeYoutubeApi` and
`tests/FakeHub`. For a running instance, see the README (bare-metal or Docker); a
good smoke-test channel id is `UC_x5XG1OV2P6uZZ5FSM9Ttw`.

## Coding standards

- **PSR-12**, with `declare(strict_types=1)` in every PHP file.
- Standard library first - there are no runtime dependencies; keep it that way
  where reasonable.
- Small, single-purpose functions; descriptive names; match the style of the
  file you edit.
- Comment the *why*, not the *what*.

**Portable SQL** (the app targets both SQLite and MySQL/MariaDB from one query
set): compute time windows in PHP and bind them (no `NOW()`/`DATE_SUB()`), prefer
correlated subqueries over multi-table `UPDATE` joins, and use
`Db::insertIgnore()`. Timestamps are always stored and compared in **UTC**; the
configured timezone is display-only.

## Pull requests

1. Open an issue first for anything beyond a small fix.
2. Branch off `main`; add tests for new behavior (edge and error paths, not just
   the happy path).
3. Ensure `composer validate --strict` and `vendor/bin/phpunit` pass.
4. Keep commits focused; explain the *why* in the PR.

Contributions are licensed under the project's [MIT License](LICENSE).
