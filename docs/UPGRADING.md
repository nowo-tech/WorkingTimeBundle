# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 0.2.0](#to-020)
- [To 0.1.2](#to-012)
- [To 0.1.1](#to-011)
- [To 0.1.0](#to-010)

## Unreleased

From **0.2.0**:

```bash
composer update nowo-tech/working-time-bundle nowo-tech/transaction-doctrine
```

- **transaction-doctrine `^1.8`** is required (was `^1.3`; 1.8.0 fixes GHSA-9wh3-m3m5-jffx and GHSA-65w2-9r4h-848g).
  On the shared manager (`doctrine.entity_manager: null`) a deadlock replay no longer clears the host's manager: its
  entities stay managed and are reloaded when the failed attempt touched them. Read its UPGRADING from your locked
  version; nothing in the bundle's configuration changes.
- **Conflict answers carry `X-Conflict-Code`** (`CONFLICT_001` or `CONFLICT_002`) and, in JSON, a `code` key next to
  `error` (additive). After a commit earlier in the same request (or a Messenger handler that already succeeded) the
  answer is `CONFLICT_002` without `Retry-After`; a generic conflict's `error` is then `concurrency_conflict_partial`.
  Clients should key on `code` or the header. Cross-origin clients need `X-Conflict-Code` in
  `Access-Control-Expose-Headers`. The status follows the policy as before (`conflict_response.status: 503` still
  works).
- **A replay no longer clears the host's entity manager** on the private manager (the default): a deadlock replay of
  the bundle's unit clears only `nowo_working_time`. Host ports called inside a unit (audit sink, worker directory)
  must not write through a host manager (audits UZ-1, UZ2). After a deadlock in an attempt:
  - reads through a host manager are still replayed;
  - a host flush that wrote, unflushed host inserts or changes, or a host manager that closed: the attempt is not
    replayed and the answer is a 409. It is `CONFLICT_002` when a host flush ran on another connection (committed,
    or inside the host's own transaction there), otherwise `CONFLICT_001` with `Retry-After`.
  - After a rollback on the bundle's connection the host manager is put back to the database: rolled-back inserts
    are detached (generated ids reset), rolled-back updates get their old values back, and rolled-back deletes are
    managed again. Owners of rolled-back collection changes and rolled-back versioned entities are reloaded through
    their persister.
  - Host changes the failed attempt made and never flushed are reverted, not kept for the host's next flush:
    `remove()` and `persist()` are cancelled, changed entities are reloaded. With the `DEFERRED_EXPLICIT`
    change-tracking policy this includes changes never passed to `persist()`. See CONCURRENCY.md, residual risks.

  See CONCURRENCY.md, "Replays and the host's entity manager". The host-write probe does not run with
  `doctrine.entity_manager: null`.
- **BC: a write on the private manager now refuses to start while a host entity manager has unflushed changes,
  including changes pending only in collections, pending orphan removals and a `clear()` of an inverse
  collection**
  (audit UZ2-3; `LogicException` naming the manager). Before, a host port flushing that work inside the unit could
  lose it silently on a rollback. Flush (or clear) the host's work before calling the register; the shared-manager
  mode has always refused this.
- **`CONFLICT_002` for an earlier commit needs NowoTransactionDoctrineBundle with `commit_detection`** (on by
  default). Without it, those cases answer `CONFLICT_001`.
- **`ConflictFallbackListener` is registered even with NowoTransactionDoctrineBundle** (priority 63, after its
  responder). It answers only the bundle's routes, so host routes are untouched; on them a raw deadlock that reached
  the kernel answers 409 instead of 500. A host that removed the listener's definition by id should keep doing so only
  if it answers these failures itself.
- **`UiSupport::conflict()`** now takes the request and the failure (`conflict(Request, Throwable)`); it is an internal
  controller helper.

## To 0.2.0

From **0.1.2**:

```bash
composer update nowo-tech/working-time-bundle
```

From **0.1.2** (0.2.0, S2: holidays and leave foundations):

```bash
composer update nowo-tech/working-time-bundle
php bin/console doctrine:schema:update --dump-sql --em=nowo_working_time   # or doctrine:migrations:diff
```

- **New tables** (prefix `doctrine.table_prefix`, default `nowo_`): `holiday_calendar`, `holiday_day`,
  `holiday_work_centre`, `working_time_leave_type`, `working_time_leave_policy`, `working_time_leave_allowance_line`,
  `working_time_leave_settings`. They are created whether or not the parts are on (the mappings are always registered);
  the reference and identifier columns get the binary NO PAD collation on MySQL / MariaDB through the metadata listener
  as before. No change to the register tables or their triggers.
- **Columns added by the audit TD round** (same release): `working_time_leave_type.version` and the unique key
  `wt_leave_type_seed` on `(tenant_id, seed_code)` (`seed_code` gets the binary NO PAD collation on MySQL / MariaDB),
  `holiday_calendar.version`, `holiday_day.provider`.
- **Holidays are on by default** (`holidays.enabled: true`). With `ui.enabled` the pages are served under
  `ui.holidays_path` (`/holidays`) to holders of `holidays_manage` (map a role: `permissions.roles.holidays_manage`).
  Set `holidays.enabled: false` to keep them off.
- **Outbound guard and cache**: provider calls go through `holidays.outbound_guard` (default
  `Nowo\OutboundUrlGuardBundle\Guard\RuntimeOutboundUrlGuard`; without NowoOutboundUrlGuardBundle registered the bundle
  builds the same policy itself) and cache the town list and the OpenHolidays regions in `holidays.cache_pool`
  (default `cache.app`; use a shared pool in production so every worker sees the loaded list). A missing pool fails the
  build with the fix.
- **Leave** stays off (`leave.enabled: false`). Turning it on needs `register.enabled` and `holidays.enabled`; a tenant
  then enables it on the leave settings page (`manage_settings`), which seeds the default types and the vacation
  allowance of the current year (22 working days).
- **Beacon migration note** (contract section 18.2): Beacon's holiday scope `autonomous` is `regional` here; holiday
  origins keep Beacon's values (`manual`, `file`, `festivos_io`, `openholidays`); amounts keep Beacon's stored units
  (half-days, minutes).
- `HolidayCalendarServiceInterface::saveCalendar()` and `LeaveAdministrationInterface::saveType()` take an optional
  `$expectedVersion` (the version the form showed); a newer version answers `WriteConflictException` (409). The
  storage ports `HolidayStoreInterface` / `LeaveStoreInterface` (application ports, implemented by the bundle) gained
  methods.
- `RegisterController` (internal) takes the `RegisterAnnotations` service; hosts that build it by hand pass it.

## To 0.1.2

From **0.1.1**:

```bash
composer update nowo-tech/working-time-bundle
```

From **0.1.1**: no schema change and no migration.

- Heads (`--heads-out`, `--publish-heads`, the exports' `chain_heads`) now carry `day`, the head link's workday date:
  `{"hash": "…", "seq": 12, "at": "2026-11-17T16:30:00Z", "day": "2026-11-17"}`. A missing published head is excused
  only when its `day` is before the `first_kept_date` of the anchor at the chain's start. **Regenerate your heads
  files with the 0.1.2 `--heads-out`** after upgrading (right after a verification that passes) and store them
  write-once outside the database: entries without `day` (0.1.1 heads files and exports) keep the weaker 0.1.1 rule
  (`seq` below the start), which a forged anchor at the new start can satisfy.
- `--heads` refuses (exit 2) an entry with `seq` below 1 (other than the genesis with `seq` 0), an `at` that is not
  exactly `Y-m-d\TH:i:s\Z`, or a `day` that is not a real `Y-m-d` date.
- `ChainVerifierInterface::verify()` accepts `day` in each entry (as `VerificationReport::$headLinks`); pass it.
- `publishHeads()` records `{hash, seq, at, day}` in the `working_time.chain_head` audit entry.

## To 0.1.1

From **0.1.0**:

```bash
composer update nowo-tech/working-time-bundle
```

From **0.1.0**:

```bash
composer update nowo-tech/working-time-bundle
```

No schema change and no migration: the verifier derives the chain start from the existing `first_kept_seq` column of
the chain-anchor table, and the export `chain_heads` JSON column now holds `{hash, seq}` per worker.

- **Heads files (`--heads-out` / `--heads`) have a new format** (audit ST-D1). Each chain is now
  `{"hash": "…", "seq": 12, "at": "2026-11-17T16:30:00Z"}` instead of a bare hash. A missing published head is
  accepted only when its `seq` is below the chain's start (the first link, or the last anchor's `first_kept_seq` for
  a chain the purge emptied). A 0.1.0 file (bare hashes) is **refused** with exit code 2: without the `seq`, a
  purge cannot be told apart from a removed tail, so accepting it would keep the weaker check.
  Write a new file once, right after a verification that passes:
  `bin/console nowo:working-time:verify --heads-out <new file>`, then use that file with `--heads`.
- `--publish-heads` records `{hash, seq, at}` in the `working_time.chain_head` audit entry; the `head` key is gone.
  Update any reader of those entries.
- `ChainVerifierInterface::verify()` takes `{hash, seq, at}` entries (as `VerificationReport::$headLinks`). A bare
  hash is still accepted there for 0.1.0 callers, under the **weaker** 0.1.0 rule: an anchor carrying that hash excuses
  it, whatever its position. Pass the `seq` to get the full check.
- Exports made from now on store `{hash, seq}` per worker in `chain_heads`. Rows written by 0.1.0 keep their bare hash
  and the weaker 0.1.0 rule (an anchor carrying the hash, or a purge after the export, excuses a missing head). Make a
  fresh export, or keep a `--heads-out` file, to get the full check for those chains.
- A purge anchor whose `first_kept_seq` is past the chain's first link is now reported as `anchor_after_start`, and one
  with `first_kept_seq` below 2 as `anchor_seq_invalid`. A real purge never writes either.
- A published head equal to the genesis hash is accepted only with `seq` 0 (the chain was empty).
- `doctrine.entity_manager` set to the default manager's name (for example `default`) now gets the same single
  entity manager safeguards as `null`: only the bundle's entities are released, and a write while the host has pending
  changes is refused. Flush your own work first, as with `null`.

## To 0.1.0

First release (0.1.0, the register); there is no earlier version to upgrade from. Install it with:

```bash
composer require nowo-tech/working-time-bundle
```

After installing: create the tables on the bundle's entity manager, run
`bin/console nowo:working-time:triggers:install` (the database user needs the TRIGGER privilege; with MySQL binary
logging also `SUPER` or `log_bin_trust_function_creators = 1`), map the permission roles or configure your
permission checker (everything is denied by default), and turn the register on per tenant in the settings page.
`register.export.formats` containing `pdf` needs `dompdf/dompdf`.

Before you rely on the register for an inspection, read [SECURITY.md](SECURITY.md#integrity-what-the-bundle-guarantees-and-what-it-cannot):

- Install the schema and the triggers with a separate schema owner; the application role gets no `TRIGGER`, `ALTER`
  or `DROP` grant and does not own the tables.
- Prefer `integrity.purge_guard: db_user`. It needs `purge_db_user` and a dedicated DBAL connection
  `purge_connection` for that user. On MySQL / MariaDB the triggers compare `SUBSTRING_INDEX(USER(), '@', 1)`.
- Publish chain heads outside the database: `nowo:working-time:verify --publish-heads` or `--heads-out <file>`.
  Verify against them with `--heads <file>`.

Other notes:

- Instants are stored in UTC through the `nowo_wt_utc_datetime` DBAL type, which the bundle registers.
- The chain-anchor table has a `first_kept_date` column (the retention boundary recorded by the purge).
- Reminders and notices for days without scheduled ranges use `register.reminders.default_hours` (`09:00-18:00`,
  weekdays).
- With `doctrine.entity_manager: null`, the register refuses to write while the host's entity manager holds pending
  changes. Flush your own work first.
