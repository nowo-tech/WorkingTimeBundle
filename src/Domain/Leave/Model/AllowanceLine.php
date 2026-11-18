<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Leave\Model;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\AllowanceLineKind;
use Nowo\WorkingTimeBundle\Domain\Leave\Exception\LeaveRefusedException;
use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\Uuid;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

use function mb_strlen;
use function preg_replace;
use function trim;

/**
 * A line on a worker's yearly allowance of one type (contract section 6.6; spec 204 FR-007): a signed adjustment, a
 * carry-over or a protected carry-over, always with a reason (3–255 characters, audited by id only). Append-only in
 * code: lines are never edited, a correction is a new line. A carry-over stops counting after `expires_on`; the daily
 * job marks it `expired_at` once.
 */
class AllowanceLine
{
    private ?DateTimeImmutable $expiresOn;

    private ?DateTimeImmutable $expiredAt = null;

    private function __construct(
        private string $id,
        private string $tenantId,
        private string $workerId,
        private string $leaveTypeId,
        private int $leaveYear,
        private AllowanceLineKind $lineKind,
        private int $amount,
        private string $reason,
        ?LocalDate $expiresOn,
        private DateTimeImmutable $createdAt,
        private ?string $createdById,
    ) {
        $this->expiresOn = $expiresOn?->toDateTime();
    }

    /**
     * A new line.
     *
     * @throws LeaveRefusedException `leave.allowance.error.reason` | `leave.allowance.error.line_amount`
     */
    public static function create(string $id, LeaveType $type, WorkerRef $worker, int $year, AllowanceLineKind $kind, int $amount, string $reason, ?LocalDate $expiresOn, DateTimeImmutable $at, ?ActorRef $by): self
    {
        $reason = trim((string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $reason));
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) {
            throw new LeaveRefusedException('leave.allowance.error.reason');
        }
        if ($amount === 0 || ($kind !== AllowanceLineKind::Adjustment && $amount < 0) || $year < 2000 || $year > 2100) {
            throw new LeaveRefusedException('leave.allowance.error.line_amount');
        }

        return new self(Uuid::assert($id), $type->getTenantId(), $worker->value, $type->getId(), $year, $kind, $amount, $reason, $expiresOn, $at->setTimezone(new DateTimeZone('UTC')), $by?->value);
    }

    /** Marks the line expired (the daily job); a second call keeps the first instant. */
    public function expire(DateTimeImmutable $at): void
    {
        $this->expiredAt ??= $at->setTimezone(new DateTimeZone('UTC')); // @igor-ignore - entity state, not a service
    }

    /** Whether the line counts on a date (a carry-over stops counting the day after its expiry). */
    public function countsOn(LocalDate $date): bool
    {
        $expires = $this->expiresOn();

        return !$this->expiredAt instanceof DateTimeImmutable && (!$expires instanceof LocalDate || $date->compare($expires) <= 0);
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

    /** Worker reference. */
    public function getWorkerId(): string
    {
        return $this->workerId;
    }

    /** Leave type id. */
    public function getLeaveTypeId(): string
    {
        return $this->leaveTypeId;
    }

    /** Leave year it counts in. */
    public function getYear(): int
    {
        return $this->leaveYear;
    }

    /** Kind. */
    public function getKind(): AllowanceLineKind
    {
        return $this->lineKind;
    }

    /** Signed amount (stored units). */
    public function getAmount(): int
    {
        return $this->amount;
    }

    /** Reason (shown to the worker and to `leave_manage`, never in audit rows). */
    public function getReason(): string
    {
        return $this->reason;
    }

    /** Last day it counts. */
    public function expiresOn(): ?LocalDate
    {
        return $this->expiresOn instanceof DateTimeImmutable ? LocalDate::fromDateTime($this->expiresOn) : null;
    }

    /** When the daily job expired it. */
    public function getExpiredAt(): ?DateTimeImmutable
    {
        return $this->expiredAt;
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
