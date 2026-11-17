<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register\Port;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;

/**
 * The only deleting path of the protected register rows (contract section 6.5): the retention purge. One batch is one
 * transaction that lifts the trigger guard for its own statements only (MySQL / MariaDB session variable set and
 * cleared in `finally`; PostgreSQL `SET LOCAL`), deletes the workdays' revisions, segments, corrections and the
 * workdays, and writes each chain's anchor in the same transaction.
 */
interface RegisterPurgeStoreInterface
{
    /**
     * Ids of up to `$limit` workdays of the tenant before the date (keyset by id).
     *
     * @return list<string>
     */
    public function workdaysBefore(TenantRef $tenant, LocalDate $firstKept, int $limit): array;

    /** Workdays of the tenant before the date (dry run). */
    public function countWorkdaysBefore(TenantRef $tenant, LocalDate $firstKept): int;

    /**
     * Deletes one batch and records the anchors.
     *
     * @param list<string> $workdayIds
     *
     * @throws PurgeRefusedException when the triggers refused the deletes (guard misconfigured)
     *
     * @return array{workdays: int, segments: int, revisions: int, corrections: int, anchors: int}
     */
    public function deleteBatch(array $workdayIds, string $runId, DateTimeImmutable $at, ?LocalDate $firstKept = null): array;

    /** Deletes delivered notices sent before the instant. */
    public function deleteNoticesSentBefore(DateTimeImmutable $before): int;
}
