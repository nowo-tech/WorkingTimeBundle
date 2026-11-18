# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.2] - 2026-11-18

### Fixed

- **Published heads below a forged start** (audit ST hardening, probes d2 and e2; ST-H2): `--heads-out`,
  `--publish-heads` and the exports' `chain_heads` now also record the head link's workday date (`day`). A published
  head below the chain's start is excused only when that day is before the `first_kept_date` of the anchor at the
  start, which is exactly what the purge deletes. A recent head removed together with a forged anchor at the new start,
  or behind a forged anchor past a removed chain, is reported as `published_head_missing`, while a late correction,
  acknowledgement or approval of a purged day (created after the boundary) is no longer a false alarm. Entries without
  a `day` (0.1.1 heads files and export rows) keep the 0.1.1 rule (below the start); `at` is informational only.
- `anchor_out_of_retention` tolerates five minutes of clock skew for a purge date ahead of the verifier's clock.
- `--heads` refuses (exit 2) an entry whose `seq` is below 1 unless it is the genesis with `seq` 0, an `at` that is not
  exactly a UTC ISO 8601 time (overflowing values such as `2026-02-31T25:61:00Z` included) and a `day` that is not a
  real `Y-m-d` date.
- Demo e2e: the correction step amends the start to one hour before the clock-in instead of a fixed 07:30, so the
  suite no longer fails when it runs before 07:30 in the tenant's time zone.

## [0.1.1] - 2026-11-18

### Fixed

- **Published heads after a purge** (audit ST-D1, ST-D1b): `--heads-out`, `--publish-heads` and the exports'
  `chain_heads` now record each head with its `seq` (`{hash, seq, at}`; exports `{hash, seq}`), and a missing
  published head is accepted only when its `seq` is below the chain's start (the first link, or the last anchor's
  `first_kept_seq` for a chain the purge emptied). Before, any purge of the chain, or any anchor carrying the head's
  hash, excused it, so `verify --heads` no longer caught a removed tail. The genesis is a head only with `seq` 0. A
  0.1.0 heads file (bare hashes) is refused (exit 2); see UPGRADING.md. A chain the purge emptied reports its last
  anchor as its head.
- **Forged purge anchors**: an anchor past the chain's first link is reported as `anchor_after_start`, and an anchor
  keeping less than `seq` 2 (a purge never writes one) as `anchor_seq_invalid`.
- **Single entity manager by name** (audit ST Info): `doctrine.entity_manager` set to the default manager's name is
  treated as shared like `null`, so the bundle releases only its own entities and refuses to write over the host's
  pending changes instead of clearing the whole entity manager.
- UPGRADING: the 0.1.0 section describes a first install (`composer require`), not an upgrade from 0.0.0.

## [0.1.0] - 2026-11-17

### Added

- **Working-hours register** (contract S1, Beacon spec 198): workdays, work / break segments and append-only chain
  links (`working_time_workday`, `_segment`, `_revision`, `_chain_anchor`), corrections with a version, dated schedules
  (worker → group → tenant, fallback port), tenant settings with a version, worker preferences, exports, the notices
  outbox; XML mapping on the private entity manager (or the default one with `entity_manager: null`), table prefix and
  binary NO PAD collations for every reference.
- **Append-only triggers** for MySQL 8, MariaDB, PostgreSQL (TRUNCATE included) and SQLite;
  `nowo:working-time:triggers:install|check|uninstall`; the purge guard (session variable or `db_user`, PostgreSQL
  `SET LOCAL`) cleared in `finally` and on every MySQL / MariaDB connect.
- **Hash chain** in Beacon's format 1 (migrated links, never re-hashed) and the bundle's format 2, purge anchors and
  `nowo:working-time:verify` (missing link, hash mismatch, unanchored start, changed / missing segment).
- **Clock** (`RegisterClockInterface`): state machine, idempotent request keys, offline client times, IP evidence as a
  /24 or /64 prefix only, no automatic clock-out, race replay; task-timer link.
- **Corrections** (`CorrectionServiceInterface`): requests in the window, decisions on the version shown (stale ⇒ 409 +
  `Retry-After`, nothing stored, no notice), manager-recorded corrections with acknowledgement / contest.
- **Exports**: own copy and inspection exports, CSV (Beacon's columns, formula-injection safe), closed Dompdf PDF, team
  ZIP with `SHA256SUMS.txt`, chain verified first, download window, `X-Content-SHA256`.
- **Notices**: outbox with dedupe keys delivered inside the recipient's hours, reminders and the monthly summary
  (`nowo:working-time:notices`); **retention purge** (`nowo:working-time:purge`, 4–10 years).
- **GDPR**: `RegisterPersonalData` and the UserKit export provider / eraser (register kept, art. 17.3.b).
- **TimeTrack bridge**: `WorkdaySourceInterface` for the desktop app and the extension, task-timer link.
- **Pages** (UiKit + FormKit, opt-in): today, month, day, team, schedules, exports, settings, preferences; Twig
  `working_time_header_clock()`; 409 + `Retry-After` through transaction-doctrine's policy and formatter.
- Translations in en, es, it, fr, pt, de, nl, ca, eu, gl; demos for Symfony 7.4 and 8.1 with a Playwright e2e.
- Requires `nowo-tech/transaction-doctrine ^1.3` and `nowo-tech/ui-kit-bundle ^2.55`.
- Integrity hardening (audit ST):
  - UTC storage of every instant (`nowo_wt_utc_datetime`).
  - `db_user` guard on `USER()`; it needs `purge_connection`.
  - Correction decide / record / answer replay once on a taken chain head, then answer 409 + `Retry-After`.
  - `verify` cross-checks segments against links, stale heads, anchors against the retention boundary
    (`first_kept_date`), and earlier heads (`--heads`, export `chain_heads`, `--publish-heads`, `--heads-out`).
  - `triggers:check` verifies that triggers are enabled and checks their bodies.
  - In single-EM mode the host's pending changes are never flushed or cleared.
  - Notices re-check the hours at delivery; `register.reminders.default_hours` covers range-less days.
  - Downloads re-check `export`.
  - The GDPR export keeps the manager's reason.
  - The TimeTrack source acts only for the signed-in worker.
  - A worker-scoped `manage_team` opens the team page for the managed workers and lets the manager decide on them.
