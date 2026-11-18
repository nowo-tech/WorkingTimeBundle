<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Holiday\Service;

use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayCalendarId;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayEntry;
use Nowo\WorkingTimeBundle\Domain\Holiday\Model\WorkCentre;
use Nowo\WorkingTimeBundle\Domain\Holiday\WorkCentreId;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;

/**
 * The stand-alone entry point of the holiday component (contract section 7): agendas, CRMs and the register / leave
 * ask it which days are public holidays, without the register or leave being enabled. Reads only the given tenant's
 * rows; no permission check (the host calls it for its own tenant). Two queries per call.
 *
 * Which calendars apply: those of the given work centre; those listed; with `null`, the tenant's default centre's,
 * else every calendar of the tenant (a tenant without centres).
 */
interface HolidayCalendarReaderInterface
{
    /**
     * The holidays between two dates (inclusive), by date; a date present in several calendars comes once (the first
     * calendar by name wins).
     *
     * @param list<HolidayCalendarId>|WorkCentreId|null $where
     *
     * @return list<HolidayEntry>
     */
    public function holidaysBetween(TenantRef $tenant, WorkCentreId|array|null $where, LocalDate $from, LocalDate $to): array;

    /**
     * Whether the date is a holiday for the centre / calendars.
     *
     * @param list<HolidayCalendarId>|WorkCentreId|null $where
     */
    public function isHoliday(TenantRef $tenant, WorkCentreId|array|null $where, LocalDate $date): bool;

    /**
     * The holiday of each date for someone whose work centre may change from day to day (the register's dated
     * schedule rows): `$centreOn` maps each date of the range to the centre that applies that day (null: the
     * tenant's default, else every calendar).
     *
     * @param array<string, string|null> $centreOn `Y-m-d` => work centre id or null; every date of the range
     *
     * @return array<string, string> `Y-m-d` => holiday name, only the holidays
     */
    public function holidayMap(TenantRef $tenant, array $centreOn, LocalDate $from, LocalDate $to): array;

    /**
     * The tenant's work centres, by name.
     *
     * @return list<WorkCentre>
     */
    public function workCentres(TenantRef $tenant): array;
}
