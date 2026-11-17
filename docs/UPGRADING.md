# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 0.1.0](#to-010)

## Unreleased

From **0.1.0**:

```bash
composer update nowo-tech/working-time-bundle
```

No schema change and no migration: the verifier derives what it needs from the existing `first_kept_seq` and
`anchor_hash` columns of the chain-anchor table.

- **Heads files (`--heads-out` / `--heads`) have a new format** (audit ST-D1). Each chain is now
  `{"hash": "…", "seq": 12, "at": "2026-11-17T16:30:00Z"}` instead of a bare hash. A missing published head is
  accepted only when a purge anchor on that chain has `first_kept_seq` greater than the head's `seq`, or its
  `anchor_hash` is the head's hash. A 0.1.0 file (bare hashes) is **refused** with exit code 2: without the `seq`, a
  purge cannot be told apart from a removed tail, so accepting it would keep the weaker check.
  Write a new file once, right after a verification that passes:
  `bin/console nowo:working-time:verify --heads-out <new file>`, then use that file with `--heads`.
- `--publish-heads` records `{hash, seq, at}` in the `working_time.chain_head` audit entry; the `head` key is gone.
  Update any reader of those entries.
- `ChainVerifierInterface::verify()` takes `{hash, seq, at}` entries (as `VerificationReport::$headLinks`). A bare hash
  is still accepted there, but it is excused only by the anchor of the purge that removed exactly that link.
- A purge anchor whose `first_kept_seq` is past the chain's first link is now reported as `anchor_after_start`.
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
