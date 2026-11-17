# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`admon/log-shipper` — a tiny Laravel package (no app, no tests, no build) that ships a project's `storage/logs/**/*.log` files to the central log server (sibling repo `c:\wamp64\www\log-server`, live at https://logs.rivion.ai) and pulls them back down for analysis. Supports PHP `^7.4|^8.0`, Laravel (illuminate/*) 7 through 12, Guzzle 6.5 or 7.

Consuming projects (crm, aist, aist_core, aist_staff, rivion_pm, rivion_growth, rivion_studio, syncora, otp, sca, teambench, ...) install it from GitHub as a VCS repository pinned to `dev-main`. So **a push to `main` is a release** — every consumer picks it up on its next `composer update admon/log-shipper`. There is no tagging.

## Layout

- `src/LogShipperServiceProvider.php` — auto-discovered provider. `register()` uses `mergeConfigFrom` (do not replace it with `config()->set(...)`: that clobbered the cached token after `config:cache` and silently broke shipping everywhere). `boot()` registers both commands and **auto-registers the schedule** in the consumer's scheduler (no Kernel changes needed):
  - `logs:ship --no-cleanup` every six hours
  - `logs:ship --days-to-keep=7` daily at 01:00 (ships, then deletes local logs older than 7 days; today's dated file is never deleted)
  - `projectId()` builds the remote project name as `<folder>--<db name>` (e.g. `aist--super_tutor`), or just the folder when the DB is `:memory:`/unset. Renaming a consumer's folder or DB therefore starts a new project on the server.
- `src/Commands/ShipLogs.php` — `logs:ship` (alias `logs:ship-to-s3`), options `--dry-run`, `--no-cleanup`, `--days-to-keep=7`. Checks connectivity with `GET /api/projects`, groups files by the `YYYY-MM-DD` in the filename (falls back to mtime), flattens subpaths with `--`, and POSTs one multipart `/api/ingest` per date with raw text streamed from file handles. Empty files are skipped. Exits 1 if any upload failed.
- `src/Commands/PullLogs.php` — `logs:pull` (alias `logs:pull-from-s3`), options `--project=*` (fuzzy substring match), `--days=1`, `--date=`, `--output=` (default `~/.claude/logs`), `--list`.
- `config/log-shipper.php` — `url` (`LOG_SHIPPER_URL`, default `https://logs.rivion.ai`) and `token` (`LOG_SHIPPER_TOKEN`). Both commands refuse to run without a token.

The server API contract (bearer token, `/api/ingest`, `/api/projects`, `/api/logs`, `/api/logs/download`) lives in `log-server`; change both sides together.

## Gotchas

- **Keep it compatible with the oldest supported stack (PHP 7.4 / Laravel 7).** Recent fixes exist because of this: `$this->newLine()` was removed (Laravel 7 lacks it; use `$this->output->writeln('')`). No PHP 8-only syntax (constructor promotion, `match`, named args, union types, nullsafe). The code does call `str_contains` / `str_starts_with`, which on PHP 7.4 only work if the consumer has `symfony/polyfill-php80` installed.
- Scheduled events get a per-project mutex name (`log-shipper:ship|cleanup:<projectId>`). Laravel's default name is a hash of cron + command text, so two apps sharing a Redis db and cache prefix (aist + aist_staff on the PH box) used the same lock and one silently never ran.
- No local test harness: verify changes with `php -l` on the touched files and `php artisan logs:ship --dry-run` inside a consuming project.
