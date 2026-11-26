# Code inventory - 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/working-time-bundle`  
**Last audited**: 2026-11-26 (v0.4.0, manual review: every file read, one row per file with what it does)

Scope: every file under `src/` (PHP, Doctrine XML mappings, Twig views, translations). There is no build output
under `src/` (no Vite, no `dist/`). Audit command: `find src -type f | wc -l` → **339**.

Requirement IDs are defined in [`spec.md`](spec.md#requirements). FR-ARCH-001 (layer rules) has no file of its own:
it is enforced over the whole tree by `deptrac.yaml` and `tests/Architecture`.

## Table of contents

- [Bundle entry and DI](#bundle-entry-and-di)
- [Shared kernel](#shared-kernel)
- [Domain ports](#domain-ports)
- [Register domain: model and enums](#register-domain-model-and-enums)
- [Register domain: events and exceptions](#register-domain-events-and-exceptions)
- [Register domain: services, read models and hash chain](#register-domain-services-read-models-and-hash-chain)
- [Holiday domain](#holiday-domain)
- [Leave domain](#leave-domain)
- [Application: register](#application-register)
- [Application: holidays](#application-holidays)
- [Application: leave](#application-leave)
- [Application: shared](#application-shared)
- [Bridges](#bridges)
- [Doctrine storage](#doctrine-storage)
- [Doctrine XML mappings](#doctrine-xml-mappings)
- [Symfony adapters: default port implementations](#symfony-adapters-default-port-implementations)
- [Symfony UI: controllers, routing, HTTP, Twig extension](#symfony-ui-controllers-routing-http-twig-extension)
- [Symfony forms](#symfony-forms)
- [Console commands](#console-commands)
- [Exports and Messenger](#exports-and-messenger)
- [Twig views](#twig-views)
- [Translations](#translations)
- [Coverage summary](#coverage-summary)

## Bundle entry and DI

| Source file | What it does | Requirement IDs |
| --- | --- | --- |
| `src/Infrastructure/Symfony/DependencyInjection/Compiler/PrivateEntityManagerPass.php` | Compiler pass that checks the entity manager named by `doctrine.entity_manager` exists (the recipe declares `nowo_working_time`), so a missing declaration fails the container build with the fix. | FR-EM-001 |
| `src/Infrastructure/Symfony/DependencyInjection/Compiler/TwigPathsPass.php` | Registers the bundle templates under the `@NowoWorkingTimeBundle` Twig namespace and the host override directory `templates/bundles/NowoWorkingTimeBundle/` ahead of them; the automatic `@NowoWorkingTime` namespace stays as legacy alias. | FR-TWIG-001 |
| `src/Infrastructure/Symfony/DependencyInjection/Compiler/WorkingTimePortsPass.php` | Points each port alias at the host service, the default or an installed bridge, and wires the write unit and conflict policy, the closed Dompdf renderer (a `pdf` format without one fails the build), Messenger and the TimeTrack link. | FR-PORT-001, FR-BRIDGE-001 |
| `src/Infrastructure/Symfony/DependencyInjection/ConfigurationTree.php` | Defines the strict `nowo_working_time` configuration tree (unknown keys fail, every key has its contract section 10 default) for the parts, register, holidays, leave, doctrine, permissions and integrity settings. | FR-CONFIG-001, FR-PARTS-001, FR-LOCK-001, FR-PDF-001 |
| `src/Infrastructure/Symfony/DependencyInjection/ParameterFlattener.php` | Flattens the validated configuration into container parameters such as `nowo_working_time.register.enabled`; associative arrays are walked, lists and scalars are published as they are. | FR-CONFIG-001 |
| `src/Infrastructure/Symfony/DependencyInjection/ServiceDefinitions.php` | Registers every bundle service with explicit arguments and no autowiring, the port defaults under `nowo_working_time.default.*` ids, and the part-gated stores, commands, listeners and UI services, all stateless for worker mode. | FR-PORT-001, FR-PARTS-001, FR-WORKER-001 |
| `src/NowoWorkingTimeBundle.php` | `AbstractBundle` with alias `nowo_working_time`; registers `PrivateEntityManagerPass`, `WorkingTimePortsPass`, `TwigPathsPass`; prepends the FormKit profile `working_time`, the UTC datetime DBAL type and the three XML mappings on the private entity manager; flattens config to parameters and validates the default schedule. | FR-CONFIG-001, FR-EM-001, FR-PORT-001 |

## Shared kernel

| Source file | What it does | Requirement IDs |
| --- | --- | --- |
| `src/Domain/Shared/ActorRef.php` | Opaque reference to the acting user (uses `OpaqueRefTrait`); `asWorker()` converts to `WorkerRef`. | FR-SHARED-001 |
| `src/Domain/Shared/AuditEntry.php` | Immutable audit entry; key must match `working_time.*`, context values must be scalars, otherwise `InvalidArgumentException`. | FR-SHARED-001, FR-PORT-001 |
| `src/Domain/Shared/DayAnnotation.php` | Immutable day annotation of kind `holiday` or `leave` with label, full-day flag and non-negative reduced seconds. | FR-REG-005, FR-SHARED-001 |
| `src/Domain/Shared/Exception/AccessDeniedException.php` | Exception with fixed message `Forbidden.` thrown when a permission is missing; implements `WorkingTimeException`. | FR-ACCESS-001, FR-ERR-001 |
| `src/Domain/Shared/Exception/NotAvailableException.php` | Exception with fixed message `Not found.` used for unknown or out-of-tenant resources and disabled parts. | FR-ERR-001, FR-PARTS-001 |
| `src/Domain/Shared/Exception/RefusedException.php` | Abstract base for rule refusals carrying a translation `key` and `params`, shown to the user as a message instead of a trace. | FR-ERR-001 |
| `src/Domain/Shared/Exception/WorkingTimeException.php` | Marker interface extending `Throwable` that every bundle exception implements. | FR-ERR-001 |
| `src/Domain/Shared/GroupRef.php` | Opaque reference to a worker group (uses `OpaqueRefTrait`). | FR-SHARED-001 |
| `src/Domain/Shared/IpPrefix.php` | Value object storing an IP truncated to /24 (IPv4) or /64 (IPv6) for minimised clocking evidence; validates stored values by pattern. | FR-SHARED-001, FR-GDPR-001 |
| `src/Domain/Shared/LocalDate.php` | Immutable `Y-m-d` calendar date with validation (`checkdate`), conversion to and from `DateTime`, weekday, `plusDays()` and comparison. | FR-SHARED-001 |
| `src/Domain/Shared/LocalTime.php` | Static helper formatting an instant in a given time zone (default `H:i`), empty string for null. | FR-SHARED-001 |
| `src/Domain/Shared/NoticeMessage.php` | Immutable notice for the notifier port: tenant, recipient, kind, up to 20 scalar params, local-only action path and locale. | FR-NOTICE-001, FR-SHARED-001 |
| `src/Domain/Shared/OpaqueRefTrait.php` | Trait for opaque host references: 1-128 bytes of valid UTF-8 without control or format characters, with `equals()` and string cast. | FR-SHARED-001 |
| `src/Domain/Shared/RequestKey.php` | Value object for idempotency keys (printable ASCII, 1-80 chars) with `web:` and `api:` prefix factories; invalid keys throw `InvalidRequestKeyException`. | FR-REG-001, FR-SHARED-001 |
| `src/Domain/Shared/TenantProfile.php` | Immutable tenant profile: reference, name, locale (default `en`) and time zone (default `UTC`). | FR-SHARED-001, FR-PORT-001 |
| `src/Domain/Shared/TenantRef.php` | Opaque tenant reference with the `default` tenant factory (uses `OpaqueRefTrait`). | FR-SHARED-001 |
| `src/Domain/Shared/Uuid.php` | Static helper validating lowercase UUID strings (`isValid`, `assert`) used by ids and entities. | FR-SHARED-001 |
| `src/Domain/Shared/WorkerProfile.php` | Immutable worker profile: display name (1-180), email, locale, active flag, join and leave dates, groups, contract ratio in ]0,1]; `inGroup()`. | FR-SHARED-001, FR-PORT-001 |
| `src/Domain/Shared/WorkerRef.php` | Opaque worker reference (uses `OpaqueRefTrait`); `isActor()` compares with an `ActorRef`. | FR-SHARED-001 |
| `src/Domain/Shared/WorkingTimePermission.php` | Enum of bundle permissions: clock, manage_team, export, manage_settings, leave_request, leave_approve, leave_view_team, leave_manage, holidays_manage. | FR-ACCESS-001 |
| `src/Domain/TranslationDomain.php` | Holds the translation domain constant `NowoWorkingTimeBundle` used by every translatable message. | FR-I18N-001 |

## Domain ports

| Source file | What it does | Requirement IDs |
| --- | --- | --- |
| `src/Domain/Port/ExportFileStoreInterface.php` | Port to store, fetch and delete the bytes of a generated export by export id. | FR-EXPORT-002, FR-PORT-001 |
| `src/Domain/Port/HolidayProviderInterface.php` | Port for an external holiday source: key, label, attribution, `supports(location)` and `fetch(location, year, language)`. | FR-HOL-002, FR-PORT-001 |
| `src/Domain/Port/IdGeneratorInterface.php` | Port generating new lowercase UUID identifiers. | FR-PORT-001 |
| `src/Domain/Port/RegisterDayAnnotationProviderInterface.php` | Port returning day annotations (holiday, leave) for a worker and date range, shown in register reads. | FR-REG-005, FR-PORT-001 |
| `src/Domain/Port/RegisterPdfRendererInterface.php` | Port rendering a `RegisterReport` with a `Brand` into PDF bytes. | FR-PDF-001, FR-PORT-001 |
| `src/Domain/Port/ScheduleFallbackProviderInterface.php` | Port giving a fallback schedule definition for a tenant and date when no assignment resolves. | FR-REG-002, FR-PORT-001 |
| `src/Domain/Port/TaskTimerLinkInterface.php` | Port to stop a running task timer for a worker at a given instant, used when clocking out. | FR-BRIDGE-001, FR-PORT-001 |
| `src/Domain/Port/WorkerDirectoryInterface.php` | Port to resolve the current worker, worker profiles, groups and the workers managed by an actor. | FR-PORT-001, FR-BRIDGE-002 |
| `src/Domain/Port/WorkingTimeAuditSinkInterface.php` | Port receiving an `AuditEntry` for each audited action. | FR-PORT-001 |
| `src/Domain/Port/WorkingTimeNotifierInterface.php` | Port delivering a `NoticeMessage` to a recipient, called by the notice outbox. | FR-NOTICE-001, FR-PORT-001 |
| `src/Domain/Port/WorkingTimePermissionCheckerInterface.php` | Port with `isGranted(permission, tenant, subject)` and `currentActor()`; the default denies everything. | FR-ACCESS-001, FR-PORT-001 |
| `src/Domain/Port/WorkingTimeTenantResolverInterface.php` | Port returning the current `TenantRef` and the `TenantProfile` (name, locale, timezone) of a tenant. | FR-PORT-001 |

## Register domain: model and enums

| Source file | What it does | Requirement IDs |
| --- | --- | --- |
| `src/Domain/Register/Enum/ChainFormat.php` | Backed int enum of hash-chain formats: `Beacon` (1, legacy migrated links with integer refs) and `Bundle` (2, native uuid-based links); stored on each revision to pick the codec. | FR-CHAIN-001 |
| `src/Domain/Register/Enum/ClockAction.php` | Clocking actions `clock-in`, `break-start`, `break-end`, `clock-out`; encodes the allowed-from-status transition table, the matching `RevisionKind`, the segment type opened and the underscore key. | FR-REG-001 |
| `src/Domain/Register/Enum/ClockStatus.php` | Worker clock state machine values `idle`, `working`, `on_break`, `closed`, used to decide which clock actions are allowed. | FR-REG-001 |
| `src/Domain/Register/Enum/CorrectionOperation.php` | Operations of a correction: `amend` (change a segment), `add` (new segment) and `void` (cancel a segment). | FR-REG-003 |
| `src/Domain/Register/Enum/CorrectionStatus.php` | Lifecycle of a correction request: `pending`, `approved`, `rejected`; a request is decided only once. | FR-REG-003 |
| `src/Domain/Register/Enum/DayFlag.php` | Ten flags a day record can carry (missing clock-out, late entry, overtime, long day, short break, outside band, corrected, pending acknowledgement, contested, correction pending). | FR-REG-005 |
| `src/Domain/Register/Enum/ExportFormat.php` | Register export output formats `pdf`, `csv` and `zip`. | FR-EXPORT-001 |
| `src/Domain/Register/Enum/ExportStatus.php` | Async export lifecycle `pending`, `running`, `ready`, `failed`, `expired`. | FR-EXPORT-002 |
| `src/Domain/Register/Enum/RevisionKind.php` | Kinds of append-only chain revisions (four clock kinds, `correction`, `recorded_correction`, `acknowledged`, `contested`) with `isCorrection()` and `isClock()` helpers. | FR-CHAIN-001, FR-REG-003 |
| `src/Domain/Register/Enum/ScheduleScope.php` | Scope of a schedule assignment: `tenant`, `group` or `worker`, used for the per-day resolution precedence. | FR-REG-002 |
| `src/Domain/Register/Enum/SegmentSource.php` | Origin of a segment boundary: `web`, `desktop`, `extension`, `correction`, `auto`. | FR-REG-001 |
| `src/Domain/Register/Enum/SegmentType.php` | Segment type `work` or `break`. | FR-REG-001 |
| `src/Domain/Register/Model/ChainAnchor.php` | Doctrine model of a purge anchor: first kept sequence, anchor hash, purged link count, purge run id and first kept date, so the chain stays verifiable after retention purge. | FR-CHAIN-001, FR-PURGE-001 |
| `src/Domain/Register/Model/CorrectionInput.php` | Readonly input DTO for a correction (operation, segment id and type, new start/end, reason). | FR-REG-003 |
| `src/Domain/Register/Model/CorrectionRequest.php` | Aggregate for a worker's correction request: reason length 10-500, `approve()`/`reject()` by a manager exactly once (else `LogicException`), optimistic `version`, links the resulting revision id. | FR-REG-003 |
| `src/Domain/Register/Model/DaySchedule.php` | Frozen per-day schedule snapshot stored on a workday (timezone, ranges, expected seconds, overtime thresholds, flex, breaks counted, min break); `fromArray` sanitises, with working-day and daily-threshold helpers. | FR-REG-002 |
| `src/Domain/Register/Model/RegisterExport.php` | Export request entity with status transitions `complete()` (file, sha256, chain heads, expiry after N days), `fail()`, `expire()` and `isDownloadable()`. | FR-EXPORT-001, FR-EXPORT-002 |
| `src/Domain/Register/Model/RegisterExportFile.php` | Holds the generated export file content (string or stream resource) keyed by export id. | FR-EXPORT-001 |
| `src/Domain/Register/Model/RegisterNotice.php` | Outbox row for a register notice with kind constants (correction requested/decided/recorded, reminders, monthly summary, export ready), params, dedupe key and deliverable-from time. | FR-NOTICE-001 |
| `src/Domain/Register/Model/RegisterSettings.php` | Versioned per-tenant register settings entity (enabled, overtime, flex, breaks, IP evidence notices, retention years, correction window); `defaults()` clamps retention and window, `change()` applies input. | FR-REG-004 |
| `src/Domain/Register/Model/RegisterSettingsInput.php` | Validated settings input: retention 4-10 years, flex, break and overtime bounds, correction window 1-31, locale-keyed IP notices; throws `SettingsRefusedException` with a translation key. | FR-REG-004 |
| `src/Domain/Register/Model/RetentionPolicy.php` | Computes the first kept date from a retention in years (never below 4) and today. | FR-PURGE-001 |
| `src/Domain/Register/Model/ScheduleAssignment.php` | Entity assigning a `ScheduleDefinition` to a tenant, group or worker from a valid-from date, optionally with a work centre. | FR-REG-002 |
| `src/Domain/Register/Model/ScheduleDefinition.php` | Weekly schedule value object: IANA timezone, validated non-overlapping day slots, daily/weekly targets, flex band; computes ranges and expected minutes per weekday. | FR-REG-002 |
| `src/Domain/Register/Model/WorkSegment.php` | Work or break segment of a workday with start/end, sources, received-at times and IP prefixes; closed once and detects late recording. | FR-REG-001 |
| `src/Domain/Register/Model/Workday.php` | Workday aggregate per worker and local date, holding the schedule snapshot, worker name and optional legacy id for migrated rows. | FR-REG-001, FR-REG-002 |
| `src/Domain/Register/Model/WorkdayRevision.php` | Append-only chain link: seq, kind, payload, author, request key, prev hash and format; computes its own hash from the codec on construction, `append()` for native and `migrated()` for legacy. | FR-CHAIN-001, FR-REG-001 |
| `src/Domain/Register/Model/WorkerPreference.php` | Per-worker preferences for reminders and monthly summary. | FR-REG-004 |

## Register domain: events and exceptions

| Source file | What it does | Requirement IDs |
| --- | --- | --- |
| `src/Domain/Register/Event/ChainBroken.php` | Event dispatched when verification finds a broken link, carrying tenant, worker, sequence and reason. | FR-CHAIN-001 |
| `src/Domain/Register/Event/ClockActionRecorded.php` | Event after a clock action is committed, with tenant, worker, action and time. | FR-REG-001, FR-TXN-001 |
| `src/Domain/Register/Event/CorrectionDecided.php` | Event after a manager approves or rejects a correction request. | FR-REG-003 |
| `src/Domain/Register/Event/CorrectionRecorded.php` | Event after a manager records a correction directly (revision id) for the worker to acknowledge. | FR-REG-003 |
| `src/Domain/Register/Event/CorrectionRequested.php` | Event after a worker files a correction request. | FR-REG-003 |
| `src/Domain/Register/Event/RegisterExportReady.php` | Event when an async export is ready for download. | FR-EXPORT-002 |
| `src/Domain/Register/Event/RegisterPurged.php` | Event after a retention purge with first kept date and purged workday and revision counts. | FR-PURGE-001 |
| `src/Domain/Register/Event/RegisterSettingsChanged.php` | Event after register settings change, with the new version number. | FR-REG-004 |
| `src/Domain/Register/Exception/ClockConflictException.php` | Raised when a clock action is refused; reasons `not_allowed`, `earlier_than_last`, `concurrent`; carries the current `ClockState` for a 409 reply. | FR-REG-001, FR-TXN-002 |
| `src/Domain/Register/Exception/CorrectionRefusedException.php` | Correction refused with a translation key (e.g. stale decision, closed window). | FR-REG-003, FR-ERR-001 |
| `src/Domain/Register/Exception/ExportRefusedException.php` | Export request refused with a translation key. | FR-EXPORT-001, FR-ERR-001 |
| `src/Domain/Register/Exception/InvalidRequestKeyException.php` | `InvalidArgumentException` for a malformed idempotency request key. | FR-REG-001, FR-ERR-001 |
| `src/Domain/Register/Exception/PermissionDeniedException.php` | Access-denied exception for the register, extending the shared `AccessDeniedException`, tagged with the bundle interface. | FR-ACCESS-001, FR-ERR-001 |
| `src/Domain/Register/Exception/RegisterUnavailableException.php` | Raised when the register part is disabled or not available, extending the shared `NotAvailableException`. | FR-PARTS-001, FR-ERR-001 |
| `src/Domain/Register/Exception/ScheduleRefusedException.php` | Schedule assignment refused with a translation key. | FR-REG-002, FR-ERR-001 |
| `src/Domain/Register/Exception/SettingsRefusedException.php` | Settings validation failure carrying the field name and translation key; also used for stale versions. | FR-REG-004, FR-ERR-001 |
| `src/Domain/Register/Exception/WorkingTimeExceptionInterface.php` | Register-scope marker interface extending the shared `WorkingTimeException`, so every typed bundle exception is catchable under one type. | FR-ERR-001 |

## Register domain: services, read models and hash chain

| Source file | What it does | Requirement IDs |
| --- | --- | --- |
| `src/Domain/Register/Chain/BeaconChainCodec.php` | Canonical JSON for format 1 (legacy Beacon) links using integer organization/user/workday refs; throws `LogicException` if the legacy refs are missing. | FR-CHAIN-001 |
| `src/Domain/Register/Chain/BundleChainCodec.php` | Canonical JSON for native format 2 links (tenant, worker, workday, seq, kind, payload, author, UTC time, request key). | FR-CHAIN-001 |
| `src/Domain/Register/Chain/CanonicalJson.php` | Deterministic JSON encoder that sorts associative keys recursively and keeps unescaped slashes and unicode, so hashes are stable. | FR-CHAIN-001 |
| `src/Domain/Register/Chain/ChainCodecInterface.php` | Contract for a chain codec: its `ChainFormat` and the canonical string of a revision. | FR-CHAIN-001 |
| `src/Domain/Register/Chain/ChainCodecs.php` | Registry and hash helper: genesis hash of 64 zeros, codec per format, `sha256(prev . canonical)` and `isIntact()` link check using `hash_equals`. | FR-CHAIN-001 |
| `src/Domain/Register/Read/ClockState.php` | Read model of a worker's clock: status, date, timezone, day record, reminder; lists allowed actions and the primary action. | FR-REG-005, FR-REG-001 |
| `src/Domain/Register/Read/DayRecord.php` | Read model of one day: workday, effective segments, work/break/worked seconds, flags, revisions, requests and pending acknowledgements, with first start, last end and overtime helpers. | FR-REG-005 |
| `src/Domain/Register/Read/DayRecordBuilder.php` | Pure builder that replays segments and revisions (corrections, acknowledgements, contests) into a `DayRecord`, detects stale open segments and derives day flags from `RegisterRules`. | FR-REG-005, FR-REG-003 |
| `src/Domain/Register/Read/EffectiveSegment.php` | Segment after corrections: voided or corrected markers, original times, late flag and IP prefixes; computes seconds up to now. | FR-REG-005 |
| `src/Domain/Register/Read/MonthSummary.php` | Aggregates day records into month totals: worked, expected, daily and ISO-week overtime, days worked, missing clock-outs, corrections, breaks; formats `H:MM`. | FR-REG-005 |
| `src/Domain/Register/Read/RegisterReport.php` | Readonly data bag for exports: title, header, figures, day and correction rows, integrity block, legal note, chain head and locale. | FR-EXPORT-001, FR-REG-005 |
| `src/Domain/Register/Read/RegisterRules.php` | Thresholds for flags: late seconds (600), missing clock-out grace (7200), long day (57600) and the break-after-6h rule constant. | FR-REG-005 |
| `src/Domain/Register/Read/VerificationReport.php` | Result of a chain verification: chains, links, broken links, heads and head links; `isIntact()` when nothing is broken. | FR-CHAIN-001 |
| `src/Domain/Register/Service/ChainVerifierInterface.php` | Port of the chain verifier: `verify` (optionally against published heads), `publishHeads` and `head` of a worker. | FR-CHAIN-001 |
| `src/Domain/Register/Service/CorrectionServiceInterface.php` | Contract for correction use cases: `request`, `decide` with expected version, manager `record` and worker `answer` (acknowledge or contest). | FR-REG-003 |
| `src/Domain/Register/Service/RegisterClockInterface.php` | Contract for clocking: read `state` and `act` with request key, source and raw IP, returning the new state. | FR-REG-001 |
| `src/Domain/Register/Service/RegisterExportServiceInterface.php` | Contract for exports: `request` a month range for worker or group, `status`, `open` the file and `list`. | FR-EXPORT-001, FR-EXPORT-002 |
| `src/Domain/Register/Service/RegisterReaderInterface.php` | Read-side contract: day records for a worker or tenant over a date range, plus `records` for given workdays. | FR-REG-005 |
| `src/Domain/Register/Service/RegisterSettingsEditorInterface.php` | Contract to update register settings with an expected version (optimistic lock). | FR-REG-004 |
| `src/Domain/Register/Service/ScheduleServiceInterface.php` | Contract for schedules: `assign` by scope from a date, `withdrawFuture`, `resolve` a day schedule for a worker and `list`. | FR-REG-002 |

## Holiday domain

| Source file | What it does | Requirement IDs |
| --- | --- | --- |
| `src/Domain/Holiday/Enum/HolidayOrigin.php` | Backed enum of where a holiday day came from (`manual`, `file`, `festivos_io`, `openholidays`, `provider`); `ofProvider()` maps a provider key and `labelKey()` gives the translation key. | FR-HOL-001, FR-HOL-002 |
| `src/Domain/Holiday/Enum/HolidayScope.php` | Backed enum national/regional/local for a calendar; `fromImport()` normalises imported text (accepts `autonomous` as regional) and `labelKey()` gives the translation key. | FR-HOL-001, FR-HOL-002 |
| `src/Domain/Holiday/Enum/ImportStatus.php` | Backed enum of the status of an import preview row (new, duplicate, other_scope, invalid, unchanged, removed) with its translation label key. | FR-HOL-002 |
| `src/Domain/Holiday/Event/HolidaysImported.php` | Immutable event carrying tenant, calendar id, source, and added/removed counts, dispatched after a confirmed file or provider import. | FR-HOL-002, FR-TXN-001 |
| `src/Domain/Holiday/Exception/HolidayRefusedException.php` | Final `RefusedException` subtype for holiday rule violations; carries a translation key (calendar, day, centre, import errors). | FR-ERR-001, FR-HOL-001 |
| `src/Domain/Holiday/HolidayCalendarId.php` | Value object wrapping a calendar id; the constructor asserts a lowercase UUID and `equals()` compares values. | FR-HOL-001, FR-SHARED-001 |
| `src/Domain/Holiday/HolidayCalendarInput.php` | Immutable input DTO for creating or editing a calendar: name, `HolidayScope`, year, optional source note and optional `HolidayLocation`. | FR-HOL-001 |
| `src/Domain/Holiday/HolidayEntry.php` | Immutable holiday read value (date, name, scope, origin, source ref/url, calendar id, provider); cleans names (control chars, max 120) and accepts only http(s) URLs up to 255 chars. | FR-HOL-001, FR-HOL-002 |
| `src/Domain/Holiday/HolidayFetchResult.php` | Immutable result of a provider fetch: entries, source URL, place, last-updated, provisional flag and attribution text. | FR-HOL-002 |
| `src/Domain/Holiday/HolidayLocation.php` | Value object for country (ISO alpha-2), optional subdivision (`ES-XX`, must match country) and 5-digit INE municipality code; invalid values throw with translation keys. | FR-HOL-001, FR-HOL-002 |
| `src/Domain/Holiday/Import/HolidayImportParser.php` | Pure parser for CSV (`date,name,scope`, comma or semicolon) and ICS uploads (max 200 rows, 256 KiB, UTF-8) producing preview rows, plus `compare()` of provider results with stored days. | FR-HOL-002 |
| `src/Domain/Holiday/ImportPreview.php` | Immutable preview of an import: rows, optional error key and optional fetched result; `with()` filters rows by status. | FR-HOL-002 |
| `src/Domain/Holiday/ImportPreviewRow.php` | Immutable preview row (date, name, status, scope, source ref/url) with `withStatus()` to produce a copy with another status. | FR-HOL-002 |
| `src/Domain/Holiday/ImportResult.php` | Immutable outcome of a confirmed import: added count, removed count and provisional flag. | FR-HOL-002 |
| `src/Domain/Holiday/Model/HolidayCalendar.php` | Tenant calendar entity (name max 80, year 2000-2100, location, provider, provisional, attribution, version); `recordFetch()` validates the provider key and stores UTC fetch time. | FR-HOL-001, FR-HOL-002 |
| `src/Domain/Holiday/Model/HolidayDay.php` | Entity for one holiday date in a calendar; creation requires the date year to equal the calendar year, manual edits reset origin to manual and clear provider/source. | FR-HOL-001 |
| `src/Domain/Holiday/Model/WorkCentre.php` | Work centre entity (name max 80) holding a deduplicated set of calendar ids and a default flag; `forget()` drops a removed calendar and `covers()` tests membership. | FR-HOL-001 |
| `src/Domain/Holiday/Service/HolidayCalendarReaderInterface.php` | Read port used by register and leave: holidays between dates, `isHoliday()`, per-day holiday map for centres, and the tenant work centres. | FR-HOL-001, FR-REG-005 |
| `src/Domain/Holiday/Service/HolidayCalendarServiceInterface.php` | Administration contract for calendars, days, file and provider import preview/confirm, and work centres (with optimistic `expectedVersion`). | FR-HOL-001, FR-HOL-002 |
| `src/Domain/Holiday/Service/MunicipalityDirectoryInterface.php` | Contract for the INE municipality directory: search by text, name by code, loaded check and refresh. | FR-HOL-001, FR-HOL-002 |
| `src/Domain/Holiday/WorkCentreId.php` | Value object wrapping a work centre id; asserts a lowercase UUID and offers `equals()`. | FR-HOL-001, FR-SHARED-001 |

## Leave domain

| Source file | What it does | Requirement IDs |
| --- | --- | --- |
| `src/Domain/Leave/Balance.php` | Immutable balance per leave type (entitled, taken, planned, pending, lines, entitlement); `remaining()` and `available()` compute amounts and `typeName()` falls back locale, language, `en`, first. | FR-LEAVE-002 |
| `src/Domain/Leave/DayCount.php` | Immutable result of the day counter: unit, amount, counted days and skipped holidays; `toArray()` returns the JSON shape with formatted amount. | FR-LEAVE-002 |
| `src/Domain/Leave/Entitlement.php` | Immutable entitlement of a worker for a year: policy, base, lines total, pro-rata and part-time factors; `total()` is base plus lines. | FR-LEAVE-002 |
| `src/Domain/Leave/Enum/AllowanceLineKind.php` | Enum of allowance line kinds: adjustment, carry_over and protected_carry_over, with label key. | FR-LEAVE-001, FR-LEAVE-002 |
| `src/Domain/Leave/Enum/AllowanceSource.php` | Enum of where a leave type draws its balance: none, allowance or overtime, with label key. | FR-LEAVE-001 |
| `src/Domain/Leave/Enum/DayPart.php` | Enum of the part of a day a leave covers (full, morning, afternoon, hours); `isHalf()` identifies half days. | FR-LEAVE-002 |
| `src/Domain/Leave/Enum/LeaveUnit.php` | Enum natural days, working days, hours; stores amounts as half-days or minutes, with limits (732 / 124800), input conversion and formatting (`H:MM`). | FR-LEAVE-001, FR-LEAVE-002 |
| `src/Domain/Leave/Enum/RoundingMode.php` | Enum nearest/up/down; `apply()` rounds a fractional amount (pro-rata) to an integer with epsilon-safe ceil and floor. | FR-LEAVE-002 |
| `src/Domain/Leave/Event/LeaveAllowanceAdjusted.php` | Immutable event for an allowance line added for a worker: tenant, worker, leave type, year, line kind and amount. | FR-LEAVE-001, FR-TXN-001 |
| `src/Domain/Leave/Exception/LeaveRefusedException.php` | Final `RefusedException` subtype for leave rule violations; carries translation key and parameters. | FR-ERR-001, FR-LEAVE-001 |
| `src/Domain/Leave/LeaveAmount.php` | Immutable amount in a `LeaveUnit` (integer half-days or minutes) with `format()`. | FR-LEAVE-002, FR-SHARED-001 |
| `src/Domain/Leave/LeaveSettingsInput.php` | Immutable settings input; validates protected carry months 1-60, attachment retention 1-48, overtime ratio 1-400 and `MM-DD` planning deadline, else `LeaveRefusedException`. | FR-LEAVE-001 |
| `src/Domain/Leave/LeaveTypeId.php` | Value object wrapping a leave type id; asserts a lowercase UUID and offers `equals()`. | FR-LEAVE-001, FR-SHARED-001 |
| `src/Domain/Leave/LeaveTypeInput.php` | Immutable input DTO for a leave type: name, paid, source, unit, approval/attachment/medical flags, colour, max per request, carry-over options. | FR-LEAVE-001 |
| `src/Domain/Leave/Model/AllowanceLine.php` | Append-style allowance line entity (adjustment or carry-over) with reason 3-255 chars, amount and size checks, optional expiry; `expire()` marks it expired once and `countsOn()` tests validity. | FR-LEAVE-001, FR-LEAVE-003 |
| `src/Domain/Leave/Model/AllowancePolicy.php` | Allowance policy entity per leave type, scope (tenant/group/worker) and year; enforces the legal vacation minimum (30 natural or 22 working days, stored as 60 / 44 half-days) unless an agreement-equivalent note is given. | FR-LEAVE-001 |
| `src/Domain/Leave/Model/LeaveSettings.php` | Tenant leave settings entity (enabled, rounding, protected carry months, attachment retention, overtime ratio, planning deadline, version) with `defaults()` and `change()`. | FR-LEAVE-001 |
| `src/Domain/Leave/Model/LeaveType.php` | Leave type entity with per-locale names, unit, paid and approval flags, colours, carry-over rules and archive flag; `change()` validates combinations (overtime needs hours, medical excludes allowance). | FR-LEAVE-001 |
| `src/Domain/Leave/Service/LeaveAdministrationInterface.php` | Administration contract for leave types, seeding, allowance policies, worker lines and settings (with expected version). | FR-LEAVE-001 |
| `src/Domain/Leave/Service/LeaveBalanceReaderInterface.php` | Read contract returning the balances of a worker for a leave year. | FR-LEAVE-002 |

## Application: register

| Source file | What it does | Requirement IDs |
| --- | --- | --- |
| `src/Application/Register/ChainVerifier.php` | Walks every worker chain in batches of 1000: checks each link hash and previous hash, `seq` gaps, genesis or purge anchor start, segment rows against clock links and published heads; reports first break, publishes heads to the audit sink. | FR-CHAIN-001, FR-PURGE-001 |
| `src/Application/Register/CorrectionService.php` | Correction requests by workers (window and future checks, 10-500 char reason), manager decision on the version seen (own-day refused, stale version refused), manager-recorded corrections and worker answers; each appends a chain revision. | FR-REG-003, FR-CHAIN-001, FR-TXN-002 |
| `src/Application/Register/Exception/ChainBusyException.php` | `register_chain_busy`: another writer appended to the chain twice in a row; lock-conflict exception answered as 409 + `Retry-After`, nothing stored. | FR-TXN-002, FR-ERR-001, FR-CHAIN-001 |
| `src/Application/Register/Exception/StaleCorrectionDecisionException.php` | `correction_decision_stale`: a manager decided on an outdated request version; lock-conflict exception answered 409 + `Retry-After`. | FR-REG-003, FR-TXN-002, FR-ERR-001 |
| `src/Application/Register/Exception/StaleSettingsException.php` | `settings_stale`: register settings saved on an outdated version; 409 + `Retry-After` and the form is shown again. | FR-REG-004, FR-TXN-002, FR-ERR-001 |
| `src/Application/Register/HolidayDayAnnotations.php` | Register-side adapter implementing the day-annotation provider: marks public holidays of the worker's calendars (by work centre per day) as full days off. | FR-REG-005, FR-HOL-001 |
| `src/Application/Register/Notice/HoursWindow.php` | Pure time helper: next instant inside the schedule plus or minus a window, default weekday hours for days without ranges, and in/out reminder detection near scheduled start or end. | FR-NOTICE-001, FR-REG-002 |
| `src/Application/Register/Notice/NoticeDispatcher.php` | The five-minute notices run: plans clock reminders and monthly summaries into the outbox (deduplicated), delivers due notices through the notifier port within a budget, marks sent by conditional update; failures retry next run. | FR-NOTICE-001, FR-CLI-001 |
| `src/Application/Register/Notice/NoticeOutbox.php` | Queues a notice inside the caller's write with a dedupe key and a deliverable-from time within the recipient's hours; returns false on duplicates or non-members. | FR-NOTICE-001, FR-TXN-001 |
| `src/Application/Register/Port/ExportDispatcherInterface.php` | Port handing a requested export to its generator after commit, synchronously or through Messenger. | FR-EXPORT-002, FR-PORT-001 |
| `src/Application/Register/Port/ExportGeneratorInterface.php` | Port that builds the file of a pending export, claiming it first; returns false when another generator won or it is not pending. | FR-EXPORT-002 |
| `src/Application/Register/Port/PurgeRefusedException.php` | Raised when database triggers refuse the purge deletes (guard misconfigured); maps to console exit code 2. | FR-PURGE-001, FR-DB-002, FR-ERR-001 |
| `src/Application/Register/Port/RegisterPurgeStoreInterface.php` | The only deleting path of protected register rows: selects workdays before a date, deletes one batch per transaction lifting the trigger guard, writes chain anchors, drops delivered notices. | FR-PURGE-001, FR-DB-002, FR-CHAIN-001 |
| `src/Application/Register/Port/RegisterStoreInterface.php` | Register persistence port: settings, preferences, workdays, segments, chain revisions (head, request key lookup, batches), corrections and exports; protected rows only have `add*` and reads, no update or delete. | FR-REG-001, FR-REG-005, FR-DB-001 |
| `src/Application/Register/Port/RegisterTriggersInterface.php` | Port to install, uninstall and list missing append-only triggers on the bundle connection. | FR-DB-002 |
| `src/Application/Register/Port/TriggerInspectorInterface.php` | Read-only port naming missing append-only triggers; used by the settings page warning and `integrity.triggers: require`. | FR-DB-002, FR-REG-004 |
| `src/Application/Register/Port/TriggerInstallRefusedException.php` | Raised when the database user cannot create the append-only triggers; nothing half-created remains; the Doctrine installer subclasses it. | FR-DB-002, FR-ERR-001 |
| `src/Application/Register/RegisterAccess.php` | Register gate: tenant, register enabled, actor and member checks, deny-by-default permission (404 `RegisterUnavailableException`, `PermissionDeniedException`); returns `RegisterContext`; settings defaults when unsaved. | FR-ACCESS-001, FR-REG-001 |
| `src/Application/Register/RegisterAnnotations.php` | Merges the day annotations from tagged providers (holidays, leave) for a worker between dates and tells whether a date is a full day off. | FR-REG-005 |
| `src/Application/Register/RegisterContext.php` | Immutable per-call context: tenant, detached settings copy, actor, member profile and optional subject; `worker()` and `isOwn()` helpers. | FR-REG-001, FR-WORKER-001 |
| `src/Application/Register/RegisterExportService.php` | Requests register exports (own copy needs `clock`, inspection needs `export`), checks period and format, shows status, serves downloads with audit, and expires files after the download window while keeping rows and hashes. | FR-EXPORT-001, FR-EXPORT-002, FR-ACCESS-001 |
| `src/Application/Register/RegisterPersonalData.php` | Register half of GDPR: exports workdays, corrections, exports and preferences across tenants; erasure deletes preferences and export files but keeps the legal register until retention end. | FR-GDPR-001, FR-PURGE-001 |
| `src/Application/Register/RegisterPurger.php` | Retention purge per tenant: deletes workdays before the first kept date (4-10 years) in batches of 500 with anchors in the same transaction, supports dry run, audits counts, drops notices older than 400 days. | FR-PURGE-001, FR-CHAIN-001 |
| `src/Application/Register/RegisterReader.php` | Read side: loads workdays with segments, chain links and corrections in one query each and builds `DayRecord`s for one worker, the tenant or a single workday. | FR-REG-005 |
| `src/Application/Register/RegisterSettingsEditor.php` | Reads and updates tenant register settings on the version the admin saw; refuses IP evidence without notice, refuses enabling while triggers are missing, audits, maps stale writes to `StaleSettingsException`. | FR-REG-004, FR-DB-002, FR-TXN-002 |
| `src/Application/Register/RevisionChain.php` | Appends the next hash-chained revision of a worker chain inside the caller's write: reads the head, computes `seq`/hashes, stores with optional request key; unique `seq` prevents forks. | FR-CHAIN-001, FR-REG-001 |
| `src/Application/Register/ScheduleResolver.php` | Resolves a worker's schedule per date: dated row for worker, else group, else tenant, else the fallback port; range queries with work centres; applies tenant thresholds and break rules. | FR-REG-002 |
| `src/Application/Register/ScheduleService.php` | Dated schedule rows: assign (from today, group and work-centre checks, past refused), withdraw future rows only, resolve a worker's date and list the tenant's rows. | FR-REG-002, FR-ACCESS-001 |
| `src/Application/Register/TimeClock.php` | Clocking service: state and `act` for start, pause, resume, end under the chain lock with request-key replay; refuses invalid or earlier-than-last actions with `ClockConflictException` (409) and stops the task timer on end/break. | FR-REG-001, FR-TXN-002, FR-BRIDGE-001 |
| `src/Application/Register/WorkerPreferences.php` | The signed-in worker's reminder and monthly-summary preferences, forced off when the tenant disallows reminders and forced on when the summary is mandatory. | FR-REG-004, FR-NOTICE-001 |

## Application: holidays

| Source file | What it does | Requirement IDs |
| --- | --- | --- |
| `src/Application/Holiday/HolidayAccess.php` | Gate for the holiday component: requires a tenant, an actor who is an active worker and the `HolidaysManage` permission; throws `NotAvailableException` or `AccessDeniedException`, returns a `HolidayContext`; `can()` never throws. | FR-ACCESS-001, FR-HOL-001 |
| `src/Application/Holiday/HolidayCalendarReader.php` | Read side for other components: holidays between two dates, `isHoliday` and a date-to-name map per work centre (default centre fallback), first calendar by name wins per date; runs inside read units. | FR-HOL-001, FR-WORKER-001 |
| `src/Application/Holiday/HolidayCalendarService.php` | Administers calendars, days, work centres; file (CSV/ICS) and provider imports with preview then confirm; version-checked, audited (`working_time.holidays_changed`), raises `holiday_calendar_stale` / `holiday_import_conflict` conflicts. | FR-HOL-001, FR-HOL-002, FR-TXN-002 |
| `src/Application/Holiday/HolidayContext.php` | Immutable per-call context of a holiday action: tenant, actor and locale; built by `HolidayAccess`, never kept between requests. | FR-HOL-001, FR-WORKER-001 |
| `src/Application/Holiday/HolidayProviderRegistry.php` | Holds the tagged holiday providers minus the configured disabled keys; `get()` returns one by key or throws `HolidayRefusedException` (`holiday.provider.error.unknown`). | FR-HOL-002, FR-CONFIG-001 |
| `src/Application/Holiday/Port/HolidayStoreInterface.php` | Persistence port for calendars, days (by calendar and date range) and work centres, including `makeDefault`; implemented by the Doctrine holiday store on the bundle entity manager. | FR-HOL-001, FR-DB-001 |

## Application: leave

| Source file | What it does | Requirement IDs |
| --- | --- | --- |
| `src/Application/Leave/AllowanceResolver.php` | Pure entitlement calculator: picks the most specific policy (worker, group, tenant), applies part-time ratio (hours) and pro-rata by join/leave dates with rounding, adds the non-expired allowance lines. | FR-LEAVE-002 |
| `src/Application/Leave/DayCounter.php` | Counts leave in half-day units for natural days, working days or hours using resolved schedules and holiday map; validates range (max 366 days), half-day, time format with `LeaveRefusedException` codes. | FR-LEAVE-002, FR-REG-002 |
| `src/Application/Leave/LeaveAccess.php` | Leave gate: tenant, leave-enabled setting, actor membership, optional subject membership and deny-by-default permission check; returns `LeaveContext`; default settings when none stored. | FR-ACCESS-001, FR-LEAVE-001 |
| `src/Application/Leave/LeaveAdministration.php` | Admin service for leave types (create, change, archive, seed), allowance policies, allowance lines, settings (version-checked) and daily `expireCarryOvers` in batches of 500; audited, stale types answer `leave_type_stale`. | FR-LEAVE-001, FR-LEAVE-003, FR-TXN-002 |
| `src/Application/Leave/LeaveBalanceReader.php` | Builds a worker's balances for a year (entitled, taken, planned, pending) for allowance-backed types from policies, lines and the usage port; hides empty archived types. | FR-LEAVE-002 |
| `src/Application/Leave/LeaveContext.php` | Immutable per-call leave context: tenant, leave and register settings, actor, actor profile and locale. | FR-LEAVE-001, FR-WORKER-001 |
| `src/Application/Leave/LeavePersonalData.php` | GDPR export of a worker's allowance lines and policies, and erasure through the store with one audit entry per tenant; reports amounts as kept. | FR-GDPR-001, FR-LEAVE-001 |
| `src/Application/Leave/LeaveTypeSeeder.php` | Seeds the 13 default leave types (vacation, personal, compensatory, marriage, family...) with translated names and a default 44-unit vacation policy; skips types already seeded by code. | FR-LEAVE-001, FR-I18N-001 |
| `src/Application/Leave/NoLeaveUsage.php` | Safe default `LeaveUsageInterface` returning no usage, so balances show no taken, planned or pending until a host binds a real source. | FR-PORT-001, FR-LEAVE-002 |
| `src/Application/Leave/Port/LeaveSeedNamesInterface.php` | Port returning translated names (locale keyed) for a seed type code; implemented by the translator-based default adapter. | FR-LEAVE-001, FR-PORT-001, FR-I18N-001 |
| `src/Application/Leave/Port/LeaveStoreInterface.php` | Persistence port for leave settings, types (lookup by seed code, in-use check), policies, lines (per worker, expiring), plus GDPR `linesOfWorker`, `policiesOfWorker` and `erasePersonalData`. | FR-LEAVE-001, FR-DB-001, FR-GDPR-001 |
| `src/Application/Leave/Port/LeaveUsageInterface.php` | Host-implemented port giving taken, planned and pending amounts per leave type for a worker and year. | FR-PORT-001, FR-LEAVE-002 |
| `src/Application/Leave/StaleLeaveSettingsException.php` | Typed exception `leave_settings_stale` for a leave settings save on an outdated version; lock-conflict type, so the UI answers 409 with `Retry-After`. | FR-TXN-002, FR-ERR-001 |

## Application: shared

| Source file | What it does | Requirement IDs |
| --- | --- | --- |
| `src/Application/Shared/AfterCommitEvents.php` | Per-call buffer of domain events dispatched only after the commit; `clear()` drops events of a failed or replayed attempt; never service state. | FR-TXN-001, FR-WORKER-001 |
| `src/Application/Shared/DuplicateRowException.php` | Wraps a unique-key violation (same chain seq, request key or dedupe key stored first by another writer) without keeping the storage message. | FR-TXN-002, FR-ERR-001 |
| `src/Application/Shared/StaleRowException.php` | Wraps an optimistic-lock failure at flush: a versioned row changed since read; nothing of the unit committed. | FR-TXN-002, FR-ERR-001 |
| `src/Application/Shared/TransactionRunnerInterface.php` | Port for units of work: `write` (transaction-doctrine write unit, replayed on deadlock, flush before commit), `read`, `flush`; both clear the entity manager after the call. | FR-TXN-001, FR-WORKER-001 |
| `src/Application/Shared/WriteConflictException.php` | Typed lock-conflict (409 + `Retry-After`) with a named error code (e.g. `leave_seed_conflict`, `holiday_import_conflict`); `writeOnceMore` retries once after a unique-key race. | FR-TXN-002, FR-ERR-001 |

## Bridges

| Source file | What it does | Requirement IDs |
| --- | --- | --- |
| `src/Bridge/Dompdf/DompdfRegisterPdfRenderer.php` | Default inspection PDF renderer: renders `export/register_pdf.html.twig` with the brand to bytes through Dompdf with remote access, JS and PHP disabled. | FR-PDF-001, FR-BRIDGE-001, FR-EXPORT-001 |
| `src/Bridge/TimeTrack/TimeTrackTaskTimerLink.php` | Implements `TaskTimerLinkInterface` with TimeTrack: stops the signed-in worker's running timer at the same instant when clocking out or starting a break. | FR-BRIDGE-001, FR-REG-001 |
| `src/Bridge/TimeTrack/TimeTrackWorkdaySource.php` | Implements TimeTrack's workday source: desktop app and extension read today's state and clock through the same `RegisterClockInterface`, key stored as `api:<key>`, maps conflicts and forbidden to TimeTrack exceptions. | FR-BRIDGE-001, FR-REG-001 |
| `src/Bridge/UserKit/IdentifierWorkerOfUser.php` | Default `WorkerOfUserInterface`: worker ref from the identifier captured before erasure, else the security user identifier. | FR-BRIDGE-002 |
| `src/Bridge/UserKit/UserKitLeaveEraser.php` | UserKit eraser `working_time_leave`: blanks allowance-line reasons and policy notes of the worker and forgets them as creator elsewhere; amounts stay until retention. | FR-BRIDGE-002, FR-GDPR-001 |
| `src/Bridge/UserKit/UserKitLeaveExportProvider.php` | UserKit export provider adding the `working_time_leave` section (allowance lines and own policies, all tenants) to the personal data export. | FR-BRIDGE-002, FR-GDPR-001 |
| `src/Bridge/UserKit/UserKitRegisterEraser.php` | UserKit eraser `working_time`: deletes the worker's register preferences and export files; keeps workdays, chain and corrections until retention end (art. 17.3.b). | FR-BRIDGE-002, FR-GDPR-001, FR-PURGE-001 |
| `src/Bridge/UserKit/UserKitRegisterExportProvider.php` | UserKit export provider adding the `working_time` section (workdays, corrections, exports, preferences) to a person's data export. | FR-BRIDGE-002, FR-GDPR-001 |
| `src/Bridge/UserKit/WorkerOfUserInterface.php` | Port mapping a UserKit user object to a `WorkerRef` (null when not a worker); hosts with UUID worker refs implement it. | FR-BRIDGE-002, FR-PORT-001 |

## Doctrine storage

| Source file | What it does | Requirement IDs |
| --- | --- | --- |
| `src/Infrastructure/Doctrine/BinaryCollation.php` | Picks the binary NO PAD collation for identifier and reference columns (`utf8mb4_0900_bin` on MySQL 8, `utf8mb4_nopad_bin` on MariaDB, `utf8mb4_bin` older), or null on other platforms. | FR-DB-001 |
| `src/Infrastructure/Doctrine/Dbal/DbalRegisterPurgeStore.php` | Implements `RegisterPurgeStoreInterface` with DBAL: selects workdays before the first kept day and deletes per batch links, segments, corrections and workdays, writing the chain anchor in the same transaction with the trigger guard lifted. | FR-PURGE-001, FR-CHAIN-001 |
| `src/Infrastructure/Doctrine/Dbal/PurgeFlagResetDriver.php` | DBAL driver middleware that runs `SET @nowo_working_time_purge = NULL` right after a MySQL/MariaDB connection opens, so a pooled connection never keeps the purge guard lifted. | FR-PURGE-001, FR-DB-002 |
| `src/Infrastructure/Doctrine/Dbal/PurgeFlagResetMiddleware.php` | DBAL middleware whose `wrap()` returns the `PurgeFlagResetDriver` around the configured driver. | FR-PURGE-001, FR-DB-002 |
| `src/Infrastructure/Doctrine/DoctrineHolidayStore.php` | Implements `HolidayStoreInterface` on the private entity manager through the transaction runner: calendars, days (range reads), work centres and default-centre switching, with optional row locks. | FR-DB-001, FR-HOL-001 |
| `src/Infrastructure/Doctrine/DoctrineLeaveStore.php` | Implements `LeaveStoreInterface`: leave settings, types (lookup by seed code, in-use check), allowance policies per year and per-worker allowance lines through the transaction runner. | FR-DB-001, FR-LEAVE-001 |
| `src/Infrastructure/Doctrine/DoctrineRegisterStore.php` | Implements `RegisterStoreInterface`: settings, preferences, workdays and segments, chain links and anchors, revisions, correction requests, schedules, notices outbox and exports, all through the transaction runner. | FR-DB-001, FR-REG-001, FR-CHAIN-001 |
| `src/Infrastructure/Doctrine/DoctrineTransactionRunner.php` | The bundle's `TransactionRunnerInterface`: `write()` runs a transaction-doctrine write unit (replay, duplicate and stale-row exceptions), `read()` runs and clears; refuses to write over host changes and clears the manager after each unit. | FR-TXN-001, FR-TXN-002, FR-WORKER-001 |
| `src/Infrastructure/Doctrine/HostWriteProbe.php` | Per-attempt probe listening to host entity managers' `onFlush`/`postFlush` to record what host flushes wrote, so a unit is not replayed after host work and the rollback is restored (CONFLICT_002). | FR-TXN-001, FR-WORKER-001 |
| `src/Infrastructure/Doctrine/RegisterMetadataListener.php` | Doctrine `loadClassMetadata` listener that applies `doctrine.table_prefix` to the bundle's tables and indexes and maps `id`, `*_id`, hash and key columns with the binary collation. | FR-DB-001 |
| `src/Infrastructure/Doctrine/Trigger/DoctrineRegisterTriggers.php` | Implements `RegisterTriggersInterface` over the bundle connection: `install()` returns the number created, `uninstall()` drops them, `missing()` lists absent triggers. | FR-DB-002 |
| `src/Infrastructure/Doctrine/Trigger/DoctrineTriggerInspector.php` | Implements `TriggerInspectorInterface`: reports the append-only triggers missing on the connection, used by the settings page warning and the check command. | FR-DB-002 |
| `src/Infrastructure/Doctrine/Trigger/RegisterTriggerInstaller.php` | Builds and runs the no-update, no-delete (and PostgreSQL no-truncate) trigger statements for workday, segment, revision and chain anchor on MySQL/MariaDB, PostgreSQL and SQLite, with purge guards `session`, `db_user`. | FR-DB-002, FR-PURGE-001 |
| `src/Infrastructure/Doctrine/Trigger/TriggerInstallException.php` | Exception raised when the database user may not create triggers (nothing half-created); carries a MySQL flag to hint `SUPER` or `log_bin_trust_function_creators`. | FR-DB-002, FR-ERR-001 |
| `src/Infrastructure/Doctrine/Type/UtcDateTimeImmutableType.php` | Doctrine DBAL datetime type that converts instants to UTC on write and reads the stored wall clock back as UTC, whatever PHP `date.timezone` is. | FR-DB-001 |

## Doctrine XML mappings

| Source file | What it does | Requirement IDs |
| --- | --- | --- |
| `src/Resources/config/doctrine-holiday/HolidayCalendar.orm.xml` | Maps `HolidayCalendar` to `holiday_calendar`: tenant, name, scope enum, year, country/subdivision/municipality, provider, provisional flag, attribution, UTC fetch time, version lock; index `(tenant_id, year)`. | FR-HOL-001, FR-DB-001 |
| `src/Resources/config/doctrine-holiday/HolidayDay.orm.xml` | Maps `HolidayDay` to `holiday_day` with origin enum and source reference fields; unique `(calendar_id, holiday_date)` and index `(tenant_id, holiday_date)`. | FR-HOL-001, FR-HOL-002, FR-DB-001 |
| `src/Resources/config/doctrine-holiday/WorkCentre.orm.xml` | Maps `WorkCentre` to `holiday_work_centre`: name, JSON calendar ids, default flag; index on tenant. | FR-HOL-001, FR-DB-001 |
| `src/Resources/config/doctrine-leave/AllowanceLine.orm.xml` | Maps `AllowanceLine` to `working_time_leave_allowance_line`: worker, type, year, kind enum, amount, reason, expiry date and expired-at; indexes for worker-year and expiry. | FR-LEAVE-001, FR-LEAVE-003, FR-DB-001 |
| `src/Resources/config/doctrine-leave/AllowancePolicy.orm.xml` | Maps `AllowancePolicy` to `working_time_leave_policy`: type, scope enum and target, year, amount, agreement-equivalent flag, note; lookup index by tenant, type, year. | FR-LEAVE-001, FR-DB-001 |
| `src/Resources/config/doctrine-leave/LeaveSettings.orm.xml` | Maps `LeaveSettings` (tenant id as primary key) to `working_time_leave_settings`: enabled, absence visibility, medical documents, overtime ratio, rounding enum, approval chains and staffing JSON, version lock. | FR-LEAVE-001, FR-DB-001 |
| `src/Resources/config/doctrine-leave/LeaveType.orm.xml` | Maps `LeaveType` to `working_time_leave_type`: JSON names, paid, allowance source and unit enums, approval/attachment/medical flags, carry-over options, archived, sort order, version; unique `(tenant_id, seed_code)`. | FR-LEAVE-001, FR-DB-001 |
| `src/Resources/config/doctrine/ChainAnchor.orm.xml` | Maps read-only `ChainAnchor` to `working_time_chain_anchor`: first kept seq, anchor hash, purged link count, purge run id, first kept date; unique `(tenant_id, worker_id, first_kept_seq)`. | FR-CHAIN-001, FR-PURGE-001, FR-DB-001 |
| `src/Resources/config/doctrine/CorrectionRequest.orm.xml` | Maps `CorrectionRequest` to `working_time_correction`: operation, segment, proposed times, reason, status enum, decider and comment, produced revision id, version lock; indexes on status and workday. | FR-REG-003, FR-DB-001 |
| `src/Resources/config/doctrine/RegisterExport.orm.xml` | Maps `RegisterExport` to `working_time_export`: requester, worker or group, months, format and status enums, file name, sha256, chain heads JSON, ready/expiry times, error code; indexes by created and status. | FR-EXPORT-001, FR-EXPORT-002, FR-DB-001 |
| `src/Resources/config/doctrine/RegisterExportFile.orm.xml` | Maps `RegisterExportFile` to `working_time_export_file`: export id as key and a blob with the generated file bytes, kept apart from the export row. | FR-EXPORT-001, FR-DB-001 |
| `src/Resources/config/doctrine/RegisterNotice.orm.xml` | Maps the notice outbox row `RegisterNotice` to `working_time_notice`: kind, JSON params, action path, deliverable-from, sent-at; unique `dedupe_key` and a due index. | FR-NOTICE-001, FR-DB-001 |
| `src/Resources/config/doctrine/RegisterSettings.orm.xml` | Maps `RegisterSettings` (tenant id key) to `working_time_settings`: enabled, breaks, flex and overtime minutes, reminders, mandatory summary, IP evidence and notices, retention years, correction window, version lock. | FR-REG-004, FR-DB-001 |
| `src/Resources/config/doctrine/ScheduleAssignment.orm.xml` | Maps `ScheduleAssignment` to `working_time_schedule`: scope enum and target, valid-from date, JSON definition, work centre id; index `(tenant_id, scope, target_id, valid_from)`. | FR-REG-002, FR-DB-001 |
| `src/Resources/config/doctrine/WorkSegment.orm.xml` | Maps `WorkSegment` to `working_time_segment`: type enum, start and end instants with source enum, received-at and IP prefix columns; index on workday. | FR-REG-001, FR-DB-001 |
| `src/Resources/config/doctrine/Workday.orm.xml` | Maps read-only `Workday` to `working_time_workday`: worker name, local date, schedule JSON snapshot, legacy id; unique `(tenant_id, worker_id, local_date)`. | FR-REG-001, FR-REG-002, FR-DB-001 |
| `src/Resources/config/doctrine/WorkdayRevision.orm.xml` | Maps read-only hash-chain link `WorkdayRevision` to `working_time_revision`: seq, kind enum, payload, author, prev hash, hash, chain format; unique `(tenant, worker, seq)` and `(tenant, worker, request_key)`. | FR-CHAIN-001, FR-REG-001, FR-DB-001 |
| `src/Resources/config/doctrine/WorkerPreference.orm.xml` | Maps `WorkerPreference` (worker id key) to `working_time_preference`: reminders, monthly summary and update time. | FR-REG-004, FR-DB-001 |

## Symfony adapters: default port implementations

| Source file | What it does | Requirement IDs |
| --- | --- | --- |
| `src/Infrastructure/Symfony/Holiday/FestivosIoMunicipalityDirectory.php` | Spanish municipality and INE code directory from festivos.io, fetched only by an admin `refresh()` through the outbound guard and cached for `municipalities_ttl`; searches read the cache only. | FR-HOL-002 |
| `src/Infrastructure/Symfony/Holiday/FestivosIoProvider.php` | `HolidayProviderInterface` for festivos.io: fetches one municipality's national, regional and local holidays per year, flags pending local holidays, names the CC BY 4.0 attribution; only the INE code and year leave the server. | FR-HOL-002 |
| `src/Infrastructure/Symfony/Holiday/GuardedJsonClient.php` | The only outbound caller of holiday providers: GET of a fixed https base through the outbound URL guard, timeout, no redirects, 2 MiB body cap, any failure becomes one translated provider error. | FR-HOL-002 |
| `src/Infrastructure/Symfony/Holiday/OpenHolidaysProvider.php` | `HolidayProviderInterface` for the OpenHolidays API: national or regional public holidays by country and subdivision, one row per day of multi-day holidays, subdivisions cached for a day. | FR-HOL-002 |
| `src/Infrastructure/Symfony/Leave/TranslatedLeaveSeedNames.php` | Implements `LeaveSeedNamesInterface`: returns the seeded leave type name for a seed code in every shipped locale from the `leave.seed.<code>` translations. | FR-LEAVE-001, FR-I18N-001 |
| `src/Infrastructure/Symfony/Port/ConfiguredScheduleFallback.php` | Default `ScheduleFallbackProviderInterface`: returns the schedule from `register.schedule.default` in the configured time zone, or null when none is configured. | FR-REG-002, FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/CurrentUserWorkerDirectory.php` | Default `WorkerDirectoryInterface`: the signed-in security user is the only worker (ref = user identifier); team views are empty and nobody manages anyone. | FR-PORT-001, FR-ACCESS-001 |
| `src/Infrastructure/Symfony/Port/DatabaseExportFileStore.php` | Default `ExportFileStoreInterface`: puts, reads and deletes export file bytes in the `export_file` blob table on the bundle's entity manager, inside the caller's write. | FR-EXPORT-001, FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/MailerNotifier.php` | Default notifier when Symfony Mailer is installed: sends one branded e-mail per notice in the recipient's locale via `email/notice.html.twig`; no address means nothing sent. | FR-NOTICE-001, FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/NeutralBrandProvider.php` | Default `BrandProviderInterface`: a neutral name ("Working time") and palette with no logo when the host provides no brand. | FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/NullNotifier.php` | Notifier that delivers nothing when no mailer or host notifier exists; the outbox still records and dedupes and the settings page warns. | FR-NOTICE-001, FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/NullTaskTimerLink.php` | `TaskTimerLinkInterface` no-op used when TimeTrack is not installed or `register.task_timer_link` is `off`. | FR-BRIDGE-001, FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/PsrLogAuditSink.php` | Default audit sink: writes one structured `info` log line per audit entry (key, tenant, actor, subject, ids, counts, never free text). | FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/RoleMapPermissionChecker.php` | Default permission checker: `permissions.roles` maps each permission to security roles, every empty list is denied, roles grant tenant-wide, no SecurityBundle means nothing granted. | FR-ACCESS-001, FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/SingleTenantResolver.php` | Default tenant resolver for single-tenant hosts: every call belongs to `TenantRef::default()` with a configurable profile. | FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/SymfonyUuidGenerator.php` | Default `IdGeneratorInterface`: generates lowercase canonical UUIDv7 (time ordered) ids. | FR-PORT-001 |

## Symfony UI: controllers, routing, HTTP, Twig extension

| Source file | What it does | Requirement IDs |
| --- | --- | --- |
| `src/Infrastructure/Symfony/Controller/DayView.php` | Pure helper turning day records into UiKit view sections and segment choices, and the correction form data (local `HH:MM`, end before start is next day) into a `CorrectionInput`. | FR-UI-001, FR-REG-005 |
| `src/Infrastructure/Symfony/Controller/ExportController.php` | Export pages: own copy and inspection exports of a worker or team, status list, and the audited download with `X-Content-SHA256` while the window lasts, else 404. | FR-EXPORT-001, FR-UI-001 |
| `src/Infrastructure/Symfony/Controller/HolidayController.php` | Holiday pages gated by `holidays_manage`: calendars, work centres, days by hand, CSV/ICS and provider imports with preview then confirm, festivos.io attribution and the municipality search. | FR-HOL-001, FR-HOL-002, FR-UI-001 |
| `src/Infrastructure/Symfony/Controller/LeaveController.php` | Leave pages: worker balances, types with seeding and archiving, allowance policies and lines with a balance preview, and leave settings with the stale-version 409. | FR-LEAVE-001, FR-LEAVE-002, FR-UI-001 |
| `src/Infrastructure/Symfony/Controller/RegisterController.php` | The worker's own register: today with clock buttons carrying an idempotency key, month and day views, correction requests and the answer to a manager-recorded correction. | FR-REG-001, FR-REG-003, FR-UI-001 |
| `src/Infrastructure/Symfony/Controller/ScheduleController.php` | Dated schedule pages for `manage_team`: lists the tenant's rows, adds a tenant, group or worker schedule valid from today, and withdraws a row not yet valid. | FR-REG-002, FR-UI-001 |
| `src/Infrastructure/Symfony/Controller/SettingsController.php` | Register settings page (`manage_settings`, versioned, stale save answers 409 + Retry-After, missing-trigger warning) and the worker's own preferences page. | FR-REG-004, FR-TXN-002, FR-UI-001 |
| `src/Infrastructure/Symfony/Controller/TeamController.php` | Manager pages for `manage_team`: team day, a worker's date, pending correction requests with approve or reject on the shown version (stale gives 409), and manager-recorded corrections. | FR-REG-003, FR-UI-001, FR-ACCESS-001 |
| `src/Infrastructure/Symfony/Controller/UiSupport.php` | Shared stateless UI helper: rendering, area navigation, flashes, mapping gate failures to 404/403, the 409 + Retry-After conflict answer and CSRF form creation. | FR-UI-001, FR-TXN-002 |
| `src/Infrastructure/Symfony/Http/ConflictFallbackListener.php` | `kernel.exception` listener (priority 63) answering lock conflicts on the bundle's routes with 409 + Retry-After when transaction-doctrine's responder is absent or left a raw deadlock. | FR-TXN-002, FR-WORKER-001 |
| `src/Infrastructure/Symfony/Http/EntityManagerHygieneListener.php` | Clears the bundle's entity manager at the end of every request and console command (resetting it if closed) so nothing survives in a kept-alive kernel. | FR-WORKER-001 |
| `src/Infrastructure/Symfony/Http/WorkingTimeConflictFormatter.php` | Conflict response formatter for the bundle's routes: JSON bodies `correction_decision_stale`, `settings_stale` or `concurrency_conflict` with code `CONFLICT_001`/`CONFLICT_002`; HTML falls through. | FR-TXN-002 |
| `src/Infrastructure/Symfony/Routing/UiRouteLoader.php` | Route loader of type `nowo_working_time_ui` that, only when the UI is enabled, adds the register, team, schedule, export, holiday and leave routes per active part with UUID/date requirements and explicit controller tags. | FR-UI-001, FR-PARTS-001 |
| `src/Infrastructure/Symfony/Twig/WorkingTimeTwigExtension.php` | Twig extension with `working_time_header_clock()` (header quick action and day's worked time) and the `working_time_format_minutes` filter (`7:05`). | FR-TWIG-001, FR-UI-001 |

## Symfony forms

| Source file | What it does | Requirement IDs |
| --- | --- | --- |
| `src/Infrastructure/Symfony/Form/ActionType.php` | CSRF-only form for one-button actions (remove, archive, seed, refresh); the caller names the `csrf_token_id`. | FR-FORM-001, FR-UI-001 |
| `src/Infrastructure/Symfony/Form/AllowanceLineType.php` | Form for a worker's allowance line: signed adjustment, carry-over or protected carry-over, with a reason and optional expiry date. | FR-FORM-001, FR-LEAVE-002 |
| `src/Infrastructure/Symfony/Form/AllowancePolicyType.php` | Form for an allowance policy: leave type, scope (tenant, group, worker), year, amount in days or hours, agreement-equivalent mark and note. | FR-FORM-001, FR-LEAVE-001 |
| `src/Infrastructure/Symfony/Form/AnswerType.php` | Worker's answer to a manager-recorded correction: acknowledge or contest with a comment. | FR-FORM-001, FR-REG-003 |
| `src/Infrastructure/Symfony/Form/ClockType.php` | Clock action form, one per button, carrying the idempotency request key of the rendering so a double click acts once. | FR-FORM-001, FR-REG-001 |
| `src/Infrastructure/Symfony/Form/CorrectionType.php` | Day correction form: change a segment's times, add a missing segment or void one, with a 10-500 character reason; times local to the day's zone. | FR-FORM-001, FR-REG-003 |
| `src/Infrastructure/Symfony/Form/DecisionType.php` | Manager's decision form on a correction request: the version they saw (stale gives 409) and an optional comment. | FR-FORM-001, FR-REG-003 |
| `src/Infrastructure/Symfony/Form/ExportType.php` | Export request form: own copy, one worker's inspection export or team ZIP, first and last month (`Y-m`, at most 12) and format. | FR-FORM-001, FR-EXPORT-001 |
| `src/Infrastructure/Symfony/Form/FormProfile.php` | Holds the FormKit profile name (`working_time`) that all bundle form types read; the bundle class re-exports it so no layer depends on the composition root. | FR-FORM-001 |
| `src/Infrastructure/Symfony/Form/HolidayCalendarType.php` | Holiday calendar form: name, scope, year, source note and the place providers look up. | FR-FORM-001, FR-HOL-001 |
| `src/Infrastructure/Symfony/Form/HolidayDayType.php` | Form to add or edit a calendar's holiday by hand (date and name). | FR-FORM-001, FR-HOL-001 |
| `src/Infrastructure/Symfony/Form/HolidayImportConfirmType.php` | Confirms a file-import preview: new rows travel back as JSON and are validated again on the server. | FR-FORM-001, FR-HOL-002 |
| `src/Infrastructure/Symfony/Form/HolidayImportType.php` | Upload form for a CSV (`date,name,scope`) or ICS file to preview against a calendar. | FR-FORM-001, FR-HOL-002 |
| `src/Infrastructure/Symfony/Form/HolidayProviderType.php` | Form carrying the place a provider is asked about (preview) or the same place as hidden fields (confirm). | FR-FORM-001, FR-HOL-002 |
| `src/Infrastructure/Symfony/Form/LeaveSettingsType.php` | Tenant leave settings form with the version the admin saw for stale-save detection. | FR-FORM-001, FR-LEAVE-001 |
| `src/Infrastructure/Symfony/Form/LeaveTypeType.php` | Leave type form: name, paid, source, unit, approval, document, medical flag, colour, maximum per request and carry-over; no field for health detail. | FR-FORM-001, FR-LEAVE-001 |
| `src/Infrastructure/Symfony/Form/PreferencesType.php` | Worker preferences form: clock reminders (only if the tenant allows) and monthly summary (always on when mandatory). | FR-FORM-001, FR-REG-004 |
| `src/Infrastructure/Symfony/Form/ScheduleType.php` | Dated schedule form: scope, first valid date, time zone, per-weekday ranges (`09:00-14:00, 15:00-18:00`) and optional daily and weekly limits. | FR-FORM-001, FR-REG-002 |
| `src/Infrastructure/Symfony/Form/SettingsType.php` | Tenant register settings form with the version seen (stale gives 409); enabling IP evidence requires the notice text shown to workers. | FR-FORM-001, FR-REG-004 |
| `src/Infrastructure/Symfony/Form/WithdrawType.php` | CSRF-only form that withdraws a schedule row that is not valid yet. | FR-FORM-001, FR-REG-002 |
| `src/Infrastructure/Symfony/Form/WorkCentreType.php` | Work centre form: name, the holiday calendars that apply there and the default flag. | FR-FORM-001, FR-HOL-001 |

## Console commands

| Source file | What it does | Requirement IDs |
| --- | --- | --- |
| `src/Infrastructure/Symfony/Command/LeaveDailyCommand.php` | `nowo:working-time:leave:daily`: calls `LeaveAdministration` to expire carry-overs past their date, one audit row per tenant with the count. | FR-CLI-001, FR-LEAVE-003 |
| `src/Infrastructure/Symfony/Command/NoticesCommand.php` | `nowo:working-time:notices` (every 5 minutes): plans reminders and monthly summaries and delivers due outbox notices through `NoticeDispatcher`. | FR-CLI-001, FR-NOTICE-001 |
| `src/Infrastructure/Symfony/Command/PurgeRegisterCommand.php` | `nowo:working-time:purge [--dry-run]`: runs the retention purge then the expiry of export files; exit 0 done, 1 error, 2 triggers refused the deletes. | FR-CLI-001, FR-PURGE-001 |
| `src/Infrastructure/Symfony/Command/TriggersCommand.php` | `nowo:working-time:triggers:install`, `:check` and `:uninstall` (one class, three names): install exits 2 when the user cannot create triggers, check exits 1 while triggers are missing. | FR-CLI-001, FR-DB-002 |
| `src/Infrastructure/Symfony/Command/VerifyRegisterCommand.php` | `nowo:working-time:verify`: verifies every chain (or one tenant or worker), exit 1 on a broken chain; `--heads`, `--heads-out` and `--publish-heads` compare, write and audit chain heads. | FR-CLI-001, FR-CHAIN-001 |

## Exports and Messenger

| Source file | What it does | Requirement IDs |
| --- | --- | --- |
| `src/Infrastructure/Symfony/Export/RegisterCsv.php` | Builds the register CSV in stable payroll columns (UTF-8 with BOM), prefixes cells starting with `=`, `+`, `-`, `@`, tab or CR against formula injection, adds IP prefixes only when collected. | FR-EXPORT-001 |
| `src/Infrastructure/Symfony/Export/RegisterExportGenerator.php` | Generates one export: claims it so one generator wins, verifies the chains first (a broken chain is printed and audited), builds PDF, CSV or team ZIP, stores SHA-256 and heads, emits `RegisterExportReady` after commit. | FR-EXPORT-002, FR-EXPORT-001, FR-CHAIN-001 |
| `src/Infrastructure/Symfony/Export/RegisterReportBuilder.php` | Lays out one worker's inspection PDF: key figures, one row per day, corrections with reasons and approvers, and the integrity block, all translated in the export's locale. | FR-EXPORT-001, FR-PDF-001 |
| `src/Infrastructure/Symfony/Export/SyncExportDispatcher.php` | `ExportDispatcherInterface` that generates the export inside the request when `register.export.async` is `sync` or `auto` without Messenger. | FR-EXPORT-002 |
| `src/Infrastructure/Symfony/Messenger/GenerateRegisterExportHandler.php` | `#[AsMessageHandler]` that calls `ExportGeneratorInterface::generate()` for the message's export id, optionally inside transaction-doctrine's write-unit middleware. | FR-EXPORT-002 |
| `src/Infrastructure/Symfony/Messenger/GenerateRegisterExportMessage.php` | Immutable Messenger message holding the `exportId` of a register export to generate. | FR-EXPORT-002 |
| `src/Infrastructure/Symfony/Messenger/MessengerExportDispatcher.php` | `ExportDispatcherInterface` that queues `GenerateRegisterExportMessage` on the message bus when `register.export.async` is `messenger` or `auto` with Messenger. | FR-EXPORT-002 |

## Twig views

| Source file | What it does | Requirement IDs |
| --- | --- | --- |
| `src/Resources/views/email/notice.html.twig` | HTML e-mail of one working-time notice (subject, body, optional action link) with brand colours; rendered by `MailerNotifier`. | FR-NOTICE-001, FR-TWIG-001 |
| `src/Resources/views/export/register_pdf.html.twig` | Inspection PDF page of one worker's register: header, summary figures and tables, only escaped strings of `RegisterReport`, no remote resources; rendered by `DompdfRegisterPdfRenderer`. | FR-PDF-001, FR-EXPORT-001 |
| `src/Resources/views/holidays/_form.html.twig` | Shared partial rendering one bundle form in a UiKit card (title, errors, remaining fields, submit); included by holiday and leave pages. | FR-UI-001, FR-TWIG-001 |
| `src/Resources/views/holidays/calendar.html.twig` | One holiday calendar page: days list, forms to add a day, import CSV/ICS, provider imports with attribution, change and remove; rendered by `HolidayController`. | FR-HOL-001, FR-HOL-002, FR-UI-001 |
| `src/Resources/views/holidays/centre.html.twig` | Work centre edit page (name, calendars, default) with a remove form; rendered by `HolidayController`. | FR-HOL-001, FR-UI-001 |
| `src/Resources/views/holidays/day.html.twig` | Edit-one-holiday page (becomes a manual day); rendered by `HolidayController`. | FR-HOL-001, FR-UI-001 |
| `src/Resources/views/holidays/index.html.twig` | Holiday index: calendars list, add-calendar form, work centres list and form, optional municipality search; rendered by `HolidayController`. | FR-HOL-001, FR-UI-001 |
| `src/Resources/views/holidays/preview.html.twig` | Import preview page: rows with status, provisional and attribution notices, confirm form (nothing saved before); rendered by `HolidayController`. | FR-HOL-002, FR-UI-001 |
| `src/Resources/views/leave/allowances.html.twig` | Allowances page: policies of a year with remove buttons, forms to add a policy and a line (adjustment, carry-over), worker balances; rendered by `LeaveController`. | FR-LEAVE-001, FR-LEAVE-002, FR-UI-001 |
| `src/Resources/views/leave/balances.html.twig` | The actor's leave balances for a year as a UiKit list (entitled, taken, planned, pending, remaining); rendered by `LeaveController`. | FR-LEAVE-002, FR-UI-001 |
| `src/Resources/views/leave/settings.html.twig` | Leave settings page on the UiKit settings scaffold with form error display; rendered by `LeaveController`. | FR-LEAVE-001, FR-UI-001 |
| `src/Resources/views/leave/type.html.twig` | One leave type page: edit form and archive or restore button, with the no-health-data notice; rendered by `LeaveController`. | FR-LEAVE-001, FR-UI-001 |
| `src/Resources/views/leave/types.html.twig` | Leave types list with the seed-defaults button and add-type form; rendered by `LeaveController`. | FR-LEAVE-001, FR-UI-001 |
| `src/Resources/views/register/_correction_form.html.twig` | Partial card with the correction form (request or manager record) and CSRF-bearing submit; included by day and team day pages. | FR-REG-003, FR-UI-001 |
| `src/Resources/views/register/_header_clock.html.twig` | Header quick-action link showing the next clock action and worked minutes; rendered by `WorkingTimeTwigExtension`. | FR-REG-001, FR-UI-001, FR-TWIG-001 |
| `src/Resources/views/register/day.html.twig` | One day of the worker's register: sections, answer forms for manager-recorded corrections and the correction request form; rendered by `RegisterController`. | FR-REG-005, FR-REG-003, FR-UI-001 |
| `src/Resources/views/register/exports.html.twig` | Exports list with download links and the request form (own copy or inspection export); rendered by `ExportController`. | FR-EXPORT-001, FR-UI-001 |
| `src/Resources/views/register/month.html.twig` | Month view of the worker's register as a UiKit list with one row per day; rendered by `RegisterController`. | FR-REG-005, FR-UI-001 |
| `src/Resources/views/register/preferences.html.twig` | Worker preferences page on the UiKit form scaffold; rendered by `SettingsController`. | FR-REG-004, FR-UI-001 |
| `src/Resources/views/register/schedules.html.twig` | Dated schedules list with withdraw buttons for future rows and the add-schedule form; rendered by `ScheduleController`. | FR-REG-002, FR-UI-001 |
| `src/Resources/views/register/settings.html.twig` | Register settings page on the UiKit settings scaffold, with a missing-triggers warning and form errors; rendered by `SettingsController`. | FR-REG-004, FR-DB-002, FR-UI-001 |
| `src/Resources/views/register/team.html.twig` | Team page for managers: worker rows and pending correction requests with approve/reject forms carrying the seen version (stale gives 409); rendered by `TeamController`. | FR-REG-003, FR-UI-001, FR-TXN-002 |
| `src/Resources/views/register/team_day.html.twig` | A managed worker's day on the show scaffold, with the manager correction form unless it is the manager's own day; rendered by `TeamController`. | FR-REG-003, FR-REG-005, FR-UI-001 |
| `src/Resources/views/register/today.html.twig` | Today dashboard: clock card with one CSRF and idempotency-key POST form per allowed action, today's segments and IP-evidence notice; rendered by `RegisterController`. | FR-REG-001, FR-UI-001 |

## Translations

| Source file | What it does | Requirement IDs |
| --- | --- | --- |
| `src/Resources/translations/NowoWorkingTimeBundle.ca.yaml` | Catalan (`ca`) messages of domain `NowoWorkingTimeBundle`, 675 lines: nav, status, segment, clock, today, month, day, flag, history, correction, team, schedule, export, settings, preferences, notice, holiday, leave. | FR-I18N-001 |
| `src/Resources/translations/NowoWorkingTimeBundle.de.yaml` | German (`de`) messages of the same domain, same key groups (nav through leave), 675 lines. | FR-I18N-001 |
| `src/Resources/translations/NowoWorkingTimeBundle.en.yaml` | English (`en`) reference catalogue, 675 lines, key groups nav, status, segment, clock, today, month, day, flag, history, correction, team, schedule, export, settings, preferences, notice, holiday, leave. | FR-I18N-001 |
| `src/Resources/translations/NowoWorkingTimeBundle.es.yaml` | Spanish (`es`) messages of the same domain, same key groups, 675 lines. | FR-I18N-001 |
| `src/Resources/translations/NowoWorkingTimeBundle.eu.yaml` | Basque (`eu`) messages of the same domain, same key groups, 675 lines. | FR-I18N-001 |
| `src/Resources/translations/NowoWorkingTimeBundle.fr.yaml` | French (`fr`) messages of the same domain, same key groups, 675 lines. | FR-I18N-001 |
| `src/Resources/translations/NowoWorkingTimeBundle.gl.yaml` | Galician (`gl`) messages of the same domain, same key groups, 675 lines. | FR-I18N-001 |
| `src/Resources/translations/NowoWorkingTimeBundle.it.yaml` | Italian (`it`) messages of the same domain, same key groups, 675 lines. | FR-I18N-001 |
| `src/Resources/translations/NowoWorkingTimeBundle.nl.yaml` | Dutch (`nl`) messages of the same domain, same key groups, 675 lines. | FR-I18N-001 |
| `src/Resources/translations/NowoWorkingTimeBundle.pt.yaml` | Portuguese (`pt`) messages of the same domain, same key groups, 675 lines. | FR-I18N-001 |

## Coverage summary

| Category | Files |
| --- | --- |
| Bundle entry and DI | 7 |
| Shared kernel | 21 |
| Domain ports | 12 |
| Register domain: model and enums | 28 |
| Register domain: events and exceptions | 17 |
| Register domain: services, read models and hash chain | 20 |
| Holiday domain | 21 |
| Leave domain | 20 |
| Application: register | 30 |
| Application: holidays | 6 |
| Application: leave | 13 |
| Application: shared | 5 |
| Bridges | 9 |
| Doctrine storage | 15 |
| Doctrine XML mappings | 18 |
| Symfony adapters: default port implementations | 16 |
| Symfony UI: controllers, routing, HTTP, Twig extension | 14 |
| Symfony forms | 21 |
| Console commands | 5 |
| Exports and Messenger | 7 |
| Twig views | 24 |
| Translations | 10 |
| **Total mapped** | **339** |
| **`find src -type f \| wc -l`** | **339** |

Mapped = production files: **339/339** (100 %). Unmapped files: none.
