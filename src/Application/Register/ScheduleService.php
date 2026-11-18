<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register;

use Nowo\WorkingTimeBundle\Application\Register\Port\RegisterStoreInterface;
use Nowo\WorkingTimeBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\WorkingTimeBundle\Domain\Holiday\Service\HolidayCalendarReaderInterface;
use Nowo\WorkingTimeBundle\Domain\Port\IdGeneratorInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkerDirectoryInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeAuditSinkInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ScheduleScope;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\RegisterUnavailableException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\ScheduleRefusedException;
use Nowo\WorkingTimeBundle\Domain\Register\Model\DaySchedule;
use Nowo\WorkingTimeBundle\Domain\Register\Model\ScheduleAssignment;
use Nowo\WorkingTimeBundle\Domain\Register\Model\ScheduleDefinition;
use Nowo\WorkingTimeBundle\Domain\Register\Service\ScheduleServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\AuditEntry;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkingTimePermission;
use Psr\Clock\ClockInterface;

/**
 * Dated schedules (spec 198 FR-003, contract F10): a new row is valid from today or later and never changes a past day
 * (each workday keeps its snapshot); only a row not valid yet may be withdrawn.
 */
final readonly class ScheduleService implements ScheduleServiceInterface
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private RegisterAccess $access,
        private RegisterStoreInterface $store,
        private ScheduleResolver $resolver,
        private WorkerDirectoryInterface $directory,
        private TransactionRunnerInterface $runner,
        private IdGeneratorInterface $ids,
        private WorkingTimeAuditSinkInterface $audit,
        private ClockInterface $clock,
        private ?HolidayCalendarReaderInterface $holidays = null,
    ) {
    }

    /**
     * Adds a dated row.
     */
    public function assign(ScheduleScope $scope, ?string $target, LocalDate $validFrom, ScheduleDefinition $definition, ?string $workCentreId = null): ScheduleAssignment
    {
        $context = match ($scope) {
            ScheduleScope::Worker => $this->access->context(WorkingTimePermission::ManageTeam, new WorkerRef((string) $target)),
            default               => $this->access->context(WorkingTimePermission::ManageTeam),
        };
        if ($scope === ScheduleScope::Group && !$this->knownGroup($context->tenant, (string) $target)) {
            throw new ScheduleRefusedException('schedule.error.group');
        }
        if ($workCentreId !== null && !$this->knownCentre($context->tenant, $workCentreId)) {
            throw new ScheduleRefusedException('schedule.error.work_centre');
        }
        $now     = $this->clock->now();
        [$today] = $this->resolver->at($context->settings, $context->actorProfile, $now);
        if ($validFrom->compare($today) < 0) {
            throw new ScheduleRefusedException('schedule.error.past');
        }

        return $this->runner->write(function () use ($context, $scope, $target, $validFrom, $definition, $workCentreId, $now): ScheduleAssignment { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $row = ScheduleAssignment::create($this->ids->generate(), $context->tenant->value, $scope, $target, $validFrom, $definition, $workCentreId, $now, $context->actor);
            $this->store->addSchedule($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            $this->audit->record(new AuditEntry('working_time.schedule_assigned', $context->tenant, $context->actor, $scope === ScheduleScope::Worker ? new WorkerRef((string) $target) : null, ['schedule' => $row->getId(), 'scope' => $scope->value, 'valid_from' => $validFrom->value]));

            return $row;
        });
    }

    /**
     * Withdraws a future row.
     */
    public function withdrawFuture(string $scheduleId): void
    {
        $context = $this->access->context(WorkingTimePermission::ManageTeam);
        $now     = $this->clock->now();
        [$today] = $this->resolver->at($context->settings, $context->actorProfile, $now);
        $this->runner->write(function () use ($context, $scheduleId, $today): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $row = $this->store->schedule($scheduleId);
            if (!$row instanceof ScheduleAssignment || $row->getTenantId() !== $context->tenant->value) {
                throw new RegisterUnavailableException();
            }
            if ($row->getScope() === ScheduleScope::Worker) {
                $this->access->context(WorkingTimePermission::ManageTeam, new WorkerRef((string) $row->getTargetId()));
            }
            if ($row->validFrom()->compare($today) <= 0) {
                throw new ScheduleRefusedException('schedule.error.not_future');
            }
            $this->store->removeSchedule($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            $this->audit->record(new AuditEntry('working_time.schedule_withdrawn', $context->tenant, $context->actor, null, ['schedule' => $row->getId()]));
        });
    }

    /**
     * The schedule of a worker's date.
     */
    public function resolve(WorkerRef $worker, LocalDate $date): DaySchedule
    {
        $own     = $this->access->can(WorkingTimePermission::Clock) && $this->directory->current()?->equals($worker) === true;
        $context = $this->access->context($own ? WorkingTimePermission::Clock : WorkingTimePermission::ManageTeam, $worker);

        return $this->runner->read(fn (): DaySchedule => $this->resolver->forDate($context->settings, $context->subject, $date));
    }

    /**
     * The tenant's rows.
     *
     * @return list<ScheduleAssignment>
     */
    public function list(): array
    {
        $context = $this->access->context(WorkingTimePermission::ManageTeam);

        return $this->runner->read(fn (): array => $this->store->schedules($context->tenant));
    }

    private function knownGroup(TenantRef $tenant, string $target): bool
    {
        foreach ($this->directory->groups($tenant) as [$group]) {
            if ($group->value === $target) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the work centre is one of the tenant's (holiday component; without it no centre can be named).
     */
    private function knownCentre(TenantRef $tenant, string $centreId): bool
    {
        foreach ($this->holidays?->workCentres($tenant) ?? [] as $centre) {
            if ($centre->getId() === $centreId) {
                return true;
            }
        }

        return false;
    }
}
