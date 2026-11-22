# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[0.3.0] - 2026-11-20](#030---2026-11-20)
- [[0.2.0] - 2026-11-18](#020---2026-11-18)
- [[0.1.2] - 2026-11-18](#012---2026-11-18)
- [[0.1.1] - 2026-11-18](#011---2026-11-18)
- [[0.1.0] - 2026-11-17](#010---2026-11-17)

## [Unreleased]

### Changed

- `GenerateRegisterExportHandler` is registered with `#[AsMessageHandler]` (autoconfigured in Messenger mode, REQ-MSG-001) instead of a hand-written `messenger.message_handler` tag; behaviour is unchanged. New USAGE section on retry strategy and failure transport. Docs: README demo screenshots, inventory row for `HostWriteProbe.php`.

- Demo: runs in UiKit's demo platform shell (`nowo-tech/ui-kit-bundle` ^2.60); the demo's own header is gone and the pages use the shell page headers and section cards. Both demo apps (symfony7 and symfony8) are converted; the register's clock stays in the shell top bar and the account links move to the aside. Demo only, no bundle change.
- **Twig namespace is now `@NowoWorkingTimeBundle`** (REQ-TWIG-002), the same string as the translation domain, registered
  by `TwigPathsPass`; controllers, the mailer, the PDF bridge and the templates render and include `@NowoWorkingTimeBundle/...`.
  The host override directory `templates/bundles/NowoWorkingTimeBundle/` is registered under it. The automatic
  `@NowoWorkingTime` namespace still resolves (BC alias).
- Demo `LoginType` (symfony7 and symfony8) extends `FormKitAbstractType`.

### Documentation

- `docs/USAGE.md` section 7: template override procedure with the subpath list, and the translation override procedure.
- `README.md`: Requirements before Configuration.
- `specs/001-baseline/code-inventory.md`: full categorized inventory of `src/`.

## [0.3.0] - 2026-11-20

### Added

- **`X-Conflict-Code` and the conflict code on every conflict answer** of the bundle's routes (REQ-RUNTIME-003 rule
  6): `CONFLICT_001` with `Retry-After` when nothing was committed, `CONFLICT_002` without `Retry-After` when part of
  the request was (`ConflictCode::forRequest()`, with the request's commit watermark). JSON answers gain a `code` key
  next to the existing `error`; a committed generic conflict's `error` is `concurrency_conflict_partial`. The stale
  version pages (settings, leave settings, correction decisions) send the header too. The CONFLICT_002 for an earlier commit in
  the request needs NowoTransactionDoctrineBundle with `commit_detection` (on by default).

### Changed

- **transaction-doctrine `^1.8.1`** (was `^1.3`), in the bundle and both demos. 1.8.0 fixes two security advisories
  (GHSA-9wh3-m3m5-jffx, GHSA-65w2-9r4h-848g) and keeps the host's entities managed across a write-unit replay (on the
  shared manager too); 1.8.1 fixes 1.8.0's replay of new entities that reference a host-managed entity.
- **Write units scoped to the private entity manager** (transaction-doctrine `forManagers()`, audit UI2-1): a deadlock
  replay clears or resets only the bundle's manager; the host's open default manager keeps its entities. Unchanged on
  a shared manager (`doctrine.entity_manager: null` or the default manager's name).
- **Host writes inside a unit are never replayed** (audits UZ-1, UZ2). Each attempt on the private manager watches
  every host entity manager for flushes:
  - A host flush that wrote, unflushed host inserts or changes, or a host manager that closed: the attempt is not
    replayed after a deadlock. The answer is a 409: `CONFLICT_002` only when a host flush committed on its own
    connection, otherwise `CONFLICT_001`.
  - The host manager is put back to what the database holds (audit UZ3-1): inserts the rollback removed are
    detached, rolled-back updates get their old values back in the entity and in Doctrine's original data, and
    rolled-back deletes are managed again. A retry writes the change for real.
  - The owners of rolled-back collection changes (many-to-many links, inverse one-to-many children, cascade-all
    ones included) and rolled-back versioned entities are reloaded from the database through their persister, which
    does not cascade. A retry writes again and no phantom child is inserted (UZ4-1 to UZ4-3, UZ5-1).
  - Each entity is restored on its own: a readonly generated id that cannot be reset still answers 409 (UZ4-4). An
    entity the host had before that a cascade detach reached is managed again and reloaded (UZ4-5).
  - Unflushed host work of the failed attempt is reverted: `remove()` cancelled, changed entities and owners of
    changed collections reloaded, `persist()` cancelled (UZ3-3, UZ5-2, UZ5-3). Orphan removals scheduled by
    `removeElement()` or `clear()` are cancelled first (UZ6-1, UZ7-1). Read-only entities are skipped (UZ6-3). After a
    rolled-back flush of a cleared inverse collection the owner is reloaded too (UZ7-2). An unflushed owning-side
    `clear()` is taken off Doctrine's scheduled collection deletions and the owner's collections go back to lazy, so
    the host's flush deletes no link and the retry's `clear()` deletes them for real (UZ8-1, UZ8-2). The orphan
    removals such a `clear()` queues on a many-to-many with `orphanRemoval` are taken off too (UZ9-1). Both fail
    closed with a 409 and a refusing start guard if Doctrine's internals change. The host's next flush
    writes none of it.
  - A flush inside the host's own transaction on another connection counts as committed (`CONFLICT_002`, UZ3-2).
    Nested host flushes are followed (UZ3-4).
  - Reads through a host manager still replay and detach nothing.
- **BC: on the private manager a write refuses to start while a host entity manager has unflushed changes**
  (`LogicException`, audit UZ2-3), as the shared-manager mode already did. Changes pending only in collections, and
  pending orphan removals, and a `clear()` of an inverse collection count too (UZ6-2, UZ7-3).
- **`ConflictFallbackListener` answers through transaction-doctrine's responder when it exists** (`respond()`: the
  branded HTML page and the bundle's JSON formatter), and logs one constant warning (route, method, code).
- **`ConflictFallbackListener` is always registered**, at `kernel.exception` priority 63 (after transaction-doctrine's
  responder): on the bundle's routes only, it answers every conflict when NowoTransactionDoctrineBundle is not
  registered, and the raw transient failures the responder leaves when it is.

### Fixed

- **Conflicts inside Messenger wrappers** are found (transaction-doctrine's bounded walk instead of a hand-written
  `getPrevious()` loop): a deadlock as the second wrapped exception of a synchronous `HandlerFailedException` answers
  409 instead of 500, and the bundle's own code (`settings_stale`, ...) is kept when its exception is wrapped. Unique
  violations and lost connections, wrapped or not, are still not conflicts.

## [0.2.0] - 2026-11-18

### Added

- **Holiday component** (S2, contract sections 6.7, 7 and 9.2; usable with the register and leave switched off):
  holiday calendars (national, regional, local; country, ISO region and INE municipality for the providers), days by
  hand, work centres (the calendars of an office; one default per tenant), CSV (`date,name,scope`) and ICS imports
  with a preview (`new` / `duplicate` / `other_scope` / `invalid`) and a confirm that checks every row again, and
  provider imports: **festivos.io** (Spain by INE municipality: national, regional and local days with the bulletin
  they cite; provisional years flagged; CC BY 4.0 attribution on every screen that shows the data) and **OpenHolidays**
  (country or region; multi-day holidays give one row per day). A provider runs only on an admin action (preview, then
  confirm, which asks the provider again and replaces only that provider's earlier days), through OutboundUrlGuard
  (no private targets, DNS pinned), with a timeout and no redirects, and is sent only the place and the year. Hosts add
  their own providers by tagging a `HolidayProviderInterface` (`nowo_working_time.holiday_provider`).
- `HolidayCalendarReaderInterface`: the stand-alone entry point for agendas (`holidaysBetween()`, `isHoliday()`,
  `holidayMap()` for workers whose work centre changes from day to day, `workCentres()`); `HolidayCalendarServiceInterface`
  (administration, `holidays_manage`); `MunicipalityDirectoryInterface` (festivos.io's town list: a search reads the
  cache only; loading the list is a button).
- Holiday pages under `ui.holidays_path` (`/holidays`): calendars, days, imports, providers, work centres and the town
  list (`GET /holidays/municipalities?query=`, UI JSON).
- **Leave foundations** (S2, contract sections 6.6 and 9.3; spec 204 FR-002 – FR-009): leave types (seeded when a
  tenant enables leave, editable, archivable; no field for any health detail), allowance policies (tenant → group →
  worker, the legal vacation minimum refused unless marked as the agreement's equivalent with a note), allowance lines
  (adjustments, carry-overs that expire on the type's date, protected carry-overs that last `protected_carry_months`),
  pro-rata by natural days employed with the tenant's rounding, hour allowances × the worker's `contractRatio`,
  balances (`LeaveBalanceReaderInterface`), the day counter (`Application\Leave\DayCounter`: natural days, working days
  of the dated schedule minus each day's work-centre holidays, hours capped at the scheduled day; spec 204 SC-001 fixture
  year at 100 %), leave settings (versioned; enabling needs the register and seeds the types), and
  `nowo:working-time:leave:daily` (expires carry-overs, one audit row per tenant).
- Leave pages under `ui.leave_path` (`/leave`: own balances; `/leave/manage`: types and allowances) and the leave
  settings at `ui.settings_path`/leave. The request workflow arrives with S3.
- **Register day annotations** (`RegisterDayAnnotationProviderInterface`, tagged `nowo_working_time.day_annotation_provider`):
  public holidays of the worker's calendars show on the month view, ask for no clock reminder and are no first working
  day for the monthly summary. The schedule form names the work centre of a row.
- Translations of the new pages in the ten shipped locales.

### Fixed

Audit TD on the S2 pull request (contention and input bounds; no security impact above Low, no advisory):

- **Racing seeds** no longer duplicate leave types or the vacation default (TD-M1): a unique key on
  `(tenant_id, seed_code)`, seeds looked up byte-exact over the tenant's own types, and the losing seed runs once more
  (a second refusal is a 409 + `Retry-After`, `leave_seed_conflict`).
- **Two default work centres** can no longer coexist (TD-M2): the switch is one `UPDATE … SET is_default = (id = :id)`
  over the tenant's centres.
- **A racing import confirm** (file or provider) is no longer a 500 (TD-L1): it runs once more and skips the dates the
  other confirm stored; a second refusal is a 409 + `Retry-After` (`holiday_import_conflict`).
- A carry-over expiry or a planning deadline on **29 February** is refused (it would never come in a common year;
  TD-L2).
- **Hostile provider answers** (TD-L3): response bodies are capped at 2 MiB (announced or received); an OpenHolidays
  holiday longer than 31 days, or ending before it starts, is skipped, and the walk starts at 1 January at the
  earliest; provider timeouts are 1–30 seconds in the configuration.
- **Host providers** keep their own days (TD-L4): the provider key is stored on each imported day, so confirming one
  host provider never removes another's.
- **Amounts are bounded** (TD-L5): a policy or a line holds at most 366 days or 2 080 hours, and an adjustment never
  takes the entitlement below zero. Nobody adds an allowance line or a worker policy for themselves.
- **Leave type and holiday calendar edits are versioned** (TD-L6): the row is locked `FOR UPDATE` in every write that
  depends on it (policies, lines, days, imports), and an edit of a newer version answers 409 + `Retry-After`
  (`leave_type_stale`, `holiday_calendar_stale`).
- **GDPR** (TD-L7): the allowance lines and the worker's own policies are in the personal data export (section
  `working_time_leave`, `LeavePersonalData`, UserKit bridge) and in the erasure (reasons and notes blanked, the worker
  forgotten as creator of other people's rows; amounts kept until retention).
- The allowance-line reason and the policy note carry the "Do not include health information" hint (TD-L8).
- A holiday file is refused by its size before it is read (TD-L9).
- `docs/CONCURRENCY.md`: the write paths and how each one behaves under contention.

### Changed

- `RegisterUnavailableException` and `PermissionDeniedException` now extend the new shared
  `Domain\Shared\Exception\NotAvailableException` (404) and `AccessDeniedException` (403); the register's
  `WorkingTimeExceptionInterface` extends `Domain\Shared\Exception\WorkingTimeException`. Catching the old classes
  works as before.
- `symfony/string` is a direct requirement (town search).
- The holiday component is on by default (`holidays.enabled: true`, as documented since 0.1.0): its tables join the
  schema and its pages are served when `ui.enabled` is on. Its routes need `holidays_manage`.

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
