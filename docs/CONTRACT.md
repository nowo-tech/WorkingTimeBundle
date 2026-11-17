# Working-time bundle (WorkingTimeBundle). Contract v0.1 for symfony-beacon KIT-77 / KIT-78

Status 2026-11-17: **v0.1, design only, awaiting the owner (Q-O, §21) and Symfony Beacon (Q-B, §21).** No code, no
PR, no tag. The repository `nowo-tech/WorkingTimeBundle` exists and stays empty until the answers are recorded (§22).

- **Approval.** Owner decision **D-20261117-16** (Beacon `docs/DECISIONS.md`): KIT-77 (working-hours register, art. 34.9
  ET, Beacon spec 198) and KIT-78 (leave and holidays, Beacon spec 204) become ONE new bundle,
  `nowo-tech/working-time-bundle`. Request: `.uic-work/working-time/REQUEST.md` rows KIT-77 / KIT-78 and section D.
- **Related decisions.** D-20261117-15 (a final lock conflict answers **409 + `Retry-After`** on every route, bundles
  included; OTHER REQ-SCALE-002), D-20261117-18 (TimeTrack client API at `/api/time-track/v1`, permanent aliases; the
  clock API of this bundle is served through TimeTrackBundle, §11.3).
- **Requirements.** OTHER `REQ-WORKTIME-001` (register) and `REQ-WORKTIME-002` (leave, holidays), block "REQ additions —
  Beacon audit 2026-11-17" of `OTHER_FULL_SPECS_DETAILS.md`. `REQ-WORKTIME-003` (activity monitoring) is **not** in this
  bundle: it is KIT-84 in TimeTrackBundle.
- **House bars from the first PR.** `BUNDLES_FIX_RULES.md`: REQ-ARCH-001 (standard Symfony default, DDD opt-in, rule 3
  strict: own VO per port, no Symfony / Doctrine / HTTP types in public ports, events or VOs; private entity manager),
  REQ-RUNTIME-002 (FrankenPHP worker mode, kernel reset **false**), identifier columns binary NO PAD (audit LQ), public
  HTTP API paths versioned, runtime settings env / DB where it applies, demos on UiKit + FormKit, 100 % of the applicable
  REQs of `BUNDLES_FULL_SPECS_DETAILS.md` before 1.0.0, and the 221 conventions (UUIDv7 `string(36)` binary ids, XML
  mapping default + attribute twin, public HTTP APIs disabled by default).

Read for this contract (2026-11-17):

| Source | Version read | What was read |
| --- | --- | --- |
| symfony-beacon (read-only, never modified) | worktree `v1163`, commit `286c916cd` (`feat/1.163.0`) | `src/WorkingTime/**` (183 files, 17 669 lines; register ≈ 7 970, `Leave/**` ≈ 9 700), entity mappings, `Integrity/*` (triggers, chain, verifier, canonical JSON), `Service/{TimeClock,RegisterPurger}`, `Schedule/ScheduleResolver`, `Leave/Service/LeaveRequests`, `Leave/Workflow/LeaveApprovalProcess`, `Leave/Holiday/*`, routes of every controller, cross-module imports; specs `198-working-hours-register`, `204-leave-management`, `241-reaudit-2026-11-17-fixes` (block A FR-003 – FR-005, tasks T003 – T005, T053 – T055) |
| transaction-doctrine | branch `feat/kit79-write-units` (1.3.0 in progress, `.uic-work/kit79-txdoctrine`) | `WriteUnit\{WriteUnitInterface,AsWriteUnit,LockConflictExceptionInterface,ConcurrencyConflict}`, `Symfony\EventListener\ConcurrencyConflictResponder`, `Symfony\Conflict\*`, `Symfony\Messenger\WriteUnitMiddleware` |
| TimeTrackBundle (Beacon vendor) | as installed in Beacon | `Workday\WorkdaySourceInterface`, `Client\{ClientUserLeaveProviderInterface,ClientUserDaysOffSourceInterface}`, `Service\TimerService` |
| WorkflowBundle (Beacon vendor) | 1.15 | `Contract\WorkflowRuntimeInterface` (`can`, `apply`, `enabledTransitions`, `marking`, `pin`) |
| UserKitBundle | main | `PersonalData\{PersonalDataExportProviderInterface,PersonalDataEraserInterface,ErasureRequest}` |
| brand-contracts | main | `Nowo\Brand\Contract\{BrandProviderInterface,Brand}` |
| Earlier contracts (style) | — | ShortLinkBundle `docs/CONTRACT.md` v1.0, NotifyKitBundle `docs/CONTRACT.md` (221-B) |
| Rules | 2026-11-17 | `BUNDLES_FIX_RULES.md`, `BUNDLES_FULL_SPECS_DETAILS.md` (REQ-ARCH-001, REQ-RUNTIME-002, REQ-ORM-*, REQ-DATA-*, REQ-UI-*, REQ-SF-006), OTHER REQ-WORKTIME-001/002, REQ-SCALE-002 |

## 0. Findings that shaped the contract

| # | Finding | Effect |
|---|---|---|
| F1 | Beacon's triggers exist for **MySQL and SQLite only** (`RegisterTriggers::statements()` returns `[]` on PostgreSQL); the purge exception is a session user variable `@beacon_working_time_purge` | The bundle ships trigger sets for MySQL 8, MariaDB 10.11 / 11.x, PostgreSQL 14+ and SQLite (§6.4). On PostgreSQL the purge flag is `SET LOCAL` (transaction-scoped, safe with poolers); on MySQL / MariaDB the session variable is set and cleared in `try / finally` and cleared again on every connect (worker mode, §16) |
| F2 | After a purge Beacon's verifier accepts "the oldest remaining link's `prev_hash`" as the anchor: anyone able to delete the oldest links (with the purge flag) leaves a chain that still verifies | New append-only `chain_anchor` table written by the purge in the same transaction; the verifier accepts a chain start only if it equals `GENESIS` or a recorded anchor (§6.3) |
| F3 | The hash covers **integer** ids (`organization`, `user`, `workday`, `author`) in canonical JSON. The bundle uses opaque string references | A `chain_format` column: format `1` re-creates Beacon's exact canonical form from frozen `legacy_refs`; format `2` is the bundle's form with string refs. Migrated chains are **never re-hashed** (re-hashing a legal record destroys the evidence); the first bundle link chains onto the last Beacon hash (§6.3, §18.2) |
| F4 | Beacon's leave-race fix (spec 241 FR-003 / FR-004, T003 / T004) is **planned, not in `286c916cd`**: `LeaveRequests` has no lock and `LeaveRequest` no version | The contract specifies the fix as Beacon designed it (per-worker lock `leave:<org>:<user>` around validate + persist, re-validate after acquiring; `#[Version]` on the request, stale decision refused, nothing stored, no notice) and adds the same version on correction decisions (same race, same shape, §15) |
| F5 | Schedules fall back to TimeTrack's organization working hours (spec 197, `App\TimeTrack\Entity\WorkSchedule`) and then the instance default | Port `ScheduleFallbackProviderInterface` (§8.2); the bundle never reads TimeTrack tables |
| F6 | Medical absences store only `justified` and an optional document, **but** `LeaveRequest.comment` (≤ 500) is free text on every type, medical included | Owner rule "no health detail anywhere in leave": the comment is refused for medical types (server side), hidden in the form, and every other comment shows a "no health information" hint; a schema / form enumeration test guards it (§14.3, Q-O7) |
| F7 | The PDF goes through Beacon's `App\Analytics\Report\PdfReportRenderer` (Dompdf, closed: no remote, no JS, chroot) | Port `RegisterPdfRendererInterface` with a Dompdf default configured the same way; Dompdf in `suggest` (Q-O9) |
| F8 | Holiday clients (`FestivosIoClient`, `OpenHolidaysClient`, `MunicipalityDirectory`, `HolidayImportParser`, 624 lines) depend only on HttpClient, OutboundUrlGuard and a cache: no leave or register type | They become the stand-alone `Holiday` component of the bundle (owner fixed point), with no dependency on `Register` or `Leave` (enforced by an architecture test, §5) |
| F9 | Leave approval runs through WorkflowBundle 1.15 per organization (`leave_approval`, D-20261112-10); the chain's earlier steps stay outside the workflow | Port `LeaveApprovalFlowInterface`: built-in state machine by default, WorkflowBundle bridge when installed; the chain steps (1–3 per group) stay in the bundle (§9.4) |
| F10 | `ScheduleAssignmentRepository::withdrawFuture()` **deletes** a not-yet-valid schedule row; history is otherwise kept by dated rows | Kept (future-only delete; past and current rows are immutable in code). Not trigger-protected: schedules are configuration, not the legal record (the workday stores its schedule snapshot) |
| F11 | The workday client API already lives in TimeTrackBundle (`WorkdaySourceInterface`, `ClientUserLeaveProviderInterface`, `ClientUserDaysOffSourceInterface`); Beacon implements them in `Client/BeaconWorkdayProvider`, `Leave/Client/*` | The bundle ships those adapters as a **TimeTrack bridge**; it serves no clock API of its own (§11.3) |
| F12 | Beacon's iCal feed URLs (`/dashboard/leave/ical/{token}.ics`) are subscribed in people's calendar apps: an installed-client path | Canonical `<prefix>/working-time/v1/ical/{token}.ics`; Beacon's path stays as a **permanent** alias (house API rule, §11.2) |

## 1. Scope and non-goals

**In scope (one bundle, three components that can be enabled separately):**

| Component | Namespace | Enabled by | Contents |
|---|---|---|---|
| **Register** (KIT-77) | `Nowo\WorkingTimeBundle\Register` | `register.enabled` (default `true`) and per tenant in its settings (default **off**) | Workdays, work / break segments, clock state machine, schedules (worker → group → tenant, dated), corrections with approval, manager-recorded corrections with acknowledgement / contest, append-only revisions with SHA-256 chain + DB triggers, verifier, inspection export PDF / CSV / ZIP, monthly summary, reminders inside the schedule, IP-prefix evidence (opt-in), 4–10-year retention purge, notices outbox, worker preferences |
| **Holiday** (KIT-78 part, stand-alone) | `Nowo\WorkingTimeBundle\Holiday` | `holidays.enabled` (default `true`) | Holiday calendars (national / regional / local, per year), work centres grouping calendars, providers festivos.io (by INE municipality), OpenHolidays API, CSV / ICS import with preview, municipality directory, `HolidayCalendarReaderInterface` for agendas that do not need leave |
| **Leave** (KIT-78) | `Nowo\WorkingTimeBundle\Leave` | `leave.enabled` (default `false`; requires `register` and `holidays`) and per tenant (default **off**) | Leave types (seeded, archivable, never deleted while referenced), allowance policies (worker → group → tenant, dated) with pro-rata and carry-over (with expiry and protected lines), day counter on schedules minus holidays, requests (half-day, hours), approval chain (built-in or WorkflowBundle), staffing minimum (warning), planning deadline and art. 38.3 ET reminders, team calendar, personal / team iCal feeds, on-behalf registration, overtime ratification and compensatory time off, payroll CSV, attachments (off by default), register integration (no missing-clock flags on leave days, leave type in exports) |

**Non-goals (v1, as specs 198 / 204):** GPS / geolocation, photo or biometric clocking, kiosks; payroll calculation,
overtime compensation logic beyond the ratified-hours ledger; collective-agreement rule engines (seniority days,
accrual formulas beyond pro-rata); shift planning / rotas (staffing is a warning only); any health data (diagnosis,
cause, sick-note content, IT management); accounts for workers' representatives or the Labour Inspectorate (export
handover only); activity monitoring (KIT-84, TimeTrackBundle); a clock API of its own (TimeTrackBundle serves it);
native apps. The bundle is **not legal advice**: the employer decides to use it as their register (the UI and
`docs/LEGAL.md` say so, as spec 198 does).

## 2. Package

| Item | Proposal |
|---|---|
| Name | `WorkingTimeBundle` (approved), Composer `nowo-tech/working-time-bundle`, GitHub `nowo-tech/WorkingTimeBundle` (public, MIT; owner registers Packagist once S0 is on `main`) |
| Namespace / class | `Nowo\WorkingTimeBundle` / `NowoWorkingTimeBundle`; config root `nowo_working_time`; Twig namespace `@NowoWorkingTime`; translation domain `NowoWorkingTimeBundle` (7 locales: en, es, ca, eu, gl, fr, pt as Beacon) |
| First tag | 0.x per slice for Beacon integration, **1.0.0** after the REQ audit and SEC-004 audits (Q-O14) |
| PHP / Symfony | `>=8.2 <8.6`; `^7.4 \|\| ^8.0`; demos Symfony 7.4 + 8.1 on FrankenPHP worker, MySQL |
| Runtime deps | `doctrine/dbal ^3.8.2 \|\| ^4.0`, `doctrine/orm ^3.7`, `doctrine/doctrine-bundle ^2.8 \|\| ^3.0`, `symfony/{framework-bundle,http-kernel,routing,config,dependency-injection,event-dispatcher,clock,console,form,validator,translation,twig-bundle,uid,http-client,mime}`, `psr/clock`, `psr/log`, `psr/cache`, `nowo-tech/transaction-contract ^1.0`, **`nowo-tech/transaction-doctrine ^1.3`** (write units, `LockConflictExceptionInterface`, `ConcurrencyConflictResponder` 409 + `Retry-After`), `nowo-tech/brand-contracts ^1.0`, `nowo-tech/outbound-url-guard-bundle ^1.5` (holiday fetches, Q-O8), `nowo-tech/ui-kit-bundle ^2.49`, `nowo-tech/form-kit-bundle ^2.22` |
| Suggest | `dompdf/dompdf` (PDF export), `symfony/messenger` (export generation, notices), `symfony/lock` (leave lock backend `lock_factory`), `symfony/mailer` (default notifier), `symfony/security-bundle` (default role-map checker), `nowo-tech/workflow-bundle ^1.15`, `nowo-tech/time-track-bundle` (bridge), `nowo-tech/task-board-bundle` (calendar annotations), `nowo-tech/user-kit-bundle` (GDPR export / eraser), `nowo-tech/audit-kit-bundle` (audit sink), `nowo-tech/notify-kit-bundle` (notifier), `nowo-tech/storage-kit-bundle` (attachments, export files), `nowo-tech/legal-kit-bundle` (privacy-notice section), `nowo-tech/http-log-bundle` (provider names on outbound calls) |
| Not used | No JS framework (Stimulus controllers shipped as plain assets, REQ-ASSETS-*), no GeoIP, no external PDF service |

