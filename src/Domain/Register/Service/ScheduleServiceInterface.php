<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Service;

use Nowo\WorkingTimeBundle\Domain\Register\Enum\ScheduleScope;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\PermissionDeniedException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\RegisterUnavailableException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\ScheduleRefusedException;
use Nowo\WorkingTimeBundle\Domain\Register\Model\DaySchedule;
use Nowo\WorkingTimeBundle\Domain\Register\Model\ScheduleAssignment;
use Nowo\WorkingTimeBundle\Domain\Register\Model\ScheduleDefinition;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

/**
 * Dated schedules: worker → group → tenant (contract section 7). Changes need `manage_team`.
 *
 * @throws RegisterUnavailableException
 * @throws PermissionDeniedException
 * @throws ScheduleRefusedException
 */
interface ScheduleServiceInterface
{
    /**
     * Adds a dated row (valid from today or later).
     */
    public function assign(ScheduleScope $scope, ?string $target, LocalDate $validFrom, ScheduleDefinition $definition, ?string $workCentreId = null): ScheduleAssignment;

    /**
     * Withdraws a row that is not valid yet (past and current rows are history).
     */
    public function withdrawFuture(string $scheduleId): void;

    /**
     * The schedule a worker's date is measured against (own, or a managed worker's).
     */
    public function resolve(WorkerRef $worker, LocalDate $date): DaySchedule;

    /**
     * The tenant's rows, newest validity first.
     *
     * @return list<ScheduleAssignment>
     */
    public function list(): array;
}
