<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register\Port;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Domain\Register\Model\ChainAnchor;
use Nowo\WorkingTimeBundle\Domain\Register\Model\CorrectionRequest;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterExport;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterNotice;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettings;
use Nowo\WorkingTimeBundle\Domain\Register\Model\ScheduleAssignment;
use Nowo\WorkingTimeBundle\Domain\Register\Model\Workday;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkdayRevision;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkerPreference;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkSegment;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

/**
 * Persistence of the register (implemented on the bundle's entity manager). The protected rows (workday, segment,
 * revision, anchor) only have `add*()` and reads: there is no update or delete path for them (spec 198 SC-001); a
 * segment's single close goes through {@see WorkSegment::close()}. Every lookup by a reference re-checks it with `===`
 * (rule LQ).
 */
interface RegisterStoreInterface
{
    // Settings and preferences.

    /** The tenant's settings, null when never saved. */
    public function settings(TenantRef $tenant): ?RegisterSettings;

    /**
     * Every tenant with settings (purge, notices).
     *
     * @return list<RegisterSettings>
     */
    public function allSettings(): array;

    /** Stores new settings. */
    public function addSettings(RegisterSettings $settings): void;

    /** A worker's preferences, null when never saved. */
    public function preference(WorkerRef $worker): ?WorkerPreference;

    /** Stores new preferences. */
    public function addPreference(WorkerPreference $preference): void;

    /** Deletes a worker's preferences (erasure). */
    public function removePreference(WorkerRef $worker): void;

    // Workdays and segments.

    /** A workday by id. */
    public function workday(string $id): ?Workday;

    /** The worker's workday on a date. */
    public function workdayOn(TenantRef $tenant, WorkerRef $worker, LocalDate $date): ?Workday;

    /**
     * The worker's latest workdays (newest first).
     *
     * @return list<Workday>
     */
    public function latestWorkdays(TenantRef $tenant, WorkerRef $worker, int $limit): array;

    /**
     * The worker's workdays between two dates, inclusive, by date.
     *
     * @return list<Workday>
     */
    public function workdaysBetween(TenantRef $tenant, WorkerRef $worker, LocalDate $from, LocalDate $to): array;

    /**
     * The tenant's workdays between two dates (optionally of some workers), by worker and date.
     *
     * @param list<WorkerRef>|null $workers
     *
     * @return list<Workday>
     */
    public function tenantWorkdaysBetween(TenantRef $tenant, LocalDate $from, LocalDate $to, ?array $workers = null): array;

    /**
     * Every workday of a worker in any tenant (GDPR export).
     *
     * @return list<Workday>
     */
    public function workdaysOfWorker(WorkerRef $worker): array;

    /** Stores a new workday. */
    public function addWorkday(Workday $workday): void;

    /** A segment by id. */
    public function segment(string $id): ?WorkSegment;

    /** Stores a new segment. */
    public function addSegment(WorkSegment $segment): void;

    /**
     * The segments of workdays, by workday id, by start.
     *
     * @param list<string> $workdayIds
     *
     * @return array<string, list<WorkSegment>>
     */
    public function segmentsOf(array $workdayIds): array;

    /**
     * Segments by id.
     *
     * @param list<string> $ids
     *
     * @return array<string, WorkSegment>
     */
    public function segmentsById(array $ids): array;

    // Chain.

    /**
     * The chain links of workdays, by workday id, in seq order.
     *
     * @param list<string> $workdayIds
     *
     * @return array<string, list<WorkdayRevision>>
     */
    public function revisionsOf(array $workdayIds): array;

    /** The chain's last link. */
    public function chainHead(TenantRef $tenant, WorkerRef $worker): ?WorkdayRevision;

    /** The link stored with a request key. */
    public function revisionByRequestKey(TenantRef $tenant, WorkerRef $worker, string $requestKey): ?WorkdayRevision;

    /** A link by id. */
    public function revision(string $id): ?WorkdayRevision;

    /** Stores a new link. */
    public function addRevision(WorkdayRevision $revision): void;

    /**
     * The chains (tenant, worker) — every pair with links, workdays or anchors — optionally of one tenant / worker.
     *
     * @return list<array{TenantRef, WorkerRef}>
     */
    public function chains(?TenantRef $tenant = null, ?WorkerRef $worker = null): array;

