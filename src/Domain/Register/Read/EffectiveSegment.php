<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Read;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentSource;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentType;

use function max;

/**
 * A segment as the register shows it: the clocked values with the applied corrections on top. `original*` keep what
 * was clocked (null for a segment a correction added), so the history is never lost (FR-015).
 */
final readonly class EffectiveSegment
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        public string $id,
        public SegmentType $type,
        public DateTimeImmutable $start,
        public ?DateTimeImmutable $end,
        public SegmentSource $startSource,
        public ?SegmentSource $endSource,
        public bool $late,
        public bool $corrected,
        public bool $voided,
        public ?DateTimeImmutable $originalStart,
        public ?DateTimeImmutable $originalEnd,
        public ?string $startIpPrefix = null,
        public ?string $endIpPrefix = null,
    ) {
    }

    /** Whether it has no end yet. */
    public function isOpen(): bool
    {
        return !$this->end instanceof DateTimeImmutable;
    }

    /**
     * Seconds up to the end, or up to `$now` while open (0 when voided).
     */
    public function seconds(DateTimeImmutable $now): int
    {
        if ($this->voided) {
            return 0;
        }

        return max(0, ($this->end ?? $now)->getTimestamp() - $this->start->getTimestamp());
    }

    /**
     * The segment with a correction applied.
     */
    public function with(?DateTimeImmutable $start, ?DateTimeImmutable $end, bool $voided): self
    {
        return new self(
            $this->id,
            $this->type,
            $start ?? $this->start,
            $end ?? $this->end,
            $start instanceof DateTimeImmutable ? SegmentSource::Correction : $this->startSource,
            $end instanceof DateTimeImmutable ? SegmentSource::Correction : $this->endSource,
            $this->late,
            true,
            $voided || $this->voided,
            $this->originalStart,
            $this->originalEnd,
            $this->startIpPrefix,
            $this->endIpPrefix,
        );
    }
}
