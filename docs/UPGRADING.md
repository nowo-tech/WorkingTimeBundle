# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 0.1.2](#to-012)
- [To 0.1.1](#to-011)
- [To 0.1.0](#to-010)

## Unreleased

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
