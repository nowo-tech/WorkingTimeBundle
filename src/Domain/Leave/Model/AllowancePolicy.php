<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Leave\Model;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\LeaveUnit;
use Nowo\WorkingTimeBundle\Domain\Leave\Exception\LeaveRefusedException;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ScheduleScope;
use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\Uuid;

use function mb_strlen;
use function mb_substr;
use function trim;

/**
 * A yearly allowance of one leave type (contract section 6.6; spec 204 FR-005): the tenant's default, one group's or
 * one worker's; the most specific wins (AllowanceResolver). The amount is in the type's stored unit. A vacation
 * allowance under the legal full-time minimum (art. 38 ET: 30 natural days, or the usual working-day equivalent of 22
 * days) for the tenant or a group is refused unless it is marked *agreement equivalent* with a note.
 */
class AllowancePolicy
{
    /** Art. 38 ET: 30 natural days (60 half-days). */
    public const LEGAL_MIN_NATURAL = 60;

    /** The usual working-day equivalent: 22 days (44 half-days). */
    public const LEGAL_MIN_WORKING = 44;

    private function __construct(
        private string $id,
        private string $tenantId,
        private string $leaveTypeId,
        private ScheduleScope $scope,
        private ?string $targetId,
        private int $leaveYear,
        private int $amount,
        private bool $agreementEquivalent,
        private ?string $note,
        private DateTimeImmutable $createdAt,
        private ?string $createdById,
    ) {
    }

    /**
     * A new policy.
     *
     * @throws LeaveRefusedException `leave.allowance.error.*`
     */
    public static function create(string $id, LeaveType $type, ScheduleScope $scope, ?string $targetId, int $year, int $amount, bool $agreementEquivalent, ?string $note, DateTimeImmutable $at, ?ActorRef $by): self
    {
        if (($scope === ScheduleScope::Tenant) !== ($targetId === null || $targetId === '')) {
            throw new LeaveRefusedException('leave.allowance.error.target');
        }
        if ($amount < 0 || $year < 2000 || $year > 2100) {
            throw new LeaveRefusedException('leave.allowance.error.amount');
        }
        $note = $note === null ? '' : trim($note);
        if ($type->getSeedCode() === 'vacation' && $scope !== ScheduleScope::Worker && $amount < self::legalMinimum($type->getUnit())) {
            if (!$agreementEquivalent || mb_strlen($note) < 10) {
                throw new LeaveRefusedException('leave.allowance.error.legal_minimum');
            }
        }

        return new self(Uuid::assert($id), $type->getTenantId(), $type->getId(), $scope, $scope === ScheduleScope::Tenant ? null : $targetId, $year, $amount, $agreementEquivalent, $note === '' ? null : mb_substr($note, 0, 255), $at->setTimezone(new DateTimeZone('UTC')), $by?->value);
    }

    /** The full-time legal minimum of a vacation type in its stored unit (0 for hours: the agreement decides). */
    public static function legalMinimum(LeaveUnit $unit): int
    {
        return match ($unit) {
            LeaveUnit::NaturalDays => self::LEGAL_MIN_NATURAL,
            LeaveUnit::WorkingDays => self::LEGAL_MIN_WORKING,
            LeaveUnit::Hours       => 0,
        };
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

    /** Leave type id. */
    public function getLeaveTypeId(): string
    {
        return $this->leaveTypeId;
    }

    /** Scope (tenant, group, worker). */
    public function getScope(): ScheduleScope
    {
        return $this->scope;
    }

    /** Group or worker reference (null for the tenant default). */
    public function getTargetId(): ?string
    {
        return $this->targetId;
    }

    /** Leave year. */
    public function getYear(): int
    {
        return $this->leaveYear;
    }

    /** Amount (stored units). */
    public function getAmount(): int
    {
        return $this->amount;
    }

    /** Whether the amount is the agreement's equivalent of the legal minimum. */
    public function isAgreementEquivalent(): bool
    {
        return $this->agreementEquivalent;
    }

    /** Note. */
    public function getNote(): ?string
    {
        return $this->note;
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
