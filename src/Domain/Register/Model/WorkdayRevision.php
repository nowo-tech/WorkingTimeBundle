<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Model;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Nowo\WorkingTimeBundle\Domain\Register\Chain\ChainCodecs;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ChainFormat;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\RevisionKind;
use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\RequestKey;
use Nowo\WorkingTimeBundle\Domain\Shared\Uuid;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;

use function mb_substr;

/**
 * One link of a worker's register chain in one tenant (spec 198 FR-015 – FR-018): a clock action, an applied
 * correction or the worker's answer to one. INSERT only: `seq` counts the links of the chain, `hash` is
 * `sha256(prev_hash ‖ canonical)` in the link's `chain_format` ({@see ChainCodecs}). New links are format 2 and chain
 * onto the head whatever its format (the first bundle link continues a migrated Beacon chain).
 */
class WorkdayRevision
{
    private string $hash;

    /**
     * @param array<string, mixed> $payload
     * @param array<string, int|null>|null $legacyRefs format 1 only: `{organization, user, workday, author}`
     */
    private function __construct(
        private string $id,
        private string $tenantId,
        private string $workerId,
        private string $workdayId,
        private int $seq,
        private RevisionKind $kind,
        private ?string $segmentId,
        private array $payload,
        private ?string $reason,
        private ?string $authorId,
        private string $authorName,
        private DateTimeImmutable $createdAt,
        private ?string $requestKey,
        private string $prevHash,
        private ChainFormat $chainFormat,
        private ?array $legacyRefs,
    ) {
        $this->hash = ChainCodecs::expectedHash($this);
    }

    /**
     * The link at `$seq` after the link (or anchor) whose hash is `$prevHash`, format 2. The chain's first link is
     * seq 1 after {@see ChainCodecs::GENESIS}; after a purge that emptied a chain, the anchor's seq and hash.
     *
     * @param array<string, mixed> $payload
     */
    public static function append(string $id, int $seq, string $prevHash, Workday $workday, RevisionKind $kind, ?string $segmentId, array $payload, ?string $reason, ?ActorRef $author, string $authorName, DateTimeInterface $at, ?RequestKey $requestKey): self
    {
        return new self(
            Uuid::assert($id),
            $workday->getTenantId(),
            $workday->getWorkerId(),
            $workday->getId(),
            $seq,
            $kind,
            $segmentId,
            $payload,
            $reason,
            $author?->value,
            mb_substr($authorName, 0, WorkerProfile::NAME_MAX),
            self::utc($at),
            $requestKey?->value,
            $prevHash,
            ChainFormat::Bundle,
            null,
        );
    }

    /**
     * A link copied from Beacon (format 1): every column as Beacon stored it, the hash recomputed from them; the
     * migration compares it with Beacon's stored hash and aborts on a difference.
     *
     * @param array<string, mixed> $payload
     * @param array{organization: int, user: int, workday: int, author: int|null} $legacyRefs
     */
    public static function migrated(string $id, Workday $workday, int $seq, RevisionKind $kind, ?string $segmentId, array $payload, ?string $reason, ?string $authorId, string $authorName, DateTimeInterface $at, ?string $requestKey, string $prevHash, array $legacyRefs): self
    {
        return new self(Uuid::assert($id), $workday->getTenantId(), $workday->getWorkerId(), $workday->getId(), $seq, $kind, $segmentId, $payload, $reason, $authorId, $authorName, self::utc($at), $requestKey, $prevHash, ChainFormat::Beacon, $legacyRefs);
    }

    /**
     * UTC at second precision (the hash must survive the database round trip).
     */
    public static function utc(DateTimeInterface $at): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $at->getTimestamp()))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * The stored form of an instant in payloads and the canonical JSON (`2026-11-10T18:10:00Z`).
     */
    public static function iso(DateTimeInterface $at): string
    {
        return self::utc($at)->format('Y-m-d\TH:i:s\Z');
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

    /** Position in the chain (1-based). */
    public function getSeq(): int
    {
        return $this->seq;
    }

    /** Kind of link. */
    public function getKind(): RevisionKind
    {
        return $this->kind;
    }

    /** The segment it is about. */
    public function getSegmentId(): ?string
    {
        return $this->segmentId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /** Correction reason or contest comment. */
    public function getReason(): ?string
    {
        return $this->reason;
    }

    /** Who wrote the link (null for a system link). */
    public function getAuthorId(): ?string
    {
        return $this->authorId;
    }

    /** Author's name as the register keeps it. */
    public function getAuthorName(): string
    {
        return $this->authorName;
    }

    /** When it was written (UTC, seconds). */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Idempotency key of the clock action. */
    public function getRequestKey(): ?string
    {
        return $this->requestKey;
    }

    /** Previous link's hash (or the genesis / an anchor). */
    public function getPrevHash(): string
    {
        return $this->prevHash;
    }

    /** This link's hash. */
    public function getHash(): string
    {
        return $this->hash;
    }

    /** Hash format. */
    public function getChainFormat(): ChainFormat
    {
        return $this->chainFormat;
    }

    /**
     * @return array<string, int|null>|null
     */
    public function getLegacyRefs(): ?array
    {
        return $this->legacyRefs;
    }
}
