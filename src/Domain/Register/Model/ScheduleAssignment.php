<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Model;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ScheduleScope;
use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\Uuid;

/**
 * A dated schedule row (spec 198 FR-003): the tenant's default, a group's or one worker's schedule, valid from a date
 * until a newer row of the same target. Configuration, not the legal record (the workday keeps its own snapshot):
 * only a row not yet valid may be withdrawn (contract F10).
 */
class ScheduleAssignment
{
    /** @var array<string, mixed> */
    private array $definition;

    private function __construct(
        private string $id,
        private string $tenantId,
        private ScheduleScope $scope,
        private ?string $targetId,
        private DateTimeImmutable $validFrom,
        ScheduleDefinition $definition,
        private ?string $workCentreId,
        private DateTimeImmutable $createdAt,
        private ?string $createdById,
    ) {
        $this->definition = $definition->toArray();
    }

    /**
     * A new row.
     */
    public static function create(string $id, string $tenantId, ScheduleScope $scope, ?string $targetId, LocalDate $validFrom, ScheduleDefinition $definition, ?string $workCentreId, DateTimeImmutable $at, ?ActorRef $by): self
    {
        return new self(Uuid::assert($id), $tenantId, $scope, $scope === ScheduleScope::Tenant ? null : $targetId, $validFrom->toDateTime(), $definition, $workCentreId, WorkdayRevision::utc($at), $by?->value);
    }

    /** Row id. */
    public function getId(): string
    {
        return $this->id;
    }

    /** Tenant reference. */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    /** Scope. */
    public function getScope(): ScheduleScope
    {
        return $this->scope;
    }

    /** Group or worker reference (null for the tenant default). */
    public function getTargetId(): ?string
    {
        return $this->targetId;
    }

    /** First valid date. */
    public function validFrom(): LocalDate
    {
        return LocalDate::fromDateTime($this->validFrom);
    }

    /** The weekly schedule. */
    public function definition(): ScheduleDefinition
    {
        return ScheduleDefinition::fromArray($this->definition);
    }

    /** Work centre (holiday calendars, S2). */
    public function getWorkCentreId(): ?string
    {
        return $this->workCentreId;
    }

    /** When it was created. */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Who created it. */
    public function getCreatedById(): ?string
    {
        return $this->createdById;
    }
}
