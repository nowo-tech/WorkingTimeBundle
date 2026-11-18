<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Holiday\Port;

use Nowo\WorkingTimeBundle\Domain\Holiday\Model\HolidayCalendar;
use Nowo\WorkingTimeBundle\Domain\Holiday\Model\HolidayDay;
use Nowo\WorkingTimeBundle\Domain\Holiday\Model\WorkCentre;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;

/**
 * Persistence of the holiday component (implemented on the bundle's entity manager). Every lookup is scoped by the
 * tenant and re-checks it with `===` (rule LQ).
 */
interface HolidayStoreInterface
{
    /**
     * The tenant's calendars, newest year first, then by name.
     *
     * @return list<HolidayCalendar>
     */
    public function calendars(TenantRef $tenant): array;

    /** One calendar of the tenant. */
    public function calendar(TenantRef $tenant, string $id): ?HolidayCalendar;

    /** Persists a new calendar. */
    public function addCalendar(HolidayCalendar $calendar): void;

    /** Removes a calendar and its days. */
    public function removeCalendar(HolidayCalendar $calendar): void;

    /**
     * The days of the calendars, by date.
     *
     * @param non-empty-list<string> $calendarIds
     *
     * @return list<HolidayDay>
     */
    public function days(TenantRef $tenant, array $calendarIds): array;

    /**
     * The tenant's days between two dates (inclusive), of the given calendars (null: every calendar), by date.
     *
     * @param non-empty-list<string>|null $calendarIds
     *
     * @return list<HolidayDay>
     */
    public function daysBetween(TenantRef $tenant, ?array $calendarIds, LocalDate $from, LocalDate $to): array;

    /** One day of a calendar of the tenant. */
    public function day(TenantRef $tenant, string $calendarId, string $id): ?HolidayDay;

    /** Persists a new day. */
    public function addDay(HolidayDay $day): void;

    /** Removes a day. */
    public function removeDay(HolidayDay $day): void;

    /**
     * The tenant's work centres by name.
     *
     * @return list<WorkCentre>
     */
    public function centres(TenantRef $tenant): array;

    /** One work centre of the tenant. */
    public function centre(TenantRef $tenant, string $id): ?WorkCentre;

    /** Persists a new work centre. */
    public function addCentre(WorkCentre $centre): void;

    /** Removes a work centre. */
    public function removeCentre(WorkCentre $centre): void;
}
