# Usage

0.1 ships the **register** (contract S1): the working-hours record of art. 34.9 ET with a tamper-evident hash chain,
clocking, schedules, corrections, exports, notices, the retention purge and the GDPR register half. Holidays and leave
arrive with S2 / S3 ([ROADMAP.md](ROADMAP.md)). The bundle is **not legal advice**: the employer decides to use it as
their register.

## 1. Set it up

1. Install the bundle ([INSTALLATION.md](INSTALLATION.md)); register `NowoTransactionDoctrineBundle` too (write units,
   409 + `Retry-After` on a lock conflict). Without it the bundle uses a write unit of its own and answers conflicts on
   its routes with a small fallback listener.
2. Create the tables on the bundle's entity manager and install the append-only triggers:

   ```bash
   bin/console doctrine:schema:update --force --em=nowo_working_time   # or your migrations
   bin/console nowo:working-time:triggers:install                      # MySQL / MariaDB / PostgreSQL / SQLite
   bin/console nowo:working-time:triggers:check                        # exit 1 while one is missing
   ```

   MySQL / MariaDB with binary logging need `SUPER` or `log_bin_trust_function_creators = 1` to create triggers; the
   install exits 2 and leaves nothing half created. `integrity.triggers: require` refuses to turn the register on while
   triggers are missing (`warn`, the default, shows a warning on the settings page).
3. Implement the ports your host needs ([CONTRACT.md](CONTRACT.md) section 8) and point the configuration at them:
   `tenant_resolver`, `permission_checker`, `worker_directory` (the defaults are a single tenant, a role map that
   denies everything until roles are mapped, and a directory that only knows the signed-in user).
4. Opt in to the pages (`ui.enabled: true`) and import the routes:

   ```yaml
   # config/routes/nowo_working_time.yaml
   nowo_working_time_ui:
       resource: .
       type: nowo_working_time_ui
   ```

   The pages answer **404** without a signed-in member (the gate never reveals whether a register exists); put them
   behind your firewall so a guest gets your login redirect instead:

   ```yaml
   security:
       access_control:
           - { path: ^/working-time(/|$), roles: IS_AUTHENTICATED_FULLY }
   ```

   The pages extend `nowo_ui_layout.admin`: point `nowo_ui_kit.layouts.admin` at your layout to keep your navigation,
   and print `{{ working_time_header_clock() }}` in it for the one-button header clock.
5. A settings admin (`manage_settings`) turns the register on for the tenant at `/working-time/settings`.

## 2. Permissions (deny by default)

| Permission | Pages / services |
| --- | --- |
| `clock` | Today, month, day, clocking, own correction requests, answers to recorded corrections, own exports, preferences |
| `manage_team` (subject: the worker) | Team, a worker's day, decisions on correction requests, recorded corrections, schedules |
| `export` | Inspection exports of a worker or the team (ZIP) |
| `manage_settings` | Register settings (open while the register is off) |

No tenant, the register off, nobody signed in, not an active member, or another tenant's row: **404**. A missing
permission: **403**. Nobody decides or records a correction on their own day.

## 3. Services

```php
use Nowo\WorkingTimeBundle\Domain\Register\Enum\{ClockAction, SegmentSource};
use Nowo\WorkingTimeBundle\Domain\Register\Service\{RegisterClockInterface, CorrectionServiceInterface};
use Nowo\WorkingTimeBundle\Domain\Shared\RequestKey;

$state = $clock->act(null, ClockAction::ClockIn, null, RequestKey::web($key), SegmentSource::Web, $request->getClientIp());
// a repeated key answers the state without acting again; ClockConflictException (409) carries the state

$correction = $corrections->decide($id, approve: true, comment: null, expectedVersion: $versionShown);
// a stale version: StaleCorrectionDecisionException (409 + Retry-After, nothing stored, nobody notified)
```

Public interfaces: `RegisterClockInterface`, `RegisterReaderInterface`, `CorrectionServiceInterface`,
`ScheduleServiceInterface`, `RegisterSettingsEditorInterface`, `RegisterExportServiceInterface`,
`ChainVerifierInterface` (namespace `Nowo\WorkingTimeBundle\Domain\Register\Service`), plus
`Application\Register\RegisterPersonalData` (GDPR export / erasure) and `WorkerPreferences`.

## 4. Jobs (scheduler or cron)

| Command | When | Exit codes |
| --- | --- | --- |
| `nowo:working-time:notices` | every 5 minutes | 1 when a notice could not be delivered (it stays in the outbox) |
| `nowo:working-time:purge [--dry-run]` | daily | 0 done, 1 error, 2 the triggers refused the deletes (guard misconfigured) |
| `nowo:working-time:verify [--tenant] [--worker] [--heads=<file>] [--heads-out=<file>] [--publish-heads]` | weekly | 1 on a broken chain (audited, `ChainBroken` event), 2 for an unreadable heads file; publish or keep the heads outside the database (SECURITY.md) |

## 5. Integrity

Every clock action, approved or recorded correction and answer is a link of the worker's chain:
`hash = sha256(prev_hash ‖ canonical JSON)`. Format 1 is Beacon's (migrated rows, never re-hashed), format 2 the
bundle's; the first bundle link continues from the last Beacon hash. The purge deletes whole old months in batches and
writes each chain's anchor (hash of the last deleted link) in the same transaction; the verifier accepts a chain start
only at the genesis or a recorded anchor. Database triggers refuse UPDATE / DELETE of the record (one close of an open
segment is the only update), TRUNCATE on PostgreSQL, and let deletes through only inside the purge (MySQL / MariaDB
session variable cleared in `finally` and on every connect, PostgreSQL `SET LOCAL`).

## 6. Bridges

- **TimeTrack** (`nowo-tech/time-track-bundle`): set `nowo_time_track.client_api.workday_provider` to
  `nowo_working_time.time_track.workday_source`; the desktop app and the extension clock through the same rules. With
  `register.task_timer_link: auto` clocking out (or a break) stops the running TimeTrack timer.
- **UserKit**: the `working_time` export section and eraser are tagged automatically; the register is kept on erasure
  (GDPR art. 17.3.b), preferences and export files are deleted. Implement `Bridge\UserKit\WorkerOfUserInterface` when
  your worker refs are not user identifiers.
- **Dompdf** (`dompdf/dompdf`): closed PDF rendering (no remote resources, no JavaScript, no PHP).