    /**
     * Up to `$limit` links of a chain after `$afterSeq`, in seq order.
     *
     * @return list<WorkdayRevision>
     */
    public function chainBatch(TenantRef $tenant, WorkerRef $worker, int $afterSeq, int $limit): array;

    /**
     * Every segment of a worker's workdays in a tenant (the verifier's cross-check), by start.
     *
     * @return list<array{id: string, start: DateTimeImmutable, end: ?DateTimeImmutable}>
     */
    public function segmentTimes(TenantRef $tenant, WorkerRef $worker): array;

    /**
     * Whether the worker's chain holds a link with this hash.
     */
    public function hasLinkHash(TenantRef $tenant, WorkerRef $worker, string $hash): bool;

    /**
     * The anchors of a chain, by first kept seq.
     *
     * @return list<ChainAnchor>
     */
    public function anchors(TenantRef $tenant, WorkerRef $worker): array;

    // Corrections.

    /** A correction request by id. */
    public function correction(string $id): ?CorrectionRequest;

    /** Stores a new correction request. */
    public function addCorrection(CorrectionRequest $request): void;

    /**
     * The correction requests of workdays, by workday id, oldest first.
     *
     * @param list<string> $workdayIds
     *
     * @return array<string, list<CorrectionRequest>>
     */
    public function correctionsOf(array $workdayIds): array;

    /**
     * Pending requests of the tenant (optionally of some workers), oldest first.
     *
     * @param list<WorkerRef>|null $workers
     *
     * @return list<CorrectionRequest>
     */
    public function pendingCorrections(TenantRef $tenant, ?array $workers = null, int $limit = 200): array;

    // Schedules.

    /**
     * The tenant's schedule rows valid on or before the date, newest validity first.
     *
     * @return list<ScheduleAssignment>
     */
    public function scheduleCandidates(TenantRef $tenant, LocalDate $until): array;

    /**
     * Every schedule row of the tenant, newest validity first.
     *
     * @return list<ScheduleAssignment>
     */
    public function schedules(TenantRef $tenant): array;

    /** A schedule row by id. */
    public function schedule(string $id): ?ScheduleAssignment;

    /** Stores a new schedule row. */
    public function addSchedule(ScheduleAssignment $schedule): void;

    /** Deletes a schedule row (only one not valid yet: the service checks). */
    public function removeSchedule(ScheduleAssignment $schedule): void;

    // Exports.

    /** An export by id. */
    public function export(string $id): ?RegisterExport;

    /** Stores a new export. */
    public function addExport(RegisterExport $export): void;

    /**
     * The tenant's latest exports (optionally of one requester).
     *
     * @return list<RegisterExport>
     */
    public function exports(TenantRef $tenant, ?string $requestedBy, int $limit): array;

    /**
     * Every export a person asked for or that is about them (GDPR).
     *
     * @return list<RegisterExport>
     */
    public function exportsAbout(string $ref): array;

    /**
     * Exports that recorded chain heads (every tenant, or one), oldest first.
     *
     * @return list<RegisterExport>
     */
    public function exportsWithHeads(?TenantRef $tenant): array;

    /** Moves a pending export to running; false when another generator won. */
    public function claimExport(string $id): bool;

    /**
     * Ready exports whose download window ended.
     *
     * @return list<RegisterExport>
     */
    public function expiredExports(DateTimeImmutable $now, int $limit): array;

    // Notices.

    /** Queues a notice; false when its dedupe key exists (never sent twice). */
    public function addNotice(RegisterNotice $notice): bool;

    /**
     * Unsent notices whose time has come, oldest first.
     *
     * @return list<RegisterNotice>
     */
    public function dueNotices(DateTimeImmutable $now, int $limit): array;

    /** Moves an unsent notice to a later instant (outside the recipient's hours at delivery). */
    public function deferNotice(string $id, DateTimeImmutable $deliverableFrom): bool;

    /** Marks a notice sent; false when another run sent it. */
    public function markNoticeSent(string $id, DateTimeImmutable $at): bool;

    /** Whether a notice with this dedupe key exists. */
    public function hasNotice(string $dedupeKey): bool;
}
