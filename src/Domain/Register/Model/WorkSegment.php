<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Model;

use DateTimeImmutable;
use LogicException;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentSource;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentType;
use Nowo\WorkingTimeBundle\Domain\Shared\IpPrefix;
use Nowo\WorkingTimeBundle\Domain\Shared\Uuid;

use function abs;

/**
 * A stretch of work or break as it was clocked (spec 198 FR-008 / FR-011 / FR-017): start and end in UTC, where each
 * came from, when the server received it and, when the tenant enabled IP evidence, the /24 or /64 prefix. The only
 * change a row ever gets is its end, once ({@see self::close()}); corrections are chain links on top of it, and the
 * database refuses any other UPDATE or DELETE.
 */
class WorkSegment
{
    private ?DateTimeImmutable $endedAt = null;

    private ?SegmentSource $endSource = null;

    private ?DateTimeImmutable $endReceivedAt = null;

    private ?string $endIpPrefix = null;

    private function __construct(
        private string $id,
        private string $workdayId,
        private SegmentType $type,
        private DateTimeImmutable $startedAt,
        private SegmentSource $startSource,
        private DateTimeImmutable $startReceivedAt,
        private ?string $startIpPrefix,
    ) {
    }

    /**
     * A new open segment of the workday.
     */
    public static function start(string $id, Workday $workday, SegmentType $type, DateTimeImmutable $at, SegmentSource $source, DateTimeImmutable $receivedAt, ?IpPrefix $ip): self
    {
        return new self(Uuid::assert($id), $workday->getId(), $type, WorkdayRevision::utc($at), $source, WorkdayRevision::utc($receivedAt), $ip?->value);
    }

    /**
     * The segment's end, once: a closed segment is never changed again.
     *
     * @throws LogicException when already closed
     */
    public function close(DateTimeImmutable $at, SegmentSource $source, DateTimeImmutable $receivedAt, ?IpPrefix $ip): void
    {
        if ($this->endedAt instanceof DateTimeImmutable) {
            throw new LogicException('A register segment is closed once.');
        }
        $this->endedAt       = WorkdayRevision::utc($at); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        $this->endSource     = $source; // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        $this->endReceivedAt = WorkdayRevision::utc($receivedAt); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        $this->endIpPrefix   = $ip?->value; // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
    }

    /** Row id. */
    public function getId(): string
    {
        return $this->id;
    }

    /** Workday id. */
    public function getWorkdayId(): string
    {
        return $this->workdayId;
    }

    /** Work or break. */
    public function getType(): SegmentType
    {
        return $this->type;
    }

    /** Start (UTC). */
    public function getStartedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    /** End (UTC), null while open. */
    public function getEndedAt(): ?DateTimeImmutable
    {
        return $this->endedAt;
    }

    /** Whether the segment is still open. */
    public function isOpen(): bool
    {
        return !$this->endedAt instanceof DateTimeImmutable;
    }

    /** Where the start came from. */
    public function getStartSource(): SegmentSource
    {
        return $this->startSource;
    }

    /** Where the end came from. */
    public function getEndSource(): ?SegmentSource
    {
        return $this->endSource;
    }

    /** When the server received the start. */
    public function getStartReceivedAt(): DateTimeImmutable
    {
        return $this->startReceivedAt;
    }

    /** When the server received the end. */
    public function getEndReceivedAt(): ?DateTimeImmutable
    {
        return $this->endReceivedAt;
    }

    /** IP prefix of the start. */
    public function getStartIpPrefix(): ?string
    {
        return $this->startIpPrefix;
    }

    /** IP prefix of the end. */
    public function getEndIpPrefix(): ?string
    {
        return $this->endIpPrefix;
    }

    /**
     * Whether the start or the end arrived more than `$lateSeconds` after the time it records (FR-011).
     */
    public function isLate(int $lateSeconds): bool
    {
        return abs($this->startReceivedAt->getTimestamp() - $this->startedAt->getTimestamp()) > $lateSeconds
            || ($this->endedAt instanceof DateTimeImmutable && $this->endReceivedAt instanceof DateTimeImmutable && abs($this->endReceivedAt->getTimestamp() - $this->endedAt->getTimestamp()) > $lateSeconds);
    }
}
