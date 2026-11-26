# Usage

0.1 ships the **register** (contract S1): the working-hours record of art. 34.9 ET with a tamper-evident hash chain,
clocking, schedules, corrections, exports, notices, the retention purge and the GDPR register half. 0.2 adds the
**holiday** component and the **leave foundations** (types, allowances, balances); the leave request workflow arrives
with S3 ([ROADMAP.md](ROADMAP.md)). The bundle is **not legal advice**: the employer decides to use it as
their register.

## Table of contents

- [1. Set it up](#1-set-it-up)
- [2. Permissions (deny by default)](#2-permissions-deny-by-default)
- [3. Services](#3-services)
  - [Holidays (stand-alone) and leave foundations (0.2)](#holidays-stand-alone-and-leave-foundations-02)
- [4. Jobs (scheduler or cron)](#4-jobs-scheduler-or-cron)
  - [Register exports through Messenger (retry and failure transport)](#register-exports-through-messenger-retry-and-failure-transport)
- [5. Integrity](#5-integrity)
- [6. Bridges](#6-bridges)
- [7. Overriding templates and translations](#7-overriding-templates-and-translations)
  - [Templates](#templates)
  - [Translations](#translations)

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
| `manage_settings` | Register settings (open while the register is off) and leave settings (open while leave is off) |
| `holidays_manage` | Holiday calendars, days, imports, providers, work centres, the town list |
| `leave_manage` | Leave types (seed, edit, archive), allowance policies and lines, a worker's balances |
| `leave_request` | Own leave balances (requests from S3) |

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

### Holidays (stand-alone) and leave foundations (0.2)

```php
use Nowo\WorkingTimeBundle\Domain\Holiday\Service\HolidayCalendarReaderInterface;
use Nowo\WorkingTimeBundle\Domain\Holiday\WorkCentreId;
use Nowo\WorkingTimeBundle\Domain\Shared\{LocalDate, TenantRef};

// An agenda without the register or leave: the public holidays of a work centre (null: the tenant's default centre,
// else every calendar of the tenant).
$days = $holidays->holidaysBetween(new TenantRef($org), new WorkCentreId($centre), new LocalDate('2026-01-01'), new LocalDate('2026-12-31'));
$off  = $holidays->isHoliday(new TenantRef($org), null, new LocalDate('2026-05-15'));

// Leave: a worker's balances of a year (entitled, taken, planned, pending, remaining in stored units).
$balances = $leaveBalances->balances(new TenantRef($org), new WorkerRef($user), 2026);

// The day counter (spec 204 FR-009): working days of the dated schedule minus each day's holidays.
$count = $dayCounter->count($registerSettings, $workerProfile, LeaveUnit::WorkingDays, new LocalDate('2026-08-03'), new LocalDate('2026-08-14'));
```

Imports: on a calendar page, *Import from festivos.io* needs the country `ES` and the town's INE code (search the town
list once it is loaded), *Import from OpenHolidays* a country and, for a regional calendar, its ISO region (`ES-MD`);
both show a preview first and ask the provider again on confirm. A CSV has a `date,name[,scope]` header. A custom
provider implements `Domain\Port\HolidayProviderInterface` and is tagged `nowo_working_time.holiday_provider`.

## 4. Jobs (scheduler or cron)

| Command | When | Exit codes |
| --- | --- | --- |
| `nowo:working-time:leave:daily` | daily (leave on) | 0; marks the carry-overs past their expiry, one audit row per tenant |
| `nowo:working-time:notices` | every 5 minutes | 1 when a notice could not be delivered (it stays in the outbox) |
| `nowo:working-time:purge [--dry-run]` | daily | 0 done, 1 error, 2 the triggers refused the deletes (guard misconfigured) |
| `nowo:working-time:verify [--tenant] [--worker] [--heads=<file>] [--heads-out=<file>] [--publish-heads]` | weekly | 1 on a broken chain (audited, `ChainBroken` event), 2 for an unreadable or 0.1.0 (hash-only) heads file; `--heads-out` writes `{hash, seq, at}` per chain; publish or keep the heads outside the database (SECURITY.md) |

### Register exports through Messenger (retry and failure transport)

With `register.export.async: messenger` (or `auto` and Messenger enabled) `GenerateRegisterExportHandler` is registered
through `#[AsMessageHandler]` (autoconfiguration; nothing to tag by hand) and handles `GenerateRegisterExportMessage`
(the export id only, no personal data in the payload). Route the message to a transport in the host:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        failure_transport: failed
        transports:
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                retry_strategy: { max_retries: 3, delay: 1000, multiplier: 2 }
            failed: 'doctrine://default?queue_name=failed'
        routing:
            'Nowo\WorkingTimeBundle\Infrastructure\Symfony\Messenger\GenerateRegisterExportMessage': async
```

- A delivery claims the export in one write unit; a second delivery of the same message (at-least-once transports) finds
  it claimed and does nothing, so retries and duplicates never build the file twice.
- A failure while building (data, renderer, storage) is caught by the generator: the export is marked failed with the
  exception class name, a log line is written and the handler returns normally, so Messenger does **not** retry it; the
  user asks for a new export.
- Infrastructure errors before the claim (database down, lock conflict) propagate: the transport's `retry_strategy`
  applies and, once exhausted, the message lands in the `failure_transport` (`messenger:failed:retry` re-delivers it).
- The handler keeps no state between messages, so it is safe in FrankenPHP worker mode with the kernel reset off.

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

## 7. Overriding templates and translations

### Templates

The templates live under the Twig namespace `@NowoWorkingTimeBundle` (the same string as the translation domain). To
change one, copy it from `vendor/nowo-tech/working-time-bundle/src/Resources/views/<subpath>` to
`templates/bundles/NowoWorkingTimeBundle/<subpath>` in the application; the host copy wins and the rest of the bundle
keeps upgrading. The subpaths:

- `email/notice.html.twig`
- `export/register_pdf.html.twig`
- `holidays/_form.html.twig`
- `holidays/calendar.html.twig`
- `holidays/centre.html.twig`
- `holidays/day.html.twig`
- `holidays/index.html.twig`
- `holidays/preview.html.twig`
- `leave/allowances.html.twig`
- `leave/balances.html.twig`
- `leave/settings.html.twig`
- `leave/type.html.twig`
- `leave/types.html.twig`
- `register/_correction_form.html.twig`
- `register/_header_clock.html.twig`
- `register/day.html.twig`
- `register/exports.html.twig`
- `register/month.html.twig`
- `register/preferences.html.twig`
- `register/schedules.html.twig`
- `register/settings.html.twig`
- `register/team.html.twig`
- `register/team_day.html.twig`
- `register/today.html.twig`

Procedure:

1. Copy only the file you need (a partial such as `register/_correction_form.html.twig`, or one page); keep the Twig
   blocks and the `{ form_start() }` / `{ form_row() }` loop so the forms keep working.
2. Clear the cache (`php bin/console cache:clear`) when the directory did not exist before: the override directory is
   registered at container build time.
3. Check `php bin/console debug:twig` lists the `@NowoWorkingTimeBundle` namespace with the `templates/bundles/...`
   path first.
4. Trade-off: a copied file is frozen. After each bundle upgrade compare it with the vendor version (the CHANGELOG
   names changed templates). Prefer the `web_ui` configuration and CSS on the `nowo-ui-*` classes when they are enough.

The automatic Symfony namespace `@NowoWorkingTime` still resolves to the same files (BC alias); new code and
overrides use `@NowoWorkingTimeBundle`. See [UPGRADING.md](UPGRADING.md).

### Translations

Every string is in the domain `NowoWorkingTimeBundle` (locales en, es, ca, eu, gl, fr, pt, de, it, nl). To change a
text, create `translations/NowoWorkingTimeBundle.<locale>.yaml` in the application with only the keys you change (the
application file wins over the bundle's `src/Resources/translations/NowoWorkingTimeBundle.<locale>.yaml`); to add a
locale, add a file with every key of the English one. Clear the cache after adding a new file.

```yaml
# translations/NowoWorkingTimeBundle.en.yaml
# copy the key from vendor/nowo-tech/working-time-bundle/src/Resources/translations/NowoWorkingTimeBundle.en.yaml
```
