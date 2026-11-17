<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Read;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ClockAction;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ClockStatus;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;

use function array_filter;
use function array_values;

/**
 * Where a worker's clock stands (spec 198 FR-008 / FR-013): the status, the day it belongs to (the open workday, or
 * today's local date), that day's record and the actions allowed now. One shape for the web, the header and the client
 * API.
 */
final readonly class ClockState
{
    public function __construct(
        public ClockStatus $status,
        public LocalDate $date,
        public string $timezone,
        public ?DayRecord $record,
        public DateTimeImmutable $now,
        public ?string $reminder = null,
    ) {
    }

    /**
     * @return list<ClockAction>
     */
    public function allowedActions(): array
    {
        return array_values(array_filter(ClockAction::cases(), fn (ClockAction $action): bool => $action->allowedIn($this->status)));
    }

    /** The action the header button offers (clock in, else clock out; on a break: end it). */
    public function primaryAction(): ClockAction
    {
        return match ($this->status) {
            ClockStatus::Idle, ClockStatus::Closed => ClockAction::ClockIn,
            ClockStatus::Working => ClockAction::ClockOut,
            ClockStatus::OnBreak => ClockAction::BreakEnd,
        };
    }

    /** Worked seconds of the day so far. */
    public function workedSeconds(): int
    {
        return $this->record->workedSeconds ?? 0;
    }

    /** Break seconds of the day so far. */
    public function breakSeconds(): int
    {
        return $this->record->breakSeconds ?? 0;
    }

    /**
     * A copy with a reminder text key.
     */
    public function withReminder(?string $reminder): self
    {
        return new self($this->status, $this->date, $this->timezone, $this->record, $this->now, $reminder);
    }
}
