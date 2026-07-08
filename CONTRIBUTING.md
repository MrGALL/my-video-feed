# Contributing

This is a small, dependency-light PHP app; the goal is to keep it that way.

## Setup and tests

Requires PHP 8.3+ with `pdo_sqlite` (or `pdo_mysql`), `curl`, `simplexml`,
`mbstring`, plus [Composer](https://getcomposer.org/).

```
composer install
composer check     # PSR-12 lint + PHPStan (level 6) + PHPUnit
```

`composer check` runs `composer lint`, `composer analyse`, and `composer test`; run
them individually as needed (`composer lint:fix` autofixes style). Tests use in-memory
SQLite, so they need no database or network. CI runs the same checks on PHP 8.3 and
8.4. Code that would hit the network is tested via seams: subclass `YoutubeApi`
(overriding `httpGet()`/`httpHead()`) or `Hub` (overriding `post()`) — see
`tests/FakeYoutubeApi`, `tests/FakeHub`, and `tests/RecordingHub`. For a running
instance, see the README (bare-metal or Docker); a good smoke-test channel id is
`UC_x5XG1OV2P6uZZ5FSM9Ttw`.

## Coding standards

- **PSR-12** (enforced by `composer lint`) and **PHPStan level 6** (`composer
  analyse`), with `declare(strict_types=1)` in every PHP file.
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
3. Ensure `composer validate --strict` and `composer check` (lint + analyse + test) pass.
4. Keep commits focused; explain the *why* in the PR.

Contributions are licensed under the project's [MIT License](LICENSE).
