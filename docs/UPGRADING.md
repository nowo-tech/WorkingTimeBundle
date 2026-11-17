# Upgrading

## Table of contents

- [Unreleased](#unreleased)

## Unreleased

First release (0.1.0, the register). After installing: create the tables on the bundle's entity manager, run
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
