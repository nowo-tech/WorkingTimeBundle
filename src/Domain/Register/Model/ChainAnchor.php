<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Model;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Domain\Shared\Uuid;

/**
 * Where a chain starts after a retention purge (contract F2, section 6.3): the hash of the last purged link and the
 * first kept `seq`. Written by the purge in the same transaction as its deletes; INSERT only. The verifier accepts a
 * chain start only when it is the genesis or a recorded anchor, so deleting the oldest links by hand is detected.
 */
class ChainAnchor
{
    private function __construct(
        private string $id,
        private string $tenantId,
        private string $workerId,
        private int $firstKeptSeq,
        private string $anchorHash,
        private int $purgedLinks,
        private DateTimeImmutable $purgedAt,
        private string $purgeRunId,
    ) {
    }

    /**
     * A new anchor.
     */
    public static function record(string $id, string $tenantId, string $workerId, int $firstKeptSeq, string $anchorHash, int $purgedLinks, DateTimeImmutable $purgedAt, string $purgeRunId): self
    {
        return new self(Uuid::assert($id), $tenantId, $workerId, $firstKeptSeq, $anchorHash, $purgedLinks, WorkdayRevision::utc($purgedAt), $purgeRunId);
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

    /** First kept seq. */
    public function getFirstKeptSeq(): int
    {
        return $this->firstKeptSeq;
    }

    /** Hash of the last purged link. */
    public function getAnchorHash(): string
    {
        return $this->anchorHash;
    }

    /** Links purged in that batch. */
    public function getPurgedLinks(): int
    {
        return $this->purgedLinks;
    }

    /** When. */
    public function getPurgedAt(): DateTimeImmutable
    {
        return $this->purgedAt;
    }

    /** Purge run id. */
    public function getPurgeRunId(): string
    {
        return $this->purgeRunId;
    }
}