## 3. Shape

```
                              Host (Beacon, CRM, Age Desk, Core v2)
  ports ──────────────────────────────────────────────────────────────────────────────────────────────
  TenantResolver · PermissionChecker · WorkerDirectory · BrandProvider · Clock · Notifier · AuditSink
  ScheduleFallback · PdfRenderer · ExportFileStore · LeaveAttachmentStore · LeaveApprovalFlow · HolidayProvider*
  ─────────────────────────────────────────────────────────────────────────────────────────────────────
  Presentation (opt-in routes, UiKit + FormKit)          Bridges (enabled when the package is installed)
    Register: today · month · day · team · schedules       TimeTrack: WorkdaySource, Leave/DaysOff sources, timer link
    exports · settings · preferences                       Workflow: leave_approval flow
    Leave: index · approvals · calendar · feeds · manage   TaskBoard: calendar day annotations
    Holidays: calendars · import · work centres            UserKit: export + eraser · AuditKit · NotifyKit
    iCal (public, token) <prefix>/working-time/v1/ical     StorageKit · LegalKit · HttpLog
  ─────────────────────────────────────────────────────────────────────────────────────────────────────
  Application   TimeClock · CorrectionService · ScheduleService · RegisterReader · RegisterExportService
                ChainVerifier · RegisterPurger · NoticeOutbox · LeaveRequestService · LeaveBalanceReader
                DayCounter · LeaveCalendarReader · LeaveAdministration · LeaveFeedService · OvertimeRatifier
                HolidayCalendarService · HolidayImporter · MunicipalityDirectory
  Domain        VOs, enums, entities (plain PHP, XML mapping), ports, events, exceptions
  Infra         private EM `nowo_working_time` · DBAL triggers (MySQL / MariaDB / PostgreSQL / SQLite)
                write units (transaction-doctrine) · leave lock (DB row or symfony/lock) · PSR-6 cache
  Console       verify · triggers:install|check|uninstall · purge · notices:send · leave:daily · attachments:purge
```

The bundle owns the record (workdays, segments, revisions, corrections), leave data, holiday data, the integrity
machinery, exports, the UI and the jobs. The host owns tenants, people and groups, who may do what, the brand, mail /
notification channels, file storage (optionally) and where the UI is mounted.

## 4. Layout and architecture rules

```
src/Domain/{Register,Leave,Holiday,Shared}/   VOs, enums, entities (plain PHP), ports, events, exceptions
src/Application/{Register,Leave,Holiday}/     services using the ports; no Symfony / Doctrine types in signatures
src/Infrastructure/Doctrine/                   XML mapping (default) + attribute twins, repositories, DBAL triggers,
                                               schema listener, chain-format codecs, purge flag handling
src/Infrastructure/Symfony/                    DI, config tree, controllers, forms (FormKit), commands, Messenger
                                               handlers, Twig, default port implementations
src/Bridge/{TimeTrack,Workflow,TaskBoard,UserKit,AuditKit,NotifyKit,StorageKit,LegalKit,Dompdf}/
src/Resources/{config,views,translations,public}/
```

- **Architecture test** (`tests/Architecture/LayerDependencyTest`, PHPat or a reflection test): `Domain` imports no
  Symfony / Doctrine / HTTP class; `Holiday\*` imports nothing from `Register\*` or `Leave\*`; `Register\*` imports
  nothing from `Leave\*` (leave hooks into the register through `RegisterDayAnnotationProviderInterface`, §8.3);
  bridges are the only code that imports optional packages.
- **Both host kinds** (REQ-ARCH-001): entities are bundle-owned plain classes; hosts reference them by id. There is
  no relation to host entities, no `resolve_target_entities`, no base class or trait for the host. The XML mapping is
  the default; attribute twins are generated and tested to give the same schema (`doctrine.mapping: xml|attribute`).

## 5. Public API: value objects and enums

All `final readonly`, validated in the constructor, in `Nowo\WorkingTimeBundle\Domain\…`.

