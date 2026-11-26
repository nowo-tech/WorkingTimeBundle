# Feature Specification: WorkingTimeBundle baseline

**Feature Branch**: `001-baseline`
**Created**: 2026-11-18
**Status**: Baseline of v0.4.0 (S0 scaffold, S1 register 0.1, S2 holidays and leave foundations 0.2, concurrency and
layer hardening 0.3 to 0.4). Deep review 2026-11-26: every file of `src/` read and mapped (REQ-SPECKIT-003).
**Input**: Contract v1.0 ([`docs/CONTRACT.md`](../../docs/CONTRACT.md)), owner (Q-O) and Beacon (Q-B) answers of
2026-11-18, the released code up to v0.4.0.

Related: [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md),
[`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md), [`docs/USAGE.md`](../../docs/USAGE.md),
[`docs/ROADMAP.md`](../../docs/ROADMAP.md), [`code-inventory.md`](code-inventory.md).

## Summary

One bundle, three parts that can be enabled separately under the configuration root `nowo_working_time`:

- the working-hours **register** (art. 34.9 ET): clocking into work and break segments, dated schedules, corrections
  decided by a manager, an append-only revision table protected by database triggers and a SHA-256 hash chain, a
  verifier, CSV / PDF / ZIP exports, notices and a retention purge;
- the **holiday** component, usable alone: calendars, days, work centres, CSV / ICS and provider imports, and a reader
  for agendas;
- **leave foundations**: leave types, allowance policies and lines, carry-over, balances and the day counter (the
  request workflow is S3 and not part of this baseline).

The bundle keeps its rows on a private entity manager (`nowo_working_time`, declared by the Flex recipe), never
flushes the host's unit of work, runs every write in a transaction-doctrine write unit, denies by default through the
host's permission checker, works in FrankenPHP worker mode without kernel reset, and offers an opt-in UiKit + FormKit
UI whose routes come from one route loader the host imports.

## User scenarios

### US-01 - Install and configure (P1)

- **Given** a host with the multi-entity-manager Doctrine form, **when** the bundle is registered without
  configuration, **then** the container builds with the contract defaults (register and holidays on, leave off).
- **Given** an unknown key, a `leave` part without `register` or `holidays`, a retention below 4 years, a
  `lock_factory` store without its service, a PDF switch turned on, or `export.async: messenger` without Messenger,
  **when** the container builds, **then** it fails and names the path.
- **Given** `doctrine.entity_manager: nowo_working_time` (default), **then** `nowo_working_time.entity_manager` is that
  manager and never the host's default one; **given** `entity_manager: null`, **then** the default manager is used
  with explicit transactions and the host's pending changes are never flushed or cleared.

### US-02 - Clock in and out (P1)

- **Given** a worker allowed to clock, **when** they send start, pause, resume and end with request keys, **then** the
  day holds work and break segments, each change appends one revision to the worker's chain, and resending the same
  request key returns the first result without a second write.
- **Given** two clock requests for the same worker at the same moment, **then** one wins and the other replays or
  answers 409 with `Retry-After` and the conflict code; nothing is half-written.

### US-03 - Correct a day (P1)

- **Given** a correction request inside the window, **when** a manager accepts it on the version shown, **then** a new
  revision is appended and the original row is never edited; **given** the version changed in between, **then** the
  decision is refused (409, nothing stored, no notice).

### US-04 - Prove integrity (P1)

- **Given** the register tables, **then** UPDATE and DELETE are refused by triggers on MySQL, MariaDB, PostgreSQL and
  SQLite; `nowo:working-time:triggers:check` exits 1 while they are missing or disabled.
- **Given** a changed, missing or unanchored link, **when** `nowo:working-time:verify` runs, **then** it reports the
  first broken link and exits 1.
- **Given** rows older than the retention, **when** `nowo:working-time:purge` runs, **then** an anchor is written first
  and only those rows go, under a guard lifted for that statement alone.

### US-05 - Export and notify (P2)

- **Given** a worker or an inspector with the export permission, **when** they ask for a CSV, PDF or team ZIP,
  **then** the chain is verified first, the file is built from the stored rows (closed PDF renderer) and recorded with
  a status; with Messenger the generation is claimed atomically so a redelivery never writes it twice.
- **Given** reminders and the monthly summary, **then** notices go through the outbox and the notifier port inside
  the recipient's hours, and an unsent notice is retried.

### US-06 - Holidays (P2)

- **Given** an admin with `holidays_manage`, **when** they import a CSV, an ICS file or a provider year (festivos.io,
  OpenHolidays), **then** a preview classifies every row and the confirm checks every row again; a provider is called
  only on that admin action, through the outbound guard.
- **Given** an agenda, **when** it asks the reader for a place and a range, **then** it gets the holidays of the
  work centre's calendars, also with the register and leave switched off.

### US-07 - Leave balances (P2)

- **Given** leave enabled for a tenant, **then** the default leave types are seeded with translated names, allowance
  policies resolve tenant → group → worker, the legal vacation minimum is refused unless marked as the agreement's
  equivalent, and balances count working days minus holidays.
- **Given** `nowo:working-time:leave:daily` runs twice on one day, **then** carry-overs expire once.

### US-08 - Personal data and bridges (P3)

- **Given** UserKit, **when** a user's data is exported or erased, **then** the register is kept for its legal period
  and anonymised on erasure; TimeTrack and Dompdf bridges are active only when their package is installed.

### US-09 - Layer rules (P3)

- **Given** the source tree, **then** `Holiday` imports nothing from `Register` or `Leave`, `Register` imports nothing
  from `Leave`, and `Domain` / `Application` import no Symfony, Doctrine or HTTP class (Deptrac and the architecture
  test).

## Requirements

Requirements are grouped by the section names used in [`code-inventory.md`](code-inventory.md).

### Bundle and DI

- **FR-CONFIG-001**: strict tree, defaults of contract section 10, parameters `nowo_working_time.<path>`.
- **FR-PARTS-001**: part toggles `register.enabled`, `holidays.enabled`, `leave.enabled`; leave needs the other two.
- **FR-EM-001**: private entity manager, alias `nowo_working_time.entity_manager`, checked at container build.
- **FR-LOCK-001**: `leave.lock.store` is `db_row` (default) or `lock_factory` (then `lock_factory` is required).
- **FR-PDF-001**: closed PDF rendering, `remote_resources`, `javascript` and `php` cannot be enabled (REQ-REPORT-001).
- **FR-ARCH-001**: layer dependency rules (REQ-ARCH-001, REQ-ARCH-002), enforced over the whole tree by `deptrac.yaml` and `tests/Architecture/LayerDependencyTest.php` (no file of its own in the inventory).

### Register (clocking, schedules, corrections, settings, reading)

- **FR-REG-001**: clocking (`start`, `pause`, `resume`, `end`) appends work segments to the worker's day; every action carries a request key (replay returns the same result) and runs inside one write unit under the chain lock.
- **FR-REG-002**: schedules (definition, assignment by scope, per-day resolution, configured fallback) feed the expected hours of a day.
- **FR-REG-003**: corrections are requests that a manager decides; an accepted one appends a revision and never edits the original row; a stale decision is refused.
- **FR-REG-004**: register settings (retention, tolerance, notices, versioned with a hidden version field) and per-worker preferences.
- **FR-REG-005**: read side: day records, month summaries, clock state, annotations (holidays, leave) and reports, computed from the stored rows only.

### Hash chain and retention

- **FR-CHAIN-001**: every revision joins a tamper-evident hash chain (the bundle's format and Beacon's); the verifier reports the first broken link; purge anchors keep the chain verifiable after a purge.
- **FR-PURGE-001**: the retention purge removes only rows older than the retention (at least 4 years), writes an anchor first and refuses when the database trigger guard cannot be lifted for that statement alone.
- **FR-NOTICE-001**: working-hours notices go through an outbox and the notifier port; an unsent notice is retried, never lost.

### Holidays

- **FR-HOL-001**: holiday calendars (national, regional, local), days by hand and work centres (one default per tenant); the reader gives agendas the days of a place and a range.
- **FR-HOL-002**: CSV, ICS and provider imports (festivos.io, OpenHolidays) show a preview and confirm re-checks every row; providers run only on an admin action through the outbound guard.

### Leave foundations

- **FR-LEAVE-001**: leave types, allowance policies and lines, leave settings (versioned), default type seeding with translated names; no health detail anywhere.
- **FR-LEAVE-002**: the day counter (working days minus holidays), entitlements, carry-over and balances.
- **FR-LEAVE-003**: the daily command expires carry-over once per day and is safe to run twice.

### Ports, access and personal data

- **FR-PORT-001**: every host-implemented port (tenant, workers, permissions, notifier, audit sink, ids, files, holiday providers, PDF renderer) has a safe default; `WorkingTimePortsPass` selects it at container build.
- **FR-ACCESS-001**: permissions are deny by default and checked in the application layer for every action; a missing permission is a refusal, never a silent empty result.
- **FR-BRIDGE-001**: the TimeTrack and Dompdf bridges are enabled only when their package is installed.
- **FR-BRIDGE-002**: the UserKit bridge gives the worker-of-user mapping and the personal-data export and erasure providers.
- **FR-GDPR-001**: export and erasure of personal data respect retention (the register is kept for its legal period, anonymised on erasure).

### Storage, write units and concurrency

- **FR-DB-001**: XML mappings for the register, holiday and leave parts on the private manager; stores are Doctrine adapters of the application ports; UTC datetime type and binary collation for hashes.
- **FR-DB-002**: database triggers (MySQL, MariaDB, PostgreSQL, SQLite) make the register append-only; the installer and inspector are idempotent and a command reports their state.
- **FR-TXN-001**: every write path runs in a transaction-doctrine write unit; domain events are released only after commit.
- **FR-TXN-002**: lock conflicts answer 409 with `Retry-After`; stale rows, duplicate rows and busy chains are named exceptions with named JSON codes.
- **FR-WORKER-001**: worker mode with kernel reset false: no per-request state in services, the entity manager hygiene listener clears only the bundle manager, memo caches are keyed by request.

### UI, forms, console, exports

- **FR-UI-001**: opt-in UI (`ui.enabled`) on UiKit; routes come from the route loader, controllers are tagged explicitly, every form has a CSRF token.
- **FR-FORM-001**: every form uses FormKit with profile `working_time`, domain `NowoWorkingTimeBundle`, `form_start`/`form_row`/`form_end` only.
- **FR-CLI-001**: console commands (verify, purge, triggers, notices, leave daily) return non-zero on failure and print one line per outcome.
- **FR-EXPORT-001**: register exports (CSV and PDF) are generated from the stored rows and recorded with a status; the PDF is rendered by a closed renderer.
- **FR-EXPORT-002**: asynchronous generation (Messenger or in line) claims the export atomically so a retry never writes it twice.

### Twig, translations, shared types, errors

- **FR-TWIG-001**: templates are in the Twig namespace `@NowoWorkingTimeBundle` (same string as the translation domain); host overrides go in `templates/bundles/NowoWorkingTimeBundle/`; the automatic `@NowoWorkingTime` alias still resolves.
- **FR-I18N-001**: every string is in the domain `NowoWorkingTimeBundle` for ten locales; a host overrides with `translations/NowoWorkingTimeBundle.<locale>.yaml`.
- **FR-SHARED-001**: shared value objects (tenant, worker, actor, group, local date, request key, ids) are immutable and framework-free.
- **FR-ERR-001**: refusals, access denials and unavailability are typed exceptions under one interface; the UI turns them into a message, never a stack trace.

## Key entities

- **Workday**, **WorkSegment**, **WorkdayRevision** (append-only chain link), **ChainAnchor**: the register of one
  worker and day.
- **CorrectionRequest**, **ScheduleDefinition** / **ScheduleAssignment** (resolved to a **DaySchedule**),
  **RegisterSettings** with its **RetentionPolicy**, **WorkerPreference**, **RegisterExport** / **RegisterExportFile**,
  **RegisterNotice** (the notices outbox).
- **HolidayCalendar**, **HolidayDay**, **WorkCentre**.
- **LeaveType**, **AllowancePolicy**, **AllowanceLine**, **LeaveSettings**.
- Shared references: tenant, worker, actor and group references, local dates and request keys (opaque, framework-free).

## Success criteria

- **SC-001**: `find src -type f | wc -l` equals the mapped count in [`code-inventory.md`](code-inventory.md)
  (**339/339** on 2026-11-26), every row says what the file does and carries a requirement ID defined here, and no
  generic `FR-SRC-*` ID is used.
- **SC-002**: the full suite passes with the coverage gate (100 %), PHPStan, PHP-CS-Fixer, Rector, Igor and Deptrac,
  run in containers.
- **SC-003**: the database suite passes on MySQL, MariaDB and PostgreSQL (triggers, guards, races).

## Non-goals

The leave request workflow, approval chains, team calendar, iCal and payroll CSV (S3); any health detail in leave;
GPS, biometric clocking, kiosks, payroll calculation, collective-agreement engines, shift planning, activity
monitoring (TimeTrackBundle) and a clock API of its own. Not legal advice.

## Assumptions

- The host provides tenant, worker, permission and notifier services through the ports; the defaults are safe
  (deny everything, no notices).
- The host declares the `nowo_working_time` entity manager (Flex recipe) or opts into its default manager.

## Validation

`make test`, `make test-with-db`, `make qa`, `make phpstan igor`, `make deptrac`, `make cs-check`, `make rector-dry`,
`make release-check`; inventory check: `find src -type f | wc -l` (339) against the coverage summary of
[`code-inventory.md`](code-inventory.md).
