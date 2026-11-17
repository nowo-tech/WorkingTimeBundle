<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Model;

use DateTimeImmutable;
use LogicException;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\CorrectionOperation;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\CorrectionStatus;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentType;
use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\Uuid;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;

use function mb_substr;

/**
 * A worker's request to correct one of their days (spec 198 FR-014 / FR-015). A request, not the record: it is
 * updatable (its decision), and every decision is checked against the `version` the manager saw (contract section
 * 15.2: a stale decision answers 409 and stores nothing). An approval writes a chain link; the request keeps its id.
 */
class CorrectionRequest
{
    /** Shortest reason. */
    public const REASON_MIN = 10;

    /** Longest reason or comment. */
    public const REASON_MAX = 500;

    private CorrectionStatus $status = CorrectionStatus::Pending;

    private ?string $decidedById = null;

    private ?string $decidedByName = null;

    private ?DateTimeImmutable $decidedAt = null;

    private ?string $decisionComment = null;

    private ?string $revisionId = null;

    /** Optimistic lock (Doctrine `version`). */
    private int $version = 1;

    private function __construct(
        private string $id,
        private string $tenantId,
        private string $workerId,
        private string $workdayId,
        private CorrectionOperation $operation,
        private ?string $segmentId,
        private ?SegmentType $segmentType,
        private ?DateTimeImmutable $newStart,
        private ?DateTimeImmutable $newEnd,
        private string $reason,
        private DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * A new pending request on the workday.
     */
    public static function file(string $id, Workday $workday, CorrectionOperation $operation, ?string $segmentId, ?SegmentType $segmentType, ?DateTimeImmutable $newStart, ?DateTimeImmutable $newEnd, string $reason, DateTimeImmutable $at): self
    {
        return new self(
            Uuid::assert($id),
            $workday->getTenantId(),
            $workday->getWorkerId(),
            $workday->getId(),
            $operation,
            $segmentId,
            $segmentType,
            $newStart instanceof DateTimeImmutable ? WorkdayRevision::utc($newStart) : null,
            $newEnd instanceof DateTimeImmutable ? WorkdayRevision::utc($newEnd) : null,
            $reason,
            WorkdayRevision::utc($at),
        );
    }

    /**
     * Approves the request; the change is the chain link `$revisionId`.
     */
    public function approve(ActorRef $manager, string $managerName, ?string $comment, string $revisionId, DateTimeImmutable $at): void
    {
        $this->decide(CorrectionStatus::Approved, $manager, $managerName, $comment, $at);
        $this->revisionId = $revisionId; // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
    }

    /**
     * Rejects the request (nothing in the register changes).
     */
    public function reject(ActorRef $manager, string $managerName, ?string $comment, DateTimeImmutable $at): void
    {
        $this->decide(CorrectionStatus::Rejected, $manager, $managerName, $comment, $at);
    }

    private function decide(CorrectionStatus $status, ActorRef $manager, string $managerName, ?string $comment, DateTimeImmutable $at): void
    {
        if ($this->status !== CorrectionStatus::Pending) {
            throw new LogicException('A correction request is decided once.');
        }
        $this->status          = $status; // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        $this->decidedById     = $manager->value; // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        $this->decidedByName   = mb_substr($managerName, 0, WorkerProfile::NAME_MAX); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        $this->decidedAt       = WorkdayRevision::utc($at); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        $this->decisionComment = $comment; // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
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

    /** Workday id. */
    public function getWorkdayId(): string
    {
        return $this->workdayId;
    }

    /** Operation. */
    public function getOperation(): CorrectionOperation
    {
        return $this->operation;
    }

    /** Segment changed or voided (null: add). */
    public function getSegmentId(): ?string
    {
        return $this->segmentId;
    }

    /** Type of the added segment. */
    public function getSegmentType(): ?SegmentType
    {
        return $this->segmentType;
    }

    /** New start. */
    public function getNewStart(): ?DateTimeImmutable
    {
        return $this->newStart;
    }

    /** New end. */
    public function getNewEnd(): ?DateTimeImmutable
    {
        return $this->newEnd;
    }

    /** The worker's reason. */
    public function getReason(): string
    {
        return $this->reason;
    }

    /** When it was filed. */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Status. */
    public function getStatus(): CorrectionStatus
    {
        return $this->status;
    }

    /** Whether it waits for a manager. */
    public function isPending(): bool
    {
        return $this->status === CorrectionStatus::Pending;
    }

    /** Who decided. */
    public function getDecidedById(): ?string
    {
        return $this->decidedById;
    }

    /** Their name. */
    public function getDecidedByName(): ?string
    {
        return $this->decidedByName;
    }

    /** When. */
    public function getDecidedAt(): ?DateTimeImmutable
    {
        return $this->decidedAt;
    }

    /** The manager's comment. */
    public function getDecisionComment(): ?string
    {
        return $this->decisionComment;
    }

    /** The chain link of an approval. */
    public function getRevisionId(): ?string
    {
        return $this->revisionId;
    }

    /** Version the decision is checked against. */
    public function getVersion(): int
    {
        return $this->version;
    }
}