| VO | Rule |
|---|---|
| `TenantRef`, `WorkerRef`, `GroupRef`, `ActorRef` | Opaque host ids, 1–128 bytes UTF-8, exact (no trim, no case folding, no control / format characters), compared with `===` (rule LQ). `TenantRef::default()` = `default` for single-tenant hosts |
| `WorkdayId`, `SegmentId`, `RevisionId`, `CorrectionId`, `ExportId`, `LeaveRequestId`, `LeaveTypeId`, `HolidayCalendarId`, `WorkCentreId`, … | UUID strings, 36 chars lowercase; new rows UUIDv7 through `IdGeneratorInterface` (default `symfony/uid`); migrated Beacon UUIDs (v4) accepted |
| `LocalDate` | `Y-m-d`, no time; `month()`, `year()` |
| `TimeZoneName` | Valid IANA identifier (`DateTimeZone::listIdentifiers()`), never an offset |
| `ClockInstant` | UTC instant at **second** precision (the hash must survive the database round trip, as Beacon) |
| `RequestKey` | `^[\x21-\x7E]{1,80}$`, matched byte for byte, never trimmed or truncated (Beacon REQ-SEC-004 F6); prefixed `web:` / `api:` |
| `IpPrefix` | Built from a raw IP inside the bundle: IPv4 `/24`, IPv6 `/64` (spec 198 FR-027; Q-O11); stored `203.0.113.0/24`, `2001:db8:1:2::/64`; the raw IP never leaves the call |
| `DaySchedule` | Time zone + weekday ranges (`HH:MM`–`HH:MM`, no overlap) or daily / weekly target minutes (≤ 720 per day) with a flexible band; `isWorkingDay()`, `expectedMinutes()`, `halfDay(DayPart)` |
| `WorkerProfile` | `WorkerRef`, display name (≤ 180), e-mail (nullable), locale, active, `joinedOn` / `leftOn` (`?LocalDate`), groups (`list<GroupRef>`), `contractRatio` (`?float` 0–1, part-time hours) |
| `TenantProfile` | `TenantRef`, name, default locale, default time zone |
| `NoticeMessage` | recipient `WorkerRef`, kind (`register.reminder_in`, `register.monthly_summary`, `leave.requested`, …), params (≤ 20 scalars), action URL (local path), locale |
| `LeaveAmount` | Unit (`LeaveUnit`) + integer in stored units: **half-days** for day units, **minutes** for hours (Beacon's `DayCount`) |
| `DayCount` | unit, amount, counted days (`Y-m-d` ⇒ amount), holidays met (`Y-m-d` ⇒ name) |
| `Balance` | entitled, taken, planned, pending, remaining (all `LeaveAmount`), lines |
| `HolidayLocation` | Country ISO 3166-1 alpha-2, optional subdivision ISO 3166-2 (`ES-MD`), optional INE municipality code (`^\d{5}$`) |
| `HolidayEntry` | date, name (≤ 120, control characters stripped), scope, origin, source ref / URL (http(s) only) |
| `ImportPreviewRow` | date, name, status `new` \| `duplicate` \| `other_scope` \| `invalid` |

Enums: `WorkingTimePermission` (§13), `ClockAction { ClockIn; BreakStart; BreakEnd; ClockOut }`, `SegmentType { Work;
Break }`, `SegmentSource { Web; Desktop; Extension; Correction; Auto }`, `RevisionKind { ClockIn; BreakStart; BreakEnd;
ClockOut; Correction; RecordedCorrection; Acknowledged; Contested }`, `CorrectionOperation { Change; Add; Void }`,
`CorrectionStatus { Pending; Approved; Rejected; PendingAcknowledgement; Acknowledged; Contested }`, `ScheduleScope {
Tenant; Group; Worker }`, `ExportFormat { Pdf; Csv; Zip }`, `ExportStatus`, `DayFlag { MissingClockOut;
MissingClockIn; Overtime; LongDay; ShortBreak; Late; Leave; Holiday }`, `LeaveStatus { Pending; Approved; Rejected;
Cancelled; Superseded }`, `LeaveUnit { NaturalDays; WorkingDays; Hours }`, `AllowanceSource`, `AllowanceLineKind {
Adjustment; CarryOver; ProtectedCarryOver }`, `DayPart { Full; Morning; Afternoon; Hours }`, `RoundingMode`,
`FeedKind { Personal; Team }`, `HolidayScope { National; Regional; Local }`, `HolidayOrigin { Manual; Import;
FestivosIo; OpenHolidays; Provider }`.

## 6. Domain model and tables

### 6.1 Conventions

- Prefix `doctrine.table_prefix` (default `nowo_`, REQ-ORM-002); names below use `<p>`.
- Ids: `string(36)` binary PK. Host refs (`tenant_id`, `worker_id`, `author_id`, `decided_by_id`, `target_id`, …):
  `string(128)` **binary NO PAD** (`utf8mb4_0900_bin` MySQL 8, `utf8mb4_nopad_bin` MariaDB; exact by default on
  PostgreSQL / SQLite, asserted by a test), declared through a metadata listener so `schema:update` and
  `migrations:diff` keep it. Every read, export or erasure by a ref re-checks `===` in PHP.
- Instants: `datetime_immutable`, UTC, second precision. Dates: `date_immutable`.
- No foreign keys to host tables. Internal FKs between bundle tables only where the purge deletes in order (§6.5).
- No Doctrine migrations shipped (house style); `docs/INSTALLATION.md` carries reference DDL per platform, generated
  by a test; `nowo:working-time:schema-check` compares the live schema (columns, collations, indexes, triggers).

### 6.2 Register tables

| Table | Columns (beyond `id`) | Keys | Write rule |
|---|---|---|---|
| `<p>working_time_workday` | `tenant_id`, `worker_id`, `worker_name` (180, kept until retention: art. 17.3.b), `local_date`, `schedule` (json snapshot of the `DaySchedule` that day), `created_at`, `legacy_id` (bigint NULL, Beacon int id, chain format 1 only) | unique `(tenant_id, worker_id, local_date)`; idx `(tenant_id, local_date)` | **INSERT only** (trigger) |
| `<p>working_time_segment` | `workday_id`, `type`, `started_at`, `start_source`, `start_received_at`, `start_ip_prefix` (43 NULL), `ended_at` NULL, `end_source` NULL, `end_received_at` NULL, `end_ip_prefix` NULL | idx `workday_id` | INSERT; **one** UPDATE that closes an open segment (only `end_*`, from NULL to a value); no DELETE (trigger) |
| `<p>working_time_revision` | `tenant_id`, `worker_id`, `workday_id`, `seq` (int), `kind`, `segment_id` NULL, `payload` (json), `reason` (text NULL), `author_id` NULL, `author_name` (180), `created_at`, `request_key` (80 ascii_bin NULL), `prev_hash` (char 64), `hash` (char 64), `chain_format` (smallint: 1 Beacon legacy, 2 bundle), `legacy_refs` (json NULL; format 1: `{organization, user, workday, author}` ints) | unique `(tenant_id, worker_id, seq)` (a chain never forks); unique `(tenant_id, worker_id, request_key)` (idempotency); idx `workday_id` | **INSERT only** (trigger) |
| `<p>working_time_chain_anchor` | `tenant_id`, `worker_id`, `first_kept_seq`, `anchor_hash` (the `hash` of the last purged link), `purged_links`, `purged_at`, `purge_run_id` | unique `(tenant_id, worker_id, first_kept_seq)` | **INSERT only** (trigger); written by the purge only |
| `<p>working_time_correction` | `tenant_id`, `worker_id`, `workday_id`, `operation`, `segment_id` NULL, `segment_type` NULL, `new_start` NULL, `new_end` NULL, `reason` (text, 10–500), `status`, `recorded_by_id` NULL (manager-recorded), `decided_by_id` NULL, `decided_by_name` NULL, `decided_at` NULL, `decision_comment` NULL, `revision_id` NULL, **`version`** (int, optimistic lock), `created_at` | idx `(tenant_id, status)`, `workday_id` | Updatable (a request, not the record); decisions write a revision |
| `<p>working_time_schedule` | `tenant_id`, `scope`, `target_id` NULL (group or worker ref), `valid_from`, `timezone`, `slots` (json), `daily_target_minutes` NULL, `weekly_target_minutes` NULL, `flex_minutes`, `work_centre_id` NULL, `created_at`, `created_by_id` | idx `(tenant_id, scope, target_id, valid_from)` | INSERT; DELETE only while `valid_from` > today (withdraw) |
| `<p>working_time_settings` | PK `tenant_id`; `enabled`, `breaks_counted`, `flex_minutes`, `min_break_minutes`, `daily_overtime_minutes`, `weekly_overtime_minutes`, `task_timer_link`, `reminders_allowed`, `monthly_summary_mandatory`, `ip_evidence`, `ip_notices` (json per locale), `ip_notice_locale`, `retention_years` (4–10), `correction_window_days` (1–31), **`version`**, `updated_by_id`, `updated_at` | — | Updatable with version |
| `<p>working_time_preference` | PK `worker_id`; `reminders` (default false), `monthly_summary` (default true), `leave_emails` (default true), `updated_at` | — | Updatable |
| `<p>working_time_export` | `tenant_id`, `requested_by_id`, `requested_by_name`, `worker_id` NULL (team), `group_id` NULL, `own_copy`, `month_from`, `month_to` (≤ 12 months), `format`, `status`, `file_name`, `content_type`, `sha256`, `chain_heads` (json), `created_at`, `ready_at`, `expires_at` (+7 days), `error_code` | idx `(tenant_id, created_at)` | Status moves by conditional UPDATE (claim) |
| `<p>working_time_export_file` | PK `export_id`; `content` (blob) — default `ExportFileStoreInterface` only | — | Deleted at expiry |
| `<p>working_time_notice` | `tenant_id`, `worker_id`, `kind`, `params` (json), `dedupe_key` (120 ascii_bin), `deliverable_from` (spec 241 T018), `created_at`, `sent_at` NULL | unique `dedupe_key`; idx `(sent_at, deliverable_from)` | Outbox; purged 400 days after `sent_at` |

### 6.3 Hash chain

- One chain per `(tenant_id, worker_id)`. `hash = sha256(prev_hash ‖ canonical)`, `GENESIS` = 64 zeros (Beacon's
  constant, kept). Canonical JSON: keys sorted at every level, lists in order, `JSON_UNESCAPED_SLASHES |
  JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION` (Beacon's `CanonicalJson`, copied byte for byte).
- **Format 1** (migrated Beacon rows): `{organization, user, workday, seq, kind, segment, payload, reason, author,
  author_name, at, request_key}` with the four ids taken from `legacy_refs` as integers: exactly Beacon's
  `WorkdayRevision::canonical()`. **Format 2** (new rows): the same keys with `tenant`, `worker`, `workday` (UUID),
  `author` as strings, plus `format: 2`. `ChainCodecInterface` per format; the verifier dispatches on `chain_format`.
- `RevisionChain::append()` reads the head (`ORDER BY seq DESC LIMIT 1`) inside the write unit's transaction and
  inserts `seq + 1`; a racing writer hits the unique key and the write unit replays (§15.2).
- `ChainVerifier::verify(?TenantRef, ?WorkerRef)`: per chain, every link's hash against its row and the previous
  link, `seq` without gaps, chain start = `GENESIS` or the `anchor_hash` of the matching `chain_anchor` row
  (F2), each clock link's segment times against the segment row. Batches of 1 000, `clear()` between them (flat
  memory). Budget: a tampered row in a 10 000-link chain found in < 5 s (spec 198 SC-002). Runs weekly
  (scheduler recipe) and before every export (the export stores `chain_heads`).

### 6.4 Triggers per database (`RegisterTriggerInstaller`, `nowo:working-time:triggers:{install,check,uninstall}`)

Protected tables: `workday`, `segment`, `revision`, `chain_anchor`. Messages: `'<table> is append-only'`. Trigger
names `<p>wt_<table>_no_update` / `_no_delete` (≤ 64 chars). Installed by the command (Beacon calls it from a
migration); the check command and `schema-check` report missing triggers; settings pages show a warning while they
are missing (`integrity.triggers: warn`, Q-O6).

**MySQL 8.0+ / 8.4**

```sql
CREATE TRIGGER <p>wt_revision_no_update BEFORE UPDATE ON <p>working_time_revision FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '<p>working_time_revision is append-only';
CREATE TRIGGER <p>wt_segment_no_update BEFORE UPDATE ON <p>working_time_segment FOR EACH ROW
  BEGIN
    IF OLD.ended_at IS NOT NULL OR NEW.ended_at IS NULL
       OR NOT (NEW.id <=> OLD.id) OR NOT (NEW.workday_id <=> OLD.workday_id) OR NOT (NEW.type <=> OLD.type)
       OR NOT (NEW.started_at <=> OLD.started_at) OR NOT (NEW.start_source <=> OLD.start_source)
       OR NOT (NEW.start_received_at <=> OLD.start_received_at) OR NOT (NEW.start_ip_prefix <=> OLD.start_ip_prefix)
    THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '<p>working_time_segment is append-only'; END IF;
  END;
CREATE TRIGGER <p>wt_revision_no_delete BEFORE DELETE ON <p>working_time_revision FOR EACH ROW
  BEGIN IF COALESCE(@nowo_working_time_purge, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '<p>working_time_revision is append-only'; END IF; END;
-- same no_update for workday and chain_anchor; same no_delete for workday and segment; chain_anchor: no DELETE at all
```

- Needs the `TRIGGER` privilege; with binary logging also `SUPER` or `log_bin_trust_function_creators = 1`
  (documented in UPGRADING; the installer reports it, rolls back what it created, exits 2).
- `TRUNCATE` and `DROP TABLE` do not fire triggers on MySQL. The chain and the anchors alone do not detect a whole
  chain removed with its segments and workdays. `verify` detects it with the segment cross-checks and with heads
  recorded outside the table: the export `chain_heads`, `--heads`, or heads published through the audit sink. See
  SECURITY.md for the residual risk and the recommended grants (audit ST-M3 / ST-I1).
- Purge guard `session_variable` (default): the purger runs `SET @nowo_working_time_purge = 1` on its own connection
  right before its DELETE batch and `SET @nowo_working_time_purge = NULL` in `finally`; a DBAL middleware
  (`PurgeFlagResetMiddleware`) also clears it on every new connection (worker mode). Option `purge_guard: db_user`:
  the trigger checks `SUBSTRING_INDEX(USER(), '@', 1) = '<purge user>'` (the login user: `CURRENT_USER()` is the definer inside a trigger) instead, and the purger uses a
  dedicated DBAL connection (`integrity.purge_connection`) — stronger, needs a second DB user (Q-O5).

**MariaDB 10.11 / 11.4** — same SQL as MySQL (`<=>`, `SIGNAL`, user variables all supported). Differences handled by
the installer: `CREATE OR REPLACE TRIGGER` is not used (idempotency through `DROP TRIGGER IF EXISTS` first); collation
`utf8mb4_nopad_bin`; the binlog privilege note reads `log_bin_trust_function_creators`. Tested in CI on 10.11 and 11.4.

**PostgreSQL 14+**

```sql
CREATE OR REPLACE FUNCTION <p>wt_refuse() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' AND TG_TABLE_NAME <> '<p>working_time_chain_anchor'
     AND current_setting('nowo_working_time.purge', true) = '1' THEN RETURN OLD; END IF;
  RAISE EXCEPTION '% is append-only', TG_TABLE_NAME USING ERRCODE = 'P0001';
END $$;
CREATE OR REPLACE FUNCTION <p>wt_segment_close_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF OLD.ended_at IS NOT NULL OR NEW.ended_at IS NULL
     OR (NEW.id, NEW.workday_id, NEW.type, NEW.started_at, NEW.start_source, NEW.start_received_at, NEW.start_ip_prefix)
        IS DISTINCT FROM (OLD.id, OLD.workday_id, OLD.type, OLD.started_at, OLD.start_source, OLD.start_received_at, OLD.start_ip_prefix)
  THEN RAISE EXCEPTION '<p>working_time_segment is append-only' USING ERRCODE = 'P0001'; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER <p>wt_revision_no_update BEFORE UPDATE ON <p>working_time_revision FOR EACH ROW EXECUTE FUNCTION <p>wt_refuse();
CREATE TRIGGER <p>wt_revision_no_delete BEFORE DELETE ON <p>working_time_revision FOR EACH ROW EXECUTE FUNCTION <p>wt_refuse();
CREATE TRIGGER <p>wt_revision_no_truncate BEFORE TRUNCATE ON <p>working_time_revision FOR EACH STATEMENT EXECUTE FUNCTION <p>wt_refuse();
CREATE TRIGGER <p>wt_segment_no_update BEFORE UPDATE ON <p>working_time_segment FOR EACH ROW EXECUTE FUNCTION <p>wt_segment_close_only();
-- the same no_update / no_delete / no_truncate set for workday, segment (delete, truncate) and chain_anchor
```

- Purge flag: `SET LOCAL nowo_working_time.purge = '1'` inside the purge transaction only (transaction-scoped: gone at
  commit or rollback, safe with PgBouncer transaction pooling and kept-alive workers). `purge_guard: db_user` uses
  `current_user = '<purge role>'`.
- `TRUNCATE` is refused (statement trigger), unlike MySQL.

**SQLite** (tests, demo) — Beacon's set (`RAISE(ABORT, …)`, `WHEN` clause for the segment close), no purge exception
(the purge test runs on MySQL / MariaDB / PostgreSQL services in CI).

**Code side (all platforms).** Repositories of the protected tables expose `add()` and read methods only; segment
closing is `WorkSegment::close()` (throws when already closed); an architecture test lists every repository and
service and fails on an update / delete path (spec 198 SC-001). The ORM mapping marks `WorkdayRevision`, `Workday`
and `ChainAnchor` `read-only="true"` (Doctrine never issues an UPDATE for them).

### 6.5 Retention purge (`RegisterPurger`, `nowo:working-time:purge`, daily)

- Per tenant: first kept local date = first day of the month `retention_years` (4–10, never < 4 whatever is stored)
  before the current month. Deletes workdays before it with their segments, revisions, corrections, leave requests /
  request days / decisions of those months (§6.6), exports and notices, in batches of 500 by keyset, one transaction
  per batch.
- Per chain and batch: writes the `chain_anchor` row (hash of the last deleted link, `first_kept_seq`) **in the same
  transaction** as the deletes. Deletion, not anonymisation (spec 198 FR-024).
- One audit event `working_time.register_purged` per tenant and run (counts only). Exit codes: 0 done, 1 error,
  2 triggers refused the deletes (guard misconfigured).

### 6.6 Leave tables

| Table | Columns (beyond `id`) | Keys / write rule |
|---|---|---|
| `<p>working_time_leave_type` | `tenant_id`, `seed_code` NULL, `names` (json per locale), `paid`, `allowance_source`, `unit`, `needs_approval`, `needs_attachment`, `medical`, `colour` (palette token), `max_per_request` NULL, `carry_over`, `carry_over_max` NULL, `carry_over_expiry` (`MM-DD`) NULL, `archived`, `sort_order`, `created_at` | idx `(tenant_id, archived)`; never deleted while referenced (archive) |
| `<p>working_time_leave_policy` | `tenant_id`, `leave_type_id`, `scope`, `target_id` NULL, `leave_year`, `amount` (stored units), `agreement_equivalent`, `note` (255) NULL, `created_at`, `created_by_id` | idx `(tenant_id, leave_type_id, leave_year)` |
| `<p>working_time_leave_allowance_line` | `tenant_id`, `worker_id`, `leave_type_id`, `leave_year`, `line_kind`, `amount`, `reason` (255, required), `expires_on` NULL, `expired_at` NULL, `created_at`, `created_by_id` | idx `(tenant_id, worker_id, leave_year)`; append-only in code (corrections are new lines) |
| `<p>working_time_leave_request` | `tenant_id`, `worker_id`, `leave_type_id`, `start_date`, `end_date`, `part`, `start_time` / `end_time` (`HH:MM`) NULL, `amount`, `status`, `marking` (workflow place), `step` (1–3), `comment` (≤ 500) NULL (always NULL for medical types), `justified` NULL (medical only), `attachment_ref` / `attachment_name` / `attachment_type` / `attachment_size` NULL, `registered_by_id` NULL, `on_behalf_reason` NULL, `revision_of_id` NULL, `direct_approval`, `submitted`, `transition_at`, `decided_at`, **`version`** (int, `#[Version]`), `created_at`, `updated_at` | idx `(tenant_id, worker_id, start_date)`, `(tenant_id, status)` |
| `<p>working_time_leave_request_day` | `request_id`, `tenant_id`, `worker_id`, `leave_date`, `amount` | idx `(tenant_id, worker_id, leave_date)`, `(tenant_id, leave_date)`, `request_id` |
| `<p>working_time_leave_decision` | `request_id`, `chain_step`, `decider_id`, `outcome` (`approved` \| `rejected` \| `modified` \| `cancelled`), `reason` (500) NULL, `request_version` (the version decided on), `created_at` | idx `request_id`; append-only in code |
| `<p>working_time_leave_settings` | PK `tenant_id`; `enabled`, `colleagues_see_absences` (default true), `medical_documents` (default false), `attachment_retention_months` (1–48, default 12), `overtime_ratio` (percent, default 100), `planning_deadline` (`MM-DD`) NULL, `rounding`, `protected_carry_months` (default 18), `approval_chains` (json: group ⇒ list of 1–3 approver refs or `any`), `min_staffing` (json: group ⇒ `{min_present}` \| `{max_off_percent}`), **`version`**, `updated_by_id`, `updated_at` | Updatable with version |
| `<p>working_time_leave_overtime` | `tenant_id`, `worker_id`, `ratified_month` (`YYYY-MM`), `overtime_minutes`, `credit_minutes`, `ratified_by_id`, `ratified_at` | unique `(tenant_id, worker_id, ratified_month)` |
| `<p>working_time_leave_feed` | `tenant_id`, `worker_id`, `feed_kind`, `token_hash` (sha256 of the token; the token is shown once), `created_at`, `last_used_on` (date only) | unique `(tenant_id, worker_id, feed_kind)`, unique `token_hash` |
| `<p>working_time_worker_lock` | PK `(tenant_id, worker_id)`; `touched_at` | Only for `leave.lock.store: database` (§15.1) |

### 6.7 Holiday tables (component; usable without register or leave)

| Table | Columns | Keys |
|---|---|---|
| `<p>holiday_calendar` | `tenant_id`, `name` (80), `scope`, `year`, `country` (2) NULL, `subdivision` (6) NULL, `municipality_code` (5) NULL, `provider` (`manual` \| `festivos_io` \| `openholidays` \| `file` \| custom key), `provisional` (festivos.io `local_status: pending`), `attribution` (255) NULL, `source_note` (255) NULL, `last_fetched_at` NULL, `created_at` | idx `(tenant_id, year)` |
| `<p>holiday_day` | `tenant_id`, `calendar_id`, `holiday_date`, `name` (120), `origin`, `source_ref` (120) NULL, `source_url` (255) NULL | unique `(calendar_id, holiday_date)`; idx `(tenant_id, holiday_date)` |
| `<p>holiday_work_centre` | `tenant_id`, `name` (80), `calendar_ids` (json list of UUIDs), `is_default` | idx `tenant_id`; one default per tenant (checked in code under the tenant's settings version) |

## 7. Public services (what a host calls)

| Service (interface) | Methods (summary) |
|---|---|
| `RegisterClockInterface` (`TimeClock`) | `state(TenantRef, WorkerRef): ClockState`; `act(TenantRef, WorkerRef, ClockAction, ?ClockInstant $occurredAt, RequestKey, SegmentSource, ?string $rawIp): ClockState` (raw IP only turned into `IpPrefix` when the tenant enabled IP evidence) — throws `ClockConflictException` (409 with state), `InvalidRequestKeyException` (400), `RegisterUnavailableException` (404) |
| `RegisterReaderInterface` | `day()`, `week()`, `month(TenantRef, WorkerRef, 'YYYY-MM'): MonthSummary`, `team(TenantRef, ?GroupRef, LocalDate)`; `DayRecord` with effective segments (after corrections), totals and `DayFlag`s, leave / holiday annotations |
| `CorrectionServiceInterface` | `request(...)`, `decide(CorrectionId, approve, ?comment, int $expectedVersion)`, `record(...)` (manager), `acknowledge(...)`, `contest(...)` |
| `ScheduleServiceInterface` | `assign(TenantRef, ScheduleScope, ?string $target, LocalDate $validFrom, DaySchedule, ?WorkCentreId)`, `withdrawFuture(...)`, `resolve(TenantRef, WorkerRef, LocalDate): DaySchedule`, `range(...)` (two queries for a whole range, as Beacon) |
| `RegisterSettingsEditorInterface` | `get(TenantRef)`, `update(TenantRef, RegisterSettingsInput, int $expectedVersion)`; enabling IP evidence requires the notice text in the tenant's locale |
| `RegisterExportServiceInterface` | `request(...)`, `status(ExportId)`, `open(ExportId): ExportFile` (stream + SHA-256), `ownCopy(...)` |
| `ChainVerifierInterface` | `verify(?TenantRef, ?WorkerRef): VerificationReport` |
| `LeaveRequestServiceInterface` | `count(...)`: `DayCount` (live dialog), `create(LeaveContext, RequestInput)`, `edit(...)`, `cancel(...)`, `registerOnBehalf(...)`, `decide(LeaveContext, LeaveRequestId, bool, ?reason, int $expectedVersion)`, `bulkApprove(LeaveContext, list<{id, version}>): BulkResult` |
| `LeaveBalanceReaderInterface` | `balances(TenantRef, WorkerRef, int $year): array<LeaveTypeId, Balance>`, `overtimeCredit(...)` |
| `LeaveDaysReaderInterface` | `daysOff(TenantRef, WorkerRef, LocalDate $from, LocalDate $to)` (types visible to the viewer), `currentLeave(...)` — used by the register, TimeTrack and TaskBoard bridges and by host agendas |
| `LeaveAdministrationInterface` | types, policies, allowance lines, work centres, settings, seeding (`seedTypes(TenantRef, locale)`), payroll CSV |
| `HolidayCalendarReaderInterface` | `holidaysBetween(TenantRef, WorkCentreId\|list<HolidayCalendarId>, LocalDate, LocalDate): list<HolidayEntry>`, `isHoliday(...)` — **the stand-alone entry point for agendas** |
| `HolidayCalendarServiceInterface` | calendars CRUD, manual days, `preview(HolidayCalendarId, ProviderKey, params)`, `confirm(...)`, `import(file)` |
| `MunicipalityDirectoryInterface` | `search(string $query, int $limit = 20)` (cache only, never calls out on a search), `name(string $ineCode)` |

**Events** (`Domain\Event`, dispatched **after commit**, ids and VOs only, rule 4 / 7): `ClockActionRecorded`,
`CorrectionRequested`, `CorrectionDecided`, `RegisterSettingsChanged`, `RegisterExportReady`, `RegisterPurged`,
`ChainBroken` (verifier), `LeaveRequestSubmitted`, `LeaveRequestDecided`, `LeaveRequestCancelled`,
`LeaveRegisteredOnBehalf`, `LeaveAllowanceAdjusted`, `HolidaysImported`. A per-call after-commit buffer (no service
state across requests) releases them; a rollback drops them.

## 8. Ports and SPIs (host-implemented; each has a safe default)

All in `Nowo\WorkingTimeBundle\Domain\Port`, configured by service id (no host override of bundle services needed:
owner fixed point "configurable by tenant resolver, permission checker and brand provider").

### 8.1 Required for a real deployment (defaults are safe, not useful)

```php
interface WorkingTimeTenantResolverInterface        // [SingleTenantResolver: TenantRef::default()]
{
    /** Tenant of the current call; null = no tenant (personal context): every page and action answers 404. Per call, never cached. */
    public function current(): ?TenantRef;
    public function profile(TenantRef $tenant): ?TenantProfile;  // name, locale, time zone (PDF header, notices)
}

interface WorkingTimePermissionCheckerInterface     // [RoleMapPermissionChecker: config permissions.roles, every list empty = DENY]
{
    /**
     * Deny by default. $subject narrows "manage / approve / view" to one worker: the host answers whether the actor
     * manages that worker (group, team). A null subject asks for the tenant-wide grant.
     */
    public function isGranted(WorkingTimePermission $permission, TenantRef $tenant, ?WorkerRef $subject = null): bool;
    public function currentActor(): ?ActorRef;
}

interface WorkerDirectoryInterface                  // [CurrentUserWorkerDirectory: only the security user; team views empty]
{
    public function current(): ?WorkerRef;                                   // the signed-in person, per call
    public function worker(TenantRef $tenant, WorkerRef $worker): ?WorkerProfile;   // null = not a member: 404
    /** @return iterable<WorkerProfile> members, optionally of one group; streamed in pages of 500 */
    public function workers(TenantRef $tenant, ?GroupRef $group = null): iterable;
    /** @return list<array{GroupRef, string}> groups with labels (schedules, allowances, staffing, chains) */
    public function groups(TenantRef $tenant): array;
    /** @return list<WorkerRef> workers the actor manages (approvals inbox, team views); host rule */
    public function managedBy(TenantRef $tenant, ActorRef $actor): array;
}
```

`Nowo\Brand\Contract\BrandProviderInterface` (brand-contracts): `brand_provider` service id, default the container's
`BrandProviderInterface` alias if one exists, else a neutral brand (name = tenant name, no logo). Used by the PDF
header, e-mail notices, the iCal `PRODID` / calendar name and the UI title. Per call, never cached (worker rule).

### 8.2 Optional ports

| Port | Default | Purpose |
|---|---|---|
| `Psr\Clock\ClockInterface` | `symfony/clock` native clock | Every "now"; tests use `MockClock` |
| `WorkingTimeNotifierInterface::send(NoticeMessage): void` | `MailerNotifier` (Symfony Mailer, branded Twig templates, recipient's locale) when `symfony/mailer` is present, else `NullNotifier` + a warning on the settings page | Reminders, monthly summary, leave notices. The bundle decides **when** (only inside the recipient's schedule ±15 min, spec 198 FR-025, via the outbox `deliverable_from`); the notifier only delivers. NotifyKit bridge `NotifyKitNotifier` (in-app + channels); Beacon may implement it with its inbox + Slack DM |
| `WorkingTimeAuditSinkInterface::record(AuditEntry)` | `PsrLogAuditSink` (one structured line, no free text) | `working_time.*` audit keys (settings, decisions, exports, purges, on-behalf, attachment access); AuditKit bridge; never comments, reasons' content or attachments |
| `ScheduleFallbackProviderInterface::fallback(TenantRef, LocalDate): ?DaySchedule` | `ConfiguredScheduleFallback` (`register.schedule.default`) | When no dated schedule row matches (Beacon: spec 197 organization working hours, then instance default) |
| `RegisterPdfRendererInterface::render(RegisterReport, Brand): string` | `DompdfRegisterPdfRenderer` (closed Dompdf: `isRemoteEnabled=false`, `isJavascriptEnabled=false`, `isPhpEnabled=false`, temporary chroot) when `dompdf/dompdf` is installed; without it `export.formats` may not contain `pdf` (container error) | Inspection PDF (chain head on every page's header and the *Integrity* block; the file's SHA-256 stored, sent as `X-Content-SHA256`, and in `SHA256SUMS.txt` of a team ZIP, as Beacon) |
| `ExportFileStoreInterface` | `DatabaseExportFileStore` (`export_file` blob) | StorageKit bridge `StorageKitExportFileStore` |
| `LeaveAttachmentStoreInterface` | none: attachments disabled unless a store is configured (container error if `leave.attachments.enabled: true` without one); StorageKit bridge `StorageKitLeaveAttachmentStore` (private profile) | Documents (PDF / image ≤ 10 MB, magic-byte check), never public URLs, streamed through a controller that re-checks permission |
| `LeaveApprovalFlowInterface` | `BuiltInLeaveApprovalFlow` (fixed state machine = Beacon's default table, §9.4) | `apply(LeaveRequestId, LeaveTransition, LeaveFlowVariables): LeaveStatus`, `can(...)`. WorkflowBundle bridge `WorkflowBundleLeaveApprovalFlow` |
| `HolidayProviderInterface` (tagged `nowo_working_time.holiday_provider`) | built-in `festivos_io`, `openholidays`, `file` | `key()`, `label()`, `attribution(): ?string`, `supports(HolidayLocation)`, `fetch(HolidayLocation, int $year, string $language): HolidayFetchResult`. Hosts add providers (another country's official feed) without overriding anything |
| `RegisterDayAnnotationProviderInterface` (tagged) | Leave's `LeaveDayAnnotations` when leave is on; holidays' `HolidayDayAnnotations` | Lets leave / holidays mark register days (no missing-clock flag, expected time reduced) without `Register` importing `Leave` |
| `WorkingTimeLegalTextInterface` | LegalKit bridge `LegalKitWorkingTimeSection` | Privacy-notice paragraphs (register: 6.1.c, 4 years, recipients; leave: 6.1.b / 6.1.c, no health data, documents HR-only) |
| `TaskTimerLinkInterface` | `NullTaskTimerLink`; TimeTrack bridge `TimeTrackTaskTimerLink` | Stop the running timer on `clock_out` / `break_start`; failures logged, never undo the register (spec 198 FR-012) |

### 8.3 Bridges (auto-enabled when the package is installed; each can be switched off)

| Bridge | Implements | From Beacon |
|---|---|---|
| TimeTrack | `WorkdaySourceInterface` (`todayFor`, `actFor` → `RegisterClockInterface`), `ClientUserLeaveProviderInterface`, `ClientUserDaysOffSourceInterface`, `TaskTimerLinkInterface` | `Client/BeaconWorkdayProvider`, `Leave/Client/{BeaconLeaveProvider,LeaveAwareClientUserProfile}`, `EventListener/TaskTimerLinkListener` |
| TaskBoard | `CalendarDayAnnotationProviderInterface` (leave and holiday days on the task calendar, *Absent* rule applies) | `Leave/Client/TaskCalendarDays` |
| Workflow | `LeaveApprovalFlowInterface` on `WorkflowRuntimeInterface` with slug `leave_approval`, template definition provider | `Leave/Workflow/*` |
| UserKit | `PersonalDataExportProviderInterface` (`working_time` + `leave` sections), `PersonalDataEraserInterface` | `Leave/PersonalData/*` (and the register half Beacon lacks) |
| AuditKit / NotifyKit / StorageKit / LegalKit / HttpLog | the ports above; HttpLog provider names `festivos_io`, `openholidays` on the outbound client | `UserActionRecorder` calls, `BrandedEmailComposer`, `StorageProfiles` |

## 9. Behaviour (summary of the rules the code implements; specs 198 / 204 are the long form)

### 9.1 Register

- Clock states `idle → working ↔ on_break → closed`; a closed day may reopen (split shift). Server time; an offline
  client's `occurredAt` is stored with `received_at` (flag *late* beyond 10 min) and never earlier than the day's last
  time. No automatic clock-out: a segment open past schedule end + 2 h (or 16 h) is *missing clock-out*; the next
  clock-in opens a new day; the worker files a correction. Idempotent per `RequestKey` (unique key; a repeat answers
  the current state). Each action appends a chain link in the same transaction.
- Schedules: worker → group → tenant default, the row valid on the date; then `ScheduleFallbackProviderInterface`. A
  workday stores the schedule snapshot it was measured against, so later changes never change past flags.
- Corrections within `correction_window_days` (default 31), reason 10–500; decided by a holder of `manage_team` for that
  worker, **never the worker**; approval appends a revision; manager-recorded corrections need a reason and wait for
  acknowledgement or contest (kept in the trail). Flags (overtime daily / weekly, short break, long day) are
  informational, never blocking.
- Exports (`export`): month or range ≤ 12 months, one worker or a group / team; PDF + CSV per worker, ZIP for a team;
  generated off the request (Messenger `GenerateRegisterExportMessage`, sync fallback for small ranges when Messenger is
  absent), downloadable 7 days, audited. Workers download their own month without `export`. CSV columns are stable
  (payroll import, owner answer 2026-11-08) and written through a CSV-injection-safe writer.
- Monthly summary on the first working day of the month inside the worker's hours, opt-out unless the tenant made it
  mandatory; reminders opt-in per worker, allowed per tenant, only within schedule ±15 min.

### 9.2 Holidays

- Providers run **only on an admin action** (preview, then confirm), never on page views or schedules. Calls go
  through OutboundUrlGuard (`RuntimeOutboundUrlGuard`), timeout 10 s, no redirects, fixed base URIs from config, no
  personal data (country, region, municipality code, year only). Failures map to one translated error per provider.
- festivos.io: `GET /v1/{year}/municipio/{ine}.json`, national + regional + local in one call, `local_status: pending`
  ⇒ calendar `provisional`; attribution *festivos.io — CC BY 4.0* shown on every screen that shows the import and
  stored on the calendar. Municipality directory: `/v1/ref/municipios.json`, cached 7 days in the configured pool.
- OpenHolidays: national calendar keeps `nationwide`; regional asks `subdivisionCode`; `type: Public` only;
  multi-day holidays give one row per day; subdivisions cached 1 day.
- File: CSV `date,name,scope` (`,` or `;`, UTF-8, BOM stripped, header, ≤ 200 rows, ≤ 256 KiB) or ICS (`VEVENT` with an
  all-day `DTSTART` and `SUMMARY`); preview statuses `new` / `duplicate` / `other_scope` / `invalid`.
- Confirm inserts only `new` rows (unique `(calendar_id, holiday_date)` makes a double confirm harmless).

### 9.3 Leave

- Seeded types on enabling (spec 204 table: vacation 30 natural days or agreement equivalent, personal days,
  compensatory time off, art. 37.3 ET paid leaves, unpaid, absence, *Absence – medical (justified)*), editable,
  archivable. Allowances worker → group → tenant on 1 January or the join date; pro-rata by natural days employed,
  rounded per `rounding`; hour units × `contractRatio`; carry-over per type (max, expiry) and protected carry-over
  lines (IT / birth overlap, 18 months by default) with a reason; a daily job expires carry-over with an audit row.
  Below the legal vacation minimum is refused unless *agreement equivalent* with a note.
- `DayCounter`: schedule working days minus the worker's holidays (work centre from the schedule assignment, else the
  tenant default) for `working_days`; every day for `natural_days`; requested minutes capped at the scheduled day for
  `hours`; half-days by the schedule's ranges or half the daily target.
- Validation (inside the lock, §15.1): end ≥ start, inside one allowance year, type maximum, no overlap with own
  pending / approved requests, within the balance (pending included) for allowance types, within the overtime credit
  for compensatory time off, attachment when required (and allowed), comment refused on medical types, on-behalf for
  oneself refused (spec 241 FR-005).
- Staffing minimum per group: a **warning** on the dialog and the approval row, never a block. Planning deadline and
  reminders at 30 / 7 days; warning when vacation is approved < 2 months ahead (art. 38.3 ET).
- Team calendar: `view_team` holders and the worker's approvers see the type; colleagues see *Absent* when the tenant
  allows it (default on); medical shows its type only to `leave.manage`; comments and attachments never appear.
- iCal: personal feed (own leave with types) and team feed (*Absent* only, filtered by the creator's view at render
  time); token per worker and feed, 32 random bytes hex, stored hashed, shown once, rotate / revoke; `Cache-Control:
  private, max-age=900`; revoked, orphaned (worker no longer a member) or disabled-tenant token ⇒ 404.
- Register integration via `RegisterDayAnnotationProviderInterface`: approved full days raise no missing-clock flag and
  no reminder; half-days / hours reduce the expected time; the month view, summary and inspection export show the type
  (*Absence – justified* for medical to anyone without `leave.manage`; the worker always sees their own type).
- Overtime ratification: managers ratify flagged overtime per worker and month; credit = minutes × `overtime_ratio`;
  compensatory requests draw from it.

### 9.4 Approval chain and flow

- Chain per group: 1–3 ordered steps, each step "any one holder"; default step = holders of `leave.approve` who
  manage the worker (`WorkerDirectoryInterface::managedBy`), else any holder in the tenant; never the requester.
- Flow transitions (the built-in default, and the WorkflowBundle template): `submit` requested → pending;
  `auto_approve` pending → approved (`direct`); `approve` pending → approved (last step only; earlier steps move
  `step`); `reject`; `cancel` pending → cancelled; `cancel_approved` approved → cancelled; `supersede` approved →
  superseded. Variables: `amount`, `unit`, `type` (seed code), `direct`, `on_behalf`, `medical`. A place the bundle does
  not know is refused. With the Workflow bridge the marking lives on the bundle's `LeaveRequest.marking` and the
  WorkflowBundle runtime is called inside the bundle's write unit; it must not flush another EntityManager (Q-B7).

## 10. Configuration (strict tree, REQ-SF-006; defaults shown)

```yaml
nowo_working_time:
    tenant_resolver: null          # service id of WorkingTimeTenantResolverInterface; null = single tenant "default"
    permission_checker: null       # service id; null = RoleMapPermissionChecker below (deny by default)
    worker_directory: null         # service id; null = current security user only (team features show nothing)
    brand_provider: null           # service id of Nowo\Brand\Contract\BrandProviderInterface; null = container alias or neutral
    clock: null                    # service id of Psr\Clock\ClockInterface; null = native clock
    notifier: auto                 # auto (mailer if present, else null) | null | <service id>
    audit_sink: auto               # auto (AuditKit bridge if installed, else PSR log) | <service id>
    permissions:
        roles:                     # RoleMapPermissionChecker only; every list empty = denied (owner rule)
            clock: []              # e.g. [ROLE_USER]
            manage_team: []
            export: []
            manage_settings: []
            leave_request: []
            leave_approve: []
            leave_view_team: []
            leave_manage: []
            holidays_manage: []
        not_available_status: 404  # tenant without the feature / no tenant: 404 (fixed), missing permission: 403
    doctrine:
        entity_manager: nowo_working_time   # private EM (Flex recipe declares it); null = default EM with explicit transactions (Q-O3)
        table_prefix: nowo_
        mapping: xml               # xml | attribute
    integrity:
        triggers: warn             # warn | require (refuse enabling the register for a tenant while triggers are missing) | off (tests)
        purge_guard: session_variable   # session_variable | db_user
        purge_db_user: null        # required when purge_guard = db_user
        purge_connection: null     # DBAL connection used by the purger (db_user mode)
        verify_before_export: true
    register:
        enabled: true
        schedule:
            default_timezone: Europe/Madrid   # used by ConfiguredScheduleFallback and new tenants
            default: null          # { ranges: {mon: ['09:00-14:00','15:00-18:00'], ...} } | { daily_target_minutes, weekly_target_minutes, flex_minutes }
        missing_clock_out_grace_minutes: 120  # 30..480
        long_day_hours: 16
        late_client_minutes: 10
        correction_window_days: 31            # default per tenant (1..31)
        retention_years: 4                    # default per tenant; tenants may set 4..10; values < 4 are refused
        ip_evidence: prefix                   # off (never offered) | prefix (tenants may opt in, notice required)
        ipv4_prefix: 24                       # fixed range 24 (Q-O11)
        ipv6_prefix: 64
        reminders: { window_minutes: 15 }
        monthly_summary: { enabled: true }
        export:
            formats: [pdf, csv]               # pdf requires dompdf/dompdf or a custom renderer
            max_months: 12
            download_days: 7
            async: auto                       # auto (Messenger if available) | sync | messenger
        task_timer_link: auto                 # auto (TimeTrack bridge if installed) | off
    holidays:
        enabled: true
        http_client: null                     # service id; null = http_client scoped by the bundle
        outbound_guard: Nowo\OutboundUrlGuardBundle\Guard\RuntimeOutboundUrlGuard
        cache_pool: cache.app
        providers:
            festivos_io: { enabled: true, base_uri: 'https://festivos.io', timeout: 10, municipalities_ttl: 604800 }
            openholidays: { enabled: true, base_uri: 'https://openholidaysapi.org', timeout: 10, subdivisions_ttl: 86400 }
            file: { enabled: true, max_rows: 200, max_bytes: 262144 }
    leave:
        enabled: false                        # requires register.enabled and holidays.enabled
        lock:
            store: database                   # database (row lock in worker_lock) | lock_factory (symfony/lock) — Q-O2
            lock_factory: lock.factory        # service id when store = lock_factory (must be a shared store: Redis, PDO…)
            wait_seconds: 5                   # 1..30, then 409 + Retry-After
            ttl_seconds: 30
        approval:
            flow: auto                        # auto (Workflow bridge if installed) | builtin | <service id>
            workflow_slug: leave_approval
            max_chain_steps: 3
        attachments:
            enabled: false
            store: null                       # service id of LeaveAttachmentStoreInterface (StorageKit bridge when installed)
            max_bytes: 10485760
            mime_types: [application/pdf, image/jpeg, image/png, image/webp]
        ical:
            enabled: true
            cache_seconds: 900
        staffing: { enabled: true }
        planning_reminders: { days_before: [30, 7] }
        stale_pending_reminder_working_days: 3
        payroll_csv: { enabled: true }
    ui:
        enabled: false                        # routes are opt-in (REQ-UI-001)
        register_path: /working-time
        leave_path: /leave
        holidays_path: /holidays
        settings_path: /working-time/settings
        page_size: 25                         # 10..100
        layout: '@NowoUiKit/layout/app.html.twig'
    api:
        prefix: /api                          # container parameter nowo_working_time.api_prefix
        ical_path: /working-time/v1/ical      # canonical; the host may add permanent legacy aliases (§11.2)
    messenger:
        transport: null                       # routing hint for GenerateRegisterExportMessage / SendNoticesMessage
```

Container validation: `leave.enabled` needs `register.enabled` and `holidays.enabled`; `export.formats` with `pdf`
needs a renderer; `attachments.enabled` needs a store; `lock.store: lock_factory` needs `symfony/lock` and the
service; `retention_years` ≥ 4; `purge_guard: db_user` needs `purge_db_user`; UI paths distinct and starting with `/`.

Runtime settings (REQ runtime env / DB, 2026-11-16): none of the above is a typical `.env` value that changes at
runtime except the provider base URIs; they are registered as `RuntimeSettingDefinition`s with a bounded
`HttpsUrl` validator (`source: env` default). Per-tenant settings (enable, retention, IP evidence, chains…) live in the
bundle's own tables and pages, not in runtime settings.

## 11. Routes, UI and API

### 11.1 UI (opt-in, `ui.enabled: true`; route import with the host's prefix; route names `nowo_working_time_*`)

Built on UiKit scaffolds (page header with `dialogs` block, list page, settings page, keyset pager, empty states,
`_dialog`) and FormKit types (REQ-UI-001/002/003, REQ-DEMO-014/015); phone-first, no horizontal scroll, axe clean;
every POST CSRF-protected; every write controller `#[AsWriteUnit]` where replay-safe (§15.2).

| Area | Route (default path under `ui.*_path`) | Permission | Beacon today |
|---|---|---|---|
| Register | `GET /` today; `GET /month/{YYYY-MM}`; `GET /day/{uuid}`; `GET /date/{Y-m-d}`; `POST /clock/{action}` | `clock` (own) | `working_time_today`, `_month`, `_day`, `_date`, `_clock` |
| | `POST /date/{date}/correction`; `POST /corrections/{uuid}/answer` (acknowledge / contest) | `clock` (own) | `working_time_correction_request`, `_answer` |
| | `GET /team`; `POST /requests/{uuid}/{approve\|reject}`; `POST /day/{uuid}/record` | `manage_team` (subject) | `working_time_team`, `_correction_decide`, `_correction_record` |
| | `GET\|POST /schedules`; `POST /schedules/{id}/withdraw` | `manage_team` | `working_time_schedules`, `_schedule_withdraw` |
| | `GET\|POST /exports`; `GET /exports/{uuid}/download` | `export` (own copy: `clock`) | `working_time_exports`, `_export_download` |
| Settings | `GET\|POST /working-time/settings` (register + leave tabs) | `manage_settings` | `organizations_working_time`, `organizations_leave` |
| Preferences | `GET\|POST /working-time/preferences` | signed-in worker | `account_working_time_preferences` |
| Leave | `GET /` index; `GET /count` (UI JSON); `POST /requests`; `GET\|POST /requests/{uuid}/edit`; `POST /requests/{uuid}/cancel`; `GET /requests/{uuid}/attachment` | `leave_request` (own); attachment: owner or `leave_manage` | `leave_index`, `leave_count`, `leave_request_*`, `leave_attachment` |
| | `GET /approvals`; `POST /approvals/{uuid}/{approve\|reject}`; `POST /approvals/bulk` | `leave_approve` (subject) | `leave_approvals`, `leave_decide`, `leave_bulk_approve` |
| | `GET /calendar/{YYYY-MM}` | `leave_request` (colleague view) / `leave_view_team` | `leave_calendar` |
| | `GET /feeds`; `POST /feeds/{kind}/{rotate\|revoke}` | `leave_request` | `leave_feeds`, `leave_feed_action` |
| | `GET /manage`, types, allowances, on-behalf (`/manage/register`), overtime, payroll CSV | `leave_manage` | `leave_manage`, `leave_type_*`, `leave_allowances`, `leave_policy_remove`, `leave_on_behalf`, `leave_overtime*`, `leave_payroll` |
| Holidays | `GET\|POST /` calendars; `/{uuid}`; `/{uuid}/add`; `/{uuid}/import`; `/{uuid}/provider/{key}` + `/confirm`; `/{uuid}/holiday/{id}/{edit\|remove}`; `/{uuid}/remove`; `/centres/{uuid}` + `/remove`; `GET /municipalities` (UI JSON) | `holidays_manage` (Beacon maps it to `leave.manage`) | `leave_holiday*`, `leave_holidays`, `leave_centre_*`, `leave_municipalities`, `leave_holiday_{festivos,openholidays}*` |

Twig: `working_time_header_clock()` (header quick action: one button, next action + elapsed time, no layout shift, ≤ 1
query per page), `working_time_format_minutes()`. Stimulus controllers: clock button, leave day-count dialog,
municipality search. Translations in 7 locales; every string in the domain `NowoWorkingTimeBundle`.

### 11.2 Public HTTP (versioned)

| Path | Method | Auth | Notes |
|---|---|---|---|
| `<api.prefix>/working-time/v1/ical/{token}.ics` | GET | token in the path (stateless, no session) | `text/calendar`, `Cache-Control: private, max-age=900`, `X-Robots-Tag: noindex`, rate-limited per token hash; 404 for every failure. Hosts register **permanent** legacy aliases for subscribed URLs (Beacon: `/dashboard/leave/ical/{token}.ics`) through `ical_legacy_paths: []` (house rule: installed-client paths are permanent) |

No other public JSON API in 1.0 (Q-O10): clocking from desktop / extension goes through TimeTrackBundle's client API
(`/api/time-track/v1/workday…`, D-20261117-18) via the TimeTrack bridge. Internal UI JSON endpoints (`/count`,
`/municipalities`) keep the UI prefix and no version (house rule exemption). OpenAPI: the iCal endpoint is documented
in `docs/openapi/working-time-v1.yaml`.

### 11.3 Errors

`404` feature off for the tenant, no tenant, record of another tenant or worker (never 403 that reveals existence
across tenants); `403` missing permission inside an enabled tenant; `409` clock state conflict (body with the state),
stale decision (§15.3), final lock conflict (**409 + `Retry-After`**, transaction-doctrine responder, JSON
`{"error": "concurrency_conflict"}` / branded *Try again* page); `400` invalid request key or form; `422` validation in
JSON; `413` attachment too large.

## 12. Messenger and scheduled work

| Command / message | Schedule (recipe) | Notes |
|---|---|---|
| `nowo:working-time:verify [--tenant] [--worker]` | weekly | Exit 1 on a broken chain; `ChainBroken` event + audit |
| `nowo:working-time:purge [--dry-run]` | daily | §6.5; also expires exports and old notices |
| `nowo:working-time:notices` (`SendNoticesMessage`) | every 5 min | Builds due reminders / summaries / leave notices into the outbox (dedupe key) and delivers those whose `deliverable_from` ≤ now; bounded query budget per run; a leave decision notice never waits behind reminders (spec 241 T018) |
| `nowo:working-time:leave:daily` | daily | Carry-over expiry, planning reminders, stale pending reminders |
| `nowo:working-time:leave:attachments-purge` | daily | Deletes attachments `attachment_retention_months` after the leave ends; audit summary |
| `nowo:working-time:triggers:{install,check,uninstall}` | install / deploy | §6.4 |
| `nowo:working-time:schema-check` | CI / deploy | Columns, collations, indexes, triggers |
| `GenerateRegisterExportMessage` | on request | Claims the export row (`pending → running` conditional UPDATE), verifies chains, renders, stores, `RegisterExportReady` after commit |

Handlers run inside transaction-doctrine's `WriteUnitMiddleware` (replay on deadlock / lock-wait timeout).

## 13. Permission matrix (deny by default; owner fixed point)

`WorkingTimePermission`: `Clock` (worker), `ManageTeam` (manager), `Export` (exporter), `ManageSettings`,
`LeaveRequest`, `LeaveApprove`, `LeaveViewTeam`, `LeaveManage`, `HolidaysManage`. Every check passes through the
tenant resolver first (no tenant or feature off ⇒ 404), then `isGranted()` (false ⇒ 403). The default checker grants
nothing until roles are configured. Beacon maps them to `organization.working_time.{clock,manage_team,export}`,
`organization.time_track.settings`, `organization.leave.{request,approve,view_team,manage}`.

| Action | Clock | ManageTeam | Export | ManageSettings | LeaveRequest | LeaveApprove | LeaveViewTeam | LeaveManage | HolidaysManage |
|---|---|---|---|---|---|---|---|---|---|
| Clock in / out, own day / month, own corrections, own monthly PDF / CSV | ✓ | | | | | | | | |
| See team register, decide / record corrections, schedules | | ✓ (subject: managed worker; never own corrections) | | | | | | | |
| Inspection export (worker / team, ≤ 12 months) | | | ✓ | | | | | | |
| Register / leave settings, IP evidence, retention | | | | ✓ | | | | | |
| Own leave: request, edit, cancel, balance, personal iCal, colleague calendar (*Absent*) | | | | | ✓ | | | | |
| Decide leave (current step, managed workers, never own), bulk approve | | | | | | ✓ | | | |
| Team calendar with types (not medical), team iCal | | | | | | (own workers) | ✓ | | |
| Types, allowances, adjustments, on-behalf (never for oneself), overtime ratification, payroll CSV, medical type label and documents | | | | | | | | ✓ | |
| Holiday calendars, imports, providers, work centres | | | | | | | | | ✓ |

Visibility rules not expressible as a column: medical type label and documents only to `LeaveManage` (and the worker
for their own); comments only to the worker, their approvers and `LeaveManage`; attachments never in calendar, iCal or
exports; the inspection export shows *Absence – justified* for medical.

## 14. Privacy and GDPR

### 14.1 Legal basis and notice

Register: art. 6.1.c GDPR (art. 34.9 ET), kept apart from task time (6.1.b / f). Leave: 6.1.b / 6.1.c. The LegalKit
bridge (or `WorkingTimeLegalTextInterface`) provides the paragraphs; the IP-evidence switch requires the tenant's
notice text before it can be turned on.

### 14.2 Data minimisation

No geolocation of any kind. IP evidence only as `/24` or `/64` prefix, per segment, only while the tenant enabled it,
shown to the worker and in exports. Reminders and notices only inside the worker's schedule. Worker names are copied
into the register (`worker_name`) so the record survives account erasure until retention.

### 14.3 No health detail anywhere in leave (owner fixed point)

- Medical types store only dates, amount, `justified` and the optional document reference (only when the tenant
  enabled documents). `comment` is refused (`leave.request.error.medical_comment`) and hidden for medical types; other
  comments carry the hint "do not include health information".
- No type attribute, field, filter, export column, audit key, notice parameter or iCal property may carry a
  diagnosis, cause or health detail. `HealthFieldGuardTest` enumerates every mapped column, form field, CSV column,
  notice parameter and audit key of `Leave\*` and fails on anything beyond the allow-list (spec 204 SC-002).
- Calendar, iCal and exports show *Absent* / *Absence – justified* for medical to anyone without `LeaveManage`.

### 14.4 Export and erasure (UserKit bridge, or `WorkingTimePersonalData` for other hosts)

| Data | Export (`working_time`, `leave` sections) | Erasure |
|---|---|---|
| Workdays, segments, revisions, corrections | Own rows (dates, times, sources, IP prefixes, corrections, decisions) | **Kept** until retention (art. 17.3.b); `worker_name` kept in the register only |
| Exports | Metadata of own exports | Files deleted; rows kept until expiry |
| Preferences, feed tokens | Yes / existence only | Deleted |
| Leave requests, days, decisions, allowance lines, overtime ledger | Own rows | Kept until retention (register-linked); `comment` blanked; `on_behalf_reason` blanked |
| Attachments | Metadata; files on request in the export ZIP | Deleted |
| Actor references on other people's rows (`decided_by_id`, `author_id`, `created_by_id`) | Not exported to the actor | `*_id` kept (legal trail), names kept in the register; outside the register set to NULL |

Export ↔ erasure parity test. Lookups by worker re-check `===` (rule LQ).

### 14.5 Retention

Register and leave rows: `retention_years` (4–10, default 4) after the end of the month (§6.5). Attachments: 1–48
months after the leave ends (default 12). Exports: 7 days. Notices: 400 days after delivery. Chain anchors: kept
(they hold only hashes and counts).

## 15. Concurrency and locking

All writes run in transaction-doctrine write units (`WriteUnitInterface`; `#[AsWriteUnit]` on controllers,
`WriteUnitMiddleware` for handlers): a deadlock (1213 / 40P01) or lock-wait timeout (1205 / 55P03) is replayed up to 3
times with full jitter; only the outermost unit replays; side effects (events, notices, mails) after commit. A final
conflict answers **409 + `Retry-After`** through `ConcurrencyConflictResponder` (D-20261117-15; transaction-doctrine
`conflict_response.status: 409`, the 1.3.0 default). Every bundle exception that means "retry" implements
`LockConflictExceptionInterface`; no route-name matching.

### 15.1 Leave-race fix (mirrors Beacon spec 241 FR-003 – FR-005; owner fixed point)

- **Per-worker lock around validate + persist** for `create()`, `edit()` and `registerOnBehalf()`:
  `LeaveWorkerLockInterface::run(TenantRef, WorkerRef, callable)`:
  - `database` store (default, Q-O2): inside the write unit's transaction, `INSERT … ON DUPLICATE KEY UPDATE` /
    `ON CONFLICT DO NOTHING` the `(tenant_id, worker_id)` row of `worker_lock`, then `SELECT … FOR UPDATE` it; held
    until commit / rollback (no leak on a crash or a killed worker). Wait bounded by `wait_seconds` through the
    session lock timeout (`innodb_lock_wait_timeout` / `SET LOCAL lock_timeout`), restored after the unit.
  - `lock_factory` store (Beacon's design): `LockFactory::createLock('nowo_working_time.leave.'.sha256(tenant|worker),
    ttl)`, `acquire(true)` bounded by `wait_seconds` (polling), released in `finally`, the transaction opened and
    committed **inside** the lock.
  - After acquiring, the service **reloads** the worker's requests, balance and overtime credit (EM cleared) and
    validates again: a second concurrent call sees the first request and is refused for overlap or balance.
  - Lock wait exhausted ⇒ `LeaveLockTimeoutException implements LockConflictExceptionInterface` ⇒ 409 +
    `Retry-After`; nothing stored.
  - An approve-on-submit type stores exactly one approved request under a double click (idempotency also via the
    form's CSRF + a request key on the dialog).
- **Version on decisions:** `LeaveRequest.version` (ORM `version="true"`) is posted with every decision (hidden field
  / JSON). `decide()` loads the request with `LockMode::OPTIMISTIC` and the expected version; a mismatch, or Doctrine's
  `OptimisticLockException` at flush, ⇒ `StaleLeaveDecisionException implements LockConflictExceptionInterface`:
  **409 + `Retry-After`**, nothing stored, **no notice**, HTML re-renders the approvals inbox with the flash *Someone
  decided this request at the same moment* (status 409), JSON `{"error": "leave_decision_stale"}` (Q-O3a). Each
  `LeaveDecision` stores `request_version`. Bulk approve sends `{id, version}` pairs; a stale one is skipped and
  reported, the others commit.
- **On behalf for oneself** refused (`leave.request.error.on_behalf_self`); the on-behalf worker list omits the actor.

### 15.2 Register

- Clock actions: unique `(tenant, worker, seq)` and `(tenant, worker, request_key)`; a racing second action fails on
  the unique key and the write unit replays it: the replay sees the new state and either answers it (same request key)
  or applies the next action / answers 409 with the state. ≤ 6 queries, < 150 ms p95 (spec 198 SC-003).
- Corrections: `version` on `working_time_correction`, same stale rule as leave decisions
  (`StaleCorrectionDecisionException`, `{"error": "correction_decision_stale"}`).
- Settings (register and leave): `version`, stale ⇒ 409 with the form re-rendered.
- Exports: claim by `UPDATE … SET status = 'running' WHERE id = ? AND status = 'pending'` (one generator wins).
- Notices: unique `dedupe_key`; delivery marks `sent_at` with `WHERE sent_at IS NULL`.
- Holiday confirm: unique `(calendar_id, holiday_date)`; duplicates skipped.
- Purge: one transaction per batch; never runs twice at once (`worker_lock` row `('__purge__', '__purge__')` or a
  `LockFactory` lock).

### 15.3 Isolation

The bundle assumes READ COMMITTED (REQ-DB-003) and short lock waits; every lock is taken in a fixed order (worker lock,
then rows), so the leave path cannot deadlock against itself.

## 16. Worker mode (FrankenPHP, kernel reset false; REQ-RUNTIME-002)

- No tenant, worker, permission, brand or schedule data in service properties or statics; every port is asked per
  call. The only caches are PSR-6 pools for **public** data (municipality list, OpenHolidays subdivisions), keyed by
  provider and base URI, never by person.
- Private EM `nowo_working_time`: `clear()` after every write unit and read batch; a closed EM is detected and reset
  (transaction-doctrine `DoctrineStateRepairer`); no managed entity survives a request; transactions open and close in
  the call.
- MySQL / MariaDB purge flag cleared in `finally` and on every connect (`PurgeFlagResetMiddleware`); PostgreSQL uses
  `SET LOCAL`. Leave locks released in `finally` (lock_factory) or at commit (database).
- After-commit event buffer and notice buffer are per call (local variables passed down), not service state; they also
  implement `ResetInterface` with a test, never relied on alone.
- HTTP clients and Dompdf instances are created per call (Dompdf keeps state); temporary chroot directories removed in
  `finally`.
- Tests: 2 000 requests in one kernel alternating two tenants and two workers (clock, leave create, calendar, iCal,
  export download) assert flat memory and no crossed data; Igor and `phpstan-frankenphp` worker rules green.

## 17. Standard vs DDD host (REQ-ARCH-001)

| Topic | Standard Symfony host | DDD / hexagonal host |
|---|---|---|
| Entities | Bundle-owned, XML mapping by default; nothing to extend | Same; host references by `*Id` VOs; attribute twins if the host prefers |
| Users / tenants | `CurrentUserWorkerDirectory` + `SingleTenantResolver` or a few-line adapter | Host adapters over its own read models |
| Flush | Bundle never flushes the host EM (rule 6): private EM + explicit transaction | Same |
| Events | Symfony dispatcher, after commit, ids only | Same; host maps to its own bus |
| Templates | UiKit layout, Twig overrides by bundle path | Host may disable `ui` and call the application services |

Both host kinds run the same functional suite (standard fixture: attribute `User`, ORM; DDD fixture: XML-mapped user
on another EM, custom resolver and checker).

## 18. Migration path from Beacon (spec 241 T053)

### 18.1 What Beacon deletes (line counts `wc -l`, `286c916cd`)

| Path | Lines |
|---|---|
| `src/WorkingTime/{Entity,Enum,Integrity,Register,Schedule,Service,Correction,Export,Notice,Message,Repository,Settings,Event,Twig,Command,Form,Security}` | 7 579 |
| `src/WorkingTime/Controller/*` (except `LegacyWorkingTimePathController`, kept as a Beacon redirect) | ≈ 870 |
| `src/WorkingTime/Client/BeaconWorkdayProvider.php`, `EventListener/TaskTimerLinkListener.php` | 139 |
| `src/WorkingTime/Leave/{Entity,Enum,Model,Repository,Service,Holiday,Workflow,PersonalData,Command,Form,Security}` | 7 930 |
| `src/WorkingTime/Leave/Controller/*` | 1 733 |
| `src/WorkingTime/Leave/Client/*` | 284 |
| `templates/working_time/*`, `templates/leave/**` | 2 176 |
| WorkingTime / Leave unit and functional tests (moved into the bundle's suite; Beacon keeps e2e `e2e/organization/leave*.spec.ts` and the register e2e as regression) | ≈ 6 300 |
| **Total** | **≈ 27 000** (17 669 PHP in `src/WorkingTime`) |

**Beacon keeps (adapters and glue, est. ≈ 600 lines):** `BeaconWorkingTimeTenantResolver` (`OrganizationContext`),
`BeaconWorkingTimePermissionChecker` (organization permission keys, `OrganizationVoter`, managed-worker rule),
`BeaconWorkerDirectory` (`BillingAccountMember`, `UserGroupMembership`, `User`), `BeaconScheduleFallback` (spec 197
`WorkSchedule` + `WorkingHoursResolver`), the existing brand provider, `BeaconWorkingTimeNotifier` (inbox +
`BrandedEmailComposer` + Slack DM until NotifyKit), `BeaconLeaveAttachmentStore` (`StorageProfiles`, until
StorageKit), `BeaconWorkingTimeAuditSink` (`UserActionRecorder`, same `working_time.*` keys), menu entries, permission
catalogue entries, `LegacyWorkingTimePathController`, the iCal legacy alias, and the three seed appliers
(`Setup/OrganizationSeed/Applier/{WorkingTimeApplier,LeaveApplier,WorkCentreSeeder}`) rewritten to call
`RegisterSettingsEditorInterface` / `LeaveAdministrationInterface::seedTypes()` / `HolidayCalendarServiceInterface`.
`src/TimeTrack/WorkingTime/ExpectedHours.php` and `Notifications/Inbox/NotificationType.php` switch their imports to
the bundle's public interfaces.

### 18.2 Data migration (one Beacon migration, maintenance mode on)

1. `nowo:working-time:verify` on the **old** tables through a read-only port of the verifier shipped for this purpose
   (`LegacyBeaconChainReader`, format 1): must report zero broken chains; record every chain head.
2. Create bundle tables; copy with `INSERT … SELECT` (no UPDATE ever touches protected rows):
   - ids: rows with a Beacon `uuid` keep it as `id` (workday, segment, revision, correction, export, leave request,
     leave type, holiday calendar, work centre, allowance line); rows without (`decision`, `request_day`, `holiday`,
     `policy`, `overtime`, `notice`, `schedule`, `feed`) get UUIDv7 in the copy; int foreign keys become UUIDs by join
     (`leave_request_day.request_id`, `holiday.calendar_id`, `policy.leave_type_id`, `schedule.work_centre_id`,
     `work_centre.calendar_ids` json, `leave_settings.approval_chains` / `min_staffing` group and user ids);
   - refs: `organization_id` ⇒ `tenant_id`, `user_id` ⇒ `worker_id`, `*_by_id` ⇒ actor refs, in the form chosen in
     Q-B1 / Q-B2 (recommended: organization and user UUIDs, by join on `billing_account` / `user`);
   - revisions: `chain_format = 1`, `legacy_refs = {organization, user, workday, author}` (Beacon ints), `prev_hash`
     and `hash` copied unchanged; workday `legacy_id` = Beacon int id;
   - `leave_request.version = 1` (Beacon's T004 column, if already shipped, copied as is); `correction.version = 1`;
   - festivos.io / OpenHolidays calendars keep provider, municipality, attribution.
3. Install triggers on the bundle tables (`nowo:working-time:triggers:install`); run `verify` on the new tables; chain
   heads must equal step 1; row counts per table must match (migration aborts otherwise and leaves the old tables as
   the source of truth).
4. Switch config and routes; old tables are renamed `*_legacy_<date>` (RENAME keeps their triggers on MySQL) and
   dropped one release later (DROP does not fire row triggers).
5. WorkflowBundle: update `leave_approval` definitions' `subject_class` to the bundle's `LeaveRequest`; markings are
   copied into `leave_request.marking` (Q-B7).
6. iCal tokens: hashes copied, so subscribed feeds keep working on the legacy alias.

### 18.3 Adoption check (Beacon side)

Beacon's WorkingTime / Leave functional suites run unchanged against the bundle (through Beacon's adapters) before the
deletion PR; the per-profile e2e suites stay green; chain verifier passes on migrated data; spec 241 T053 closes and
`OVERRIDES-INVENTORY.md` / the request rows KIT-77 / KIT-78 close with the bundle version.

## 19. Phased delivery

Batch-audit rule 2026-11-07: immediate SEC-004 audit only for auth, crypto, permissions, data loss. S1 (integrity
triggers, permissions, purge = data loss) and S3 (permissions, health data, leave race) are **immediate**; S2 batched.

| Slice | Version | Content | Size | Done when | Audit |
|---|---|---|---|---|---|
| **S0** scaffold | `main`, no tag | Composer, Docker (MySQL 8.4, MariaDB 11.4, PostgreSQL 17, SQLite), Makefile + release-check, CI matrix SF 7.4 / 8.0 / 8.1 × 4 DBs, PHPStan + `phpstan-frankenphp`, Igor, Rector, CS, coverage gate, Spec Kit baseline, docs set, Flex recipe (private EM), demo skeleton (UiKit, FormKit, FrankenPHP worker), strict config tree | **S** (1–2 days) | `make release-check` green; scaffold REQ checklist 100 %; owner registers Packagist | Batched |
| **S1** register | 0.1.0 | Domain + mapping for §6.2, triggers ×4 platforms + installer / check commands, chain formats 1 + 2, anchors, verifier, `TimeClock`, schedules + fallback port, corrections (+ version), settings (+ version), ports §8.1 + clock / audit / notifier, write units, register UI (today, month, day, team, schedules, settings, preferences, header clock), exports (PDF Dompdf, CSV, ZIP, Messenger), monthly summary, reminders, notices outbox, IP prefix, purge + anchors, TimeTrack bridge (workday source, timer link), UserKit export / eraser (register half), LegalKit text | **L** (≈ 2 weeks) | Spec 198 SC-001…SC-006 reproduced in the bundle suite; trigger tests on 4 DBs; tamper test; worker N-request test; both host kinds | **Immediate** |
| **S2** holidays + leave foundations | 0.2.0 | Holiday component (calendars, days, work centres, providers festivos.io / OpenHolidays / file, municipality directory, reader interface, UI), leave types + seeding, allowance policies, lines, pro-rata, carry-over (+ protected, expiry job), `DayCounter`, balances, register day annotations for holidays | **M** (≈ 1 week) | `DayCounter` fixture year 100 % (spec 204 SC-001); provider tests on recorded fixtures; stand-alone holiday demo page without register / leave | Batched |
| **S3** leave workflow → 1.0 | 0.3.0 → **1.0.0** | Requests (create / edit / cancel / on-behalf) with the **per-worker lock** and **version** (§15.1), approval chain + built-in flow + Workflow bridge, approvals inbox + bulk, staffing warning, planning reminders, team calendar, iCal (v1 path + legacy aliases), attachments (StorageKit bridge), overtime ratification + compensatory, payroll CSV, leave notices, register integration (leave days), TimeTrack leave / days-off bridges, TaskBoard bridge, leave GDPR, health-field guard, translations ×7, docs (USAGE, CONFIGURATION, UPGRADING, LEGAL, FRANKENPHP-WORKER-AUDIT, MIGRATION-FROM-BEACON), demo e2e, `bundle-req-audit` 100 %, SEC-004 batch on S0–S3 | **L** (≈ 2 weeks) | Spec 204 SC-001…SC-006 and spec 241 FR-003 – FR-005 tests green; per-profile e2e; REQ audit 100 %; owner OK | **Immediate** (S3 content), batch for the rest |
| Beacon adoption | after 1.0.0 (or 0.3.0 by agreement) | §18, spec 241 T053 | **L** (Beacon side) | §18.3 | Beacon's own |

Dependencies: S1 needs transaction-doctrine **1.3.0** tagged (write units + 409 responder); until then S1 develops
against the branch and does not tag. TimeTrack bridge needs no TimeTrack change (interfaces exist).

## 20. Test plan (coverage gate ≥ 99 % PHP, ≥ 90 % TS)

- **Integrity, per platform (CI services MySQL 8.4, MariaDB 10.11 + 11.4, PostgreSQL 14 + 17, SQLite):** UPDATE of
  workday / revision / anchor refused; segment close allowed exactly once, any other column change refused; DELETE
  refused without the purge flag, allowed with it; PostgreSQL TRUNCATE refused; purge flag gone after `finally` and
  after reconnect (MySQL / MariaDB), gone after commit (PostgreSQL `SET LOCAL`); `db_user` mode; installer rollback on
  missing privilege.
- **Chain:** format 1 vectors taken from Beacon fixtures hash identically; format 2 golden vectors; tamper (payload,
  times, deleted link, reordered seq, deleted head without anchor) detected; anchor accepted only when recorded; 10 000
  links < 5 s; flat memory.
- **Architecture:** no update / delete path on protected repositories; layer rules (§4); no Symfony / Doctrine types in
  `Domain`.
- **Clock:** state machine table; idempotent key (byte-exact, invalid keys refused); late flag; never earlier than last
  time; missing clock-out; split shift; DST transitions in `Europe/Madrid` and `Atlantic/Canary`; day crossing midnight.
- **Concurrency (two DBAL connections in one test; and a `pcntl` fork test on MySQL / PostgreSQL in CI):** two creates
  for the same worker, one waits and is refused for overlap / balance; approve-on-submit double click stores one;
  lock wait exhausted ⇒ 409 + `Retry-After`, nothing stored; two decisions on the same version ⇒ one stored, the
  other 409, exactly one notice queued; stale correction decision; clock seq race replayed; both lock stores.
- **Leave rules:** `DayCounter` table (national + regional + local holidays, part-time, half-days, natural vs working,
  schedule change mid-request, year split refused); pro-rata and rounding; carry-over expiry; protected lines; overlap;
  overtime credit; staffing warnings; planning reminders; on-behalf-self refused.
- **Holidays:** festivos.io / OpenHolidays responses from recorded fixtures (`MockHttpClient`), errors (404, timeout,
  bad body, guard refusal, redirect refused), provisional flag, attribution; CSV / ICS parser statuses and limits;
  municipality search reads cache only.
- **Permissions:** every route × profile (guest, no tenant, feature off, worker, manager of another group, manager,
  exporter, settings admin, leave approver, team viewer, leave manager, holidays manager) ⇒ expected 302 / 404 / 403 /
  200; default checker denies everything; approver never decides own; cross-tenant ids ⇒ 404.
- **Privacy:** health-field guard; medical label hidden in calendar, iCal, export, notices for non-managers; comment
  refused on medical; IP prefix only when enabled; no notice outside schedule; export ↔ erasure parity; collation
  test `usera` vs `UserA` never cross (MySQL / MariaDB).
- **Exports:** PDF for a 23-workday month < 10 s and SHA-256 matches the stored one; CSV stable columns (golden file),
  formula injection neutralised; ZIP `SHA256SUMS.txt`; download after 7 days ⇒ 404.
- **Retention:** purge at the boundary month (kept / deleted), < 4 years refused, anchors written, audit summary,
  attachments purge.
- **Worker mode:** §16 N-request test; `ResetInterface` tests; Igor.
- **Both host kinds:** §17 fixtures run the functional suite.
- **Migration:** a Beacon fixture dump (MySQL) migrated by the documented SQL; chains verify with the same heads; row
  counts equal; iCal legacy token works.
- **UI / e2e (demo):** Playwright per profile (clock, correction request / approval, export, leave request / approve /
  reject / cancel / on-behalf, calendar visibility, iCal revoke, holiday import preview), axe clean, phone width without
  horizontal scroll, WebP screenshots (REQ-DEMO-013).

## 21. Questions

### For the owner (Q-O)

| # | Question | Recommendation | If not |
|---|---|---|---|
| Q-O1 | Holiday component: inside this bundle (namespace `Nowo\WorkingTimeBundle\Holiday`, usable with `register` and `leave` off), with an architecture test so a later split into `holiday-calendar` is mechanical? | **Yes** (your fixed point: one bundle); AD / CRM agendas install the bundle with `register.enabled: false, leave.enabled: false` and use `HolidayCalendarReaderInterface` | Separate package now (`nowo-tech/holiday-calendar`), required by this bundle |
| Q-O2 | Default backend of the per-worker leave lock: a database row lock (`worker_lock` + `SELECT … FOR UPDATE`, no extra infrastructure, released at commit) with `symfony/lock` as an option (Beacon's Redis design)? | **Yes, database by default**; Beacon may choose `lock_factory` (Q-B4). Both give the same guarantee: validate + persist under one per-worker lock | `symfony/lock` required (needs a shared store in every app) |
| Q-O3 | Entity manager: the Flex recipe declares a private EM `nowo_working_time` (recommended by ARCH-001), with `doctrine.entity_manager: null` allowed (default EM, explicit transactions, bundle entities detached)? | **Yes** | Private EM mandatory (container error without it) |
| Q-O3a | A stale leave / correction decision answers like a lock conflict: **409 + `Retry-After`**, nothing stored, no notice; HTML shows the inbox again with the flash (status 409), JSON `leave_decision_stale`? | **Yes** (one shape for every conflict, D-20261117-15; the flash tells the person to reload) | 409 without `Retry-After` for stale versions (a retry of the same version cannot succeed) |
| Q-O4 | Migrated chains keep Beacon's hash encoding (format 1, integer ids frozen in `legacy_refs`); new links use format 2 with string refs and chain onto the last Beacon hash. No re-hashing of the record? | **Yes**: re-hashing a legal record destroys the evidence it protects | Re-hash everything into format 2 with a signed "migration link" (not recommended) |
| Q-O5 | Purge exception on MySQL / MariaDB: session variable (Beacon's, default) with a `db_user` option (a dedicated purge DB user checked by the trigger)? PostgreSQL always uses `SET LOCAL` | **Yes**; the hash chain + anchors are the tamper evidence, triggers stop accidents and ad-hoc SQL | `db_user` mandatory (every app needs two DB users) |
| Q-O6 | When the DB user cannot create triggers (binlog privileges, shared hosting): `integrity.triggers: warn` by default (register works, settings page and `schema-check` warn, Beacon's documented fallback), `require` available? | **`warn`** default; Beacon and production apps set `require` once the privilege is granted | `require` by default (the register cannot be enabled without triggers) |
| Q-O7 | Medical types: refuse any free-text comment (server and form), and show a "no health information" hint on all other comments? | **Yes** (your rule "no health detail anywhere in leave"; Beacon allows a comment today) | Keep the comment with the hint only |
| Q-O8 | OutboundUrlGuardBundle as a **required** dependency (holiday fetches only), and providers fetched only on an admin action (preview → confirm), never automatically? | **Yes** | Optional guard with a basic built-in check |
| Q-O9 | PDF engine: Dompdf in `suggest` with a closed default renderer behind `RegisterPdfRendererInterface` (PDF disabled without it), switching to ReportKit (221-C) when it ships? | **Yes** | Dompdf required; or PDF only through the host |
| Q-O10 | Public API in 1.0: only the iCal feed at `<prefix>/working-time/v1/ical/{token}.ics` (+ permanent legacy aliases); clocking stays in TimeTrackBundle's `/api/time-track/v1`? A read API (own month, balances) waits for 1.x | **Yes** | Add `<prefix>/working-time/v1` (register month, leave balance, request create) in 1.0, off by default, ApiToken auth |
| Q-O11 | IP evidence prefix: `/24` IPv4 and `/64` IPv6 (spec 198, the worker notice text), fixed, not configurable? | **Yes** (fixed sizes keep the notice true) | Configurable within `/16–/24` and `/48–/64` |
| Q-O12 | Retention: tenant setting 4–10 years (default 4), deletion (not anonymisation) at the end, chain anchors kept? | **Yes** (spec 198, D-20261104-09) | Keep anonymised aggregates after the term |
| Q-O13 | Include overtime ratification / compensatory time off and the payroll CSV in 1.0 (Beacon uses both; out-of-scope list of spec 204 was written before them)? | **Yes**, S3 | Leave for 1.1 (Beacon keeps them until then — blocks the deletion) |
| Q-O14 | Releases: 0.1 / 0.2 / 0.3 per slice, **1.0.0** after `bundle-req-audit` 100 % and SEC-004; S1 and S3 audited immediately (integrity, permissions, purge, health data)? | **Yes** | One 1.0.0 at the end (Beacon adopts later) |
| Q-O15 | Default permission checker: role map from config, **all lists empty = deny**, and the container logs a warning (not an error) when `ui.enabled` and no role is mapped? | **Yes** (deny by default, never a silent allow) | Container error until a checker or roles are configured |

### For Beacon (Q-B)

| # | Question | Recommendation |
|---|---|---|
| Q-B1 | `TenantRef` for an organization: the organization UUID or the integer id as a string? | **UUID** (stable, already in URLs and exports); integer ids survive only in `legacy_refs` for format-1 links |
| Q-B2 | `WorkerRef`: the user UUID? | **UUID** (as NotifyKit Q-B4) |
| Q-B3 | Table names: bundle defaults `nowo_working_time_*` / `nowo_holiday_*` with the copy migration of §18.2, old tables renamed `*_legacy_<date>` and dropped one release later? | **Yes** (no ALTER / UPDATE on rows the triggers protect) |
| Q-B4 | Leave lock backend: keep your Redis `LockFactory` (`leave.lock.store: lock_factory`) or the bundle's database row lock? | **Database** (no Redis dependency for a legal-record path; same guarantee); `lock_factory` if you prefer parity with T003 |
| Q-B5 | Ship spec 241 T003 – T005 in Beacon 1.163 as planned (the bundle is weeks away) and keep the exact semantics and translation keys (`leave.request.error.on_behalf_self`, stale flash) so the bundle reuses your test cases? Please send the final keys and the `version` migration when merged | **Yes** |
| Q-B6 | PDF: drop your `PdfReportRenderer` path for the register and use the bundle's Dompdf renderer (same closed options), or implement `RegisterPdfRendererInterface` with yours? | **Bundle renderer**; your report engine stays for Dashboard → Reports |
| Q-B7 | Workflow: the bridge uses `WorkflowRuntimeInterface` with subject = the bundle's `LeaveRequest`; you update `leave_approval` definitions' subject class and copy markings in the migration. Does WorkflowBundle 1.15's per-organization runtime (`WorkflowTenancy`) flush its own EM only (never the caller's)? If not, a WorkflowBundle fix comes first | **Yes**, confirm the flush behaviour |
| Q-B8 | Notifications: implement `WorkingTimeNotifierInterface` with your inbox + branded mail + Slack DM until NotifyKit, then switch to the NotifyKit bridge? | **Yes** |
| Q-B9 | Routes: mount the UI at `/dashboard/working-time`, `/dashboard/leave`, `/dashboard/leave/manage/holidays`; route names become `nowo_working_time_*` (you update menu and links); `LegacyWorkingTimePathController` stays yours; iCal legacy path `/dashboard/leave/ical/{token}.ics` registered as a permanent alias? | **Yes** |
| Q-B10 | Export files: keep the database blob store (7-day files) or StorageKit? | **Database blob** (default), as today |
| Q-B11 | Leave attachments: implement `LeaveAttachmentStoreInterface` on your `StorageProfiles` private profile until you adopt StorageKit? | **Yes** |
| Q-B12 | Schedule fallback: implement `ScheduleFallbackProviderInterface` over spec 197 `WorkSchedule` + `WorkingHoursResolver` (organization hours, then instance default)? | **Yes** |
| Q-B13 | Audit: implement `WorkingTimeAuditSinkInterface` with `UserActionRecorder`, keeping the `working_time.*` / `working_time.leave_*` keys and `UserActionType` cases? | **Yes** |
| Q-B14 | Migration window: maintenance mode during copy + verify (minutes for current volumes), verify before and after with equal chain heads, abort keeps the old tables live? | **Yes** |
| Q-B15 | Permission mapping: `HolidaysManage` ⇒ `organization.leave.manage`, `ManageSettings` ⇒ `organization.time_track.settings`, the rest 1:1; "manages the worker" = your group-manager rule used today by `LeaveApprovers` and the team views? | **Yes** |

## 22. Answers

_Empty until the owner and Beacon answer. Recorded here with the date; the contract version moves to v1.0 when every
question has an answer._

---

## Answers received (2026-11-18)

**Owner Q-O:** all 15 recommendations accepted, Q-O3a included.

**Beacon Q-B:** recommendations accepted, with these refinements.
- **Q-B4:** Beacon wires the `lock_factory` option with key `leave:<org>:<user>`, for parity with spec 241 T003. The DB row lock stays the bundle default for other hosts.
- **Q-B5:** spec 241 T003–T005 ship in Beacon 1.163 with the same semantics. Beacon will send the final translation keys and the commit; mirror them.
- **Q-B6:** Dompdf is accepted on two conditions:
  - the brand comes from BrandProviderInterface;
  - rendering is closed: no remote resources, no JS, no PHP (REQ-REPORT-001).
- **Q-B7:** verify on the WorkflowBundle side that `WorkflowRuntimeInterface` never flushes the caller's entity manager. Beacon does not rely on it.
- **Q-B9:** Beacon keeps its own old route names as aliases on its side (breadcrumbs, menus, manual tabs).
- **Q-B15:** the mapping is accepted. Beacon's group-manager rule will be confirmed later and does not block the build.
