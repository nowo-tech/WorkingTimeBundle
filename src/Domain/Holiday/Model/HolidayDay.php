<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Holiday\Model;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\HolidayOrigin;
use Nowo\WorkingTimeBundle\Domain\Holiday\Exception\HolidayRefusedException;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayEntry;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\Uuid;

/**
 * One public holiday of a calendar (contract section 6.7): a date (one per calendar), its public name, where it came
 * from and the source a provider cites. Edited by hand, it becomes a manual day that no import touches again.
 */
class HolidayDay
{
    private DateTimeImmutable $holidayDate;

    private string $name;

    private ?string $sourceRef;

    private ?string $sourceUrl;

    private function __construct(
        private string $id,
        private string $tenantId,
        private string $calendarId,
        LocalDate $date,
        string $name,
        private HolidayOrigin $origin,
        ?string $sourceRef,
        ?string $sourceUrl,
    ) {
        $entry             = $this->entryOf($date, $name, $sourceRef, $sourceUrl);
        $this->holidayDate = $date->toDateTime();
        $this->name        = $entry->name;
        $this->sourceRef   = $entry->sourceRef;
        $this->sourceUrl   = $entry->sourceUrl;
    }

    /**
     * A day of the calendar; its date must be in the calendar's year.
     *
     * @throws HolidayRefusedException `holiday.day.error.name` | `holiday.day.error.year`
     */
    public static function create(string $id, HolidayCalendar $calendar, LocalDate $date, string $name, HolidayOrigin $origin = HolidayOrigin::Manual, ?string $sourceRef = null, ?string $sourceUrl = null): self
    {
        if ($date->year() !== $calendar->getYear()) {
            throw new HolidayRefusedException('holiday.day.error.year');
        }

        return new self(Uuid::assert($id), $calendar->getTenantId(), $calendar->getId(), $date, $name, $origin, $sourceRef, $sourceUrl);
    }

    /**
     * Edited by hand: a new date or name, from then on a manual day.
     *
     * @throws HolidayRefusedException `holiday.day.error.name`
     */
    public function change(LocalDate $date, string $name): void
    {
        $entry             = $this->entryOf($date, $name, null, null);
        $this->holidayDate = $date->toDateTime(); // @igor-ignore - entity state, not a service
        $this->name        = $entry->name; // @igor-ignore - entity state, not a service
        $this->origin      = HolidayOrigin::Manual; // @igor-ignore - entity state, not a service
        $this->sourceRef   = null; // @igor-ignore - entity state, not a service
        $this->sourceUrl   = null; // @igor-ignore - entity state, not a service
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

    /** Calendar id. */
    public function getCalendarId(): string
    {
        return $this->calendarId;
    }

    /** Date. */
    public function date(): LocalDate
    {
        return LocalDate::fromDateTime($this->holidayDate);
    }

    /** Public name. */
    public function getName(): string
    {
        return $this->name;
    }

    /** Origin. */
    public function getOrigin(): HolidayOrigin
    {
        return $this->origin;
    }

    /** Bulletin reference a provider cites. */
    public function getSourceRef(): ?string
    {
        return $this->sourceRef;
    }

    /** Source URL. */
    public function getSourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    /**
     * The public value object of this day.
     */
    public function entry(HolidayCalendar $calendar): HolidayEntry
    {
        return new HolidayEntry($this->date(), $this->name, $calendar->getScope(), $this->origin, $this->sourceRef, $this->sourceUrl, $this->calendarId);
    }

    /**
     * @throws HolidayRefusedException
     */
    private function entryOf(LocalDate $date, string $name, ?string $sourceRef, ?string $sourceUrl): HolidayEntry
    {
        $clean = HolidayEntry::cleanName($name);
        if ($clean === null) {
            throw new HolidayRefusedException('holiday.day.error.name');
        }

        return new HolidayEntry($date, $clean, sourceRef: $sourceRef, sourceUrl: $sourceUrl);
    }
}
