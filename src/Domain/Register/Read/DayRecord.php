<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Read;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\DayFlag;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentType;
use Nowo\WorkingTimeBundle\Domain\Register\Model\CorrectionRequest;
use Nowo\WorkingTimeBundle\Domain\Register\Model\Workday;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkdayRevision;

use function array_map;
use function in_array;
use function max;

/**
 * One workday as the register shows it (spec 198 FR-010 / FR-020): the effective segments (corrections applied), the
 * totals, the flags and the trail (every chain link of the day and the correction requests).
 */
final readonly class DayRecord
{
    /**
     * @param list<EffectiveSegment> $segments effective, by start
     * @param list<DayFlag> $flags
     * @param list<WorkdayRevision> $revisions the day's chain links
     * @param list<CorrectionRequest> $requests
     * @param list<string> $pendingAcknowledgements ids of recorded corrections the worker has not answered
     */
    public function __construct(
        public Workday $workday,
        public array $segments,
        public int $workSeconds,
        public int $breakSeconds,
        public int $workedSeconds,
        public array $flags,
        public array $revisions,
        public array $requests,
        public array $pendingAcknowledgements,
    ) {
    }

    /** Whether the day carries the flag. */
    public function has(DayFlag $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }

    /** The live open segment (not stale, not voided), if any. */
    public function openSegment(): ?EffectiveSegment
    {
        if ($this->has(DayFlag::MissingClockOut)) {
            return null;
        }
        foreach ($this->segments as $segment) {
            if ($segment->isOpen() && !$segment->voided) {
                return $segment;
            }
        }

        return null;
    }

    /** First work start. */
    public function firstStart(): ?DateTimeImmutable
    {
        foreach ($this->segments as $segment) {
            if (!$segment->voided && $segment->type === SegmentType::Work) {
                return $segment->start;
            }
        }

        return null;
    }

    /** Last end. */
    public function lastEnd(): ?DateTimeImmutable
    {
        $end = null;
        foreach ($this->segments as $segment) {
            if (!$segment->voided && $segment->end !== null && ($end === null || $segment->end > $end)) {
                $end = $segment->end;
            }
        }

        return $end;
    }

    /** Seconds above the daily threshold (0 when none applies). */
    public function overtimeSeconds(): int
    {
        $threshold = $this->workday->schedule()->dailyThreshold();

        return $threshold === null ? 0 : max(0, $this->workedSeconds - $threshold);
    }

    /**
     * The flag values (client API, CSV).
     *
     * @return list<string>
     */
    public function flagValues(): array
    {
        return array_map(static fn (DayFlag $flag): string => $flag->value, $this->flags);
    }
}
