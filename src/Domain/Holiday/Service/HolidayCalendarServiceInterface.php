<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Holiday\Service;

use Nowo\WorkingTimeBundle\Domain\Holiday\Exception\HolidayRefusedException;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayCalendarInput;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayLocation;
use Nowo\WorkingTimeBundle\Domain\Holiday\ImportPreview;
use Nowo\WorkingTimeBundle\Domain\Holiday\ImportResult;
use Nowo\WorkingTimeBundle\Domain\Holiday\Model\HolidayCalendar;
use Nowo\WorkingTimeBundle\Domain\Holiday\Model\HolidayDay;
use Nowo\WorkingTimeBundle\Domain\Holiday\Model\WorkCentre;
use Nowo\WorkingTimeBundle\Domain\Port\HolidayProviderInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\Exception\AccessDeniedException;
use Nowo\WorkingTimeBundle\Domain\Shared\Exception\NotAvailableException;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;

/**
 * Holiday administration of the current tenant (contract sections 7 and 9.2; `holidays_manage`): calendars, their days
 * by hand, CSV / ICS imports and provider imports (preview, then confirm: the confirm asks the provider again, nothing
 * from the browser is trusted), and work centres. Every method resolves the tenant and the permission first: no tenant,
 * not a member or a row of another tenant ⇒ {@see NotAvailableException} (404); no permission ⇒
 * {@see AccessDeniedException} (403); a refused change ⇒ {@see HolidayRefusedException}. Each write is audited
 * (`working_time.holidays_changed`, ids and counts only).
 */
interface HolidayCalendarServiceInterface
{
    /**
     * The tenant's calendars (newest year first, then by name).
     *
     * @return list<HolidayCalendar>
     */
    public function calendars(): array;

    /** One calendar of the tenant. */
    public function calendar(string $id): HolidayCalendar;

    /** Creates (`$id` null) or changes a calendar. */
    public function saveCalendar(?string $id, HolidayCalendarInput $input): HolidayCalendar;

    /** Removes a calendar, its days and its place in the work centres. */
    public function removeCalendar(string $id): void;

    /**
     * The calendar's days by date.
     *
     * @return list<HolidayDay>
     */
    public function days(string $calendarId): array;

    /** Adds a day by hand. */
    public function addDay(string $calendarId, LocalDate $date, string $name): HolidayDay;

    /** Edits a day by hand (it becomes a manual day). */
    public function changeDay(string $calendarId, string $dayId, LocalDate $date, string $name): void;

    /** Removes a day. */
    public function removeDay(string $calendarId, string $dayId): void;

    /** The preview of a CSV / ICS file against the calendar (nothing is saved). */
    public function previewFile(string $calendarId, string $content, string $filename): ImportPreview;

    /**
     * Adds the `new` rows of a file preview, checked again against the calendar.
     *
     * @param list<array{date: string, name: string}> $rows
     */
    public function confirmFile(string $calendarId, array $rows): ImportResult;

    /**
     * The enabled providers.
     *
     * @return list<HolidayProviderInterface>
     */
    public function providers(): array;

    /** The preview of a provider's holidays against the calendar (one call out; nothing is saved). */
    public function previewProvider(string $calendarId, string $providerKey, HolidayLocation $location, string $language): ImportPreview;

    /** Asks the provider again and syncs the calendar: its earlier days of that provider are replaced. */
    public function confirmProvider(string $calendarId, string $providerKey, HolidayLocation $location, string $language): ImportResult;

    /**
     * The tenant's work centres by name.
     *
     * @return list<WorkCentre>
     */
    public function centres(): array;

    /** One work centre of the tenant. */
    public function centre(string $id): WorkCentre;

    /**
     * Creates (`$id` null) or changes a work centre; only the tenant's calendars are kept; a default centre clears the
     * flag on the others.
     *
     * @param list<string> $calendarIds
     */
    public function saveCentre(?string $id, string $name, array $calendarIds, bool $default): WorkCentre;

    /** Removes a work centre (schedule rows that named it fall back to the default centre). */
    public function removeCentre(string $id): void;
}
