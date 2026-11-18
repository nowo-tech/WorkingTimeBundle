<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Holiday;

use Nowo\WorkingTimeBundle\Application\Holiday\Port\HolidayStoreInterface;
use Nowo\WorkingTimeBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayCalendarId;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayEntry;
use Nowo\WorkingTimeBundle\Domain\Holiday\Model\HolidayCalendar;
use Nowo\WorkingTimeBundle\Domain\Holiday\Model\WorkCentre;
use Nowo\WorkingTimeBundle\Domain\Holiday\Service\HolidayCalendarReaderInterface;
use Nowo\WorkingTimeBundle\Domain\Holiday\WorkCentreId;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;

use function is_array;
use function usort;

/**
 * The holiday component's reader (contract section 7): the stand-alone entry point for agendas, and what the register
 * and leave ask. Each call reads the tenant's rows inside the call (worker mode: nothing kept) and at most three
 * queries (centres, calendars, days).
 */
final readonly class HolidayCalendarReader implements HolidayCalendarReaderInterface
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private HolidayStoreInterface $store,
        private TransactionRunnerInterface $runner,
    ) {
    }

    /**
     * See the implemented interface.
     */
    public function holidaysBetween(TenantRef $tenant, WorkCentreId|array|null $where, LocalDate $from, LocalDate $to): array
    {
        if ($to->compare($from) < 0) {
            return [];
        }

        return $this->runner->read(function () use ($tenant, $where, $from, $to): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $calendarIds = $this->calendarIds($tenant, $where);
            if ($calendarIds === []) {
                return [];
            }
            $calendars = [];
            foreach ($this->store->calendars($tenant) as $calendar) {
                $calendars[$calendar->getId()] = $calendar;
            }
            $days = $this->store->daysBetween($tenant, $calendarIds, $from, $to);
            usort($days, static fn ($a, $b): int => [$a->date()->value, self::nameOf($calendars, $a->getCalendarId())] <=> [$b->date()->value, self::nameOf($calendars, $b->getCalendarId())]);
            $entries = [];
            foreach ($days as $day) {
                $calendar = $calendars[$day->getCalendarId()] ?? null;
                if ($calendar instanceof HolidayCalendar && !isset($entries[$day->date()->value])) {
                    $entries[$day->date()->value] = $day->entry($calendar);
                }
            }

            /** @var list<HolidayEntry> $list */
            $list = [];
            foreach ($entries as $entry) {
                $list[] = $entry;
            }

            return $list;
        });
    }

    /**
     * See the implemented interface.
     */
    public function isHoliday(TenantRef $tenant, WorkCentreId|array|null $where, LocalDate $date): bool
    {
        return $this->holidaysBetween($tenant, $where, $date, $date) !== [];
    }

    /**
     * See the implemented interface.
     */
    public function holidayMap(TenantRef $tenant, array $centreOn, LocalDate $from, LocalDate $to): array
    {
        if ($to->compare($from) < 0) {
            return [];
        }

        return $this->runner->read(function () use ($tenant, $centreOn, $from, $to): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $days = $this->store->daysBetween($tenant, null, $from, $to);
            if ($days === []) {
                return [];
            }
            $centres = [];
            $default = null;
            foreach ($this->store->centres($tenant) as $centre) {
                $centres[$centre->getId()] = $centre;
                $default ??= $centre->isDefault() ? $centre : null;
            }
            $map = [];
            foreach ($days as $day) {
                $date     = $day->date()->value;
                $centreId = $centreOn[$date] ?? null;
                $centre   = $centreId === null ? $default : ($centres[$centreId] ?? $default);
                // Nobody's centre (none named, no default): every calendar of the tenant applies.
                if (!$centre instanceof WorkCentre || $centre->covers($day->getCalendarId())) {
                    $map[$date] ??= $day->getName();
                }
            }

            return $map;
        });
    }

    /**
     * See the implemented interface.
     */
    public function workCentres(TenantRef $tenant): array
    {
        return $this->runner->read(fn (): array => $this->store->centres($tenant)); // @igor-ignore - the runner calls this closure at once and keeps nothing
    }

    /**
     * The calendar ids that apply ([] = none: an unknown centre answers no holidays).
     *
     * @param list<HolidayCalendarId>|WorkCentreId|null $where
     *
     * @return list<string>|null null = every calendar of the tenant
     */
    private function calendarIds(TenantRef $tenant, WorkCentreId|array|null $where): ?array
    {
        if (is_array($where)) {
            $ids = [];
            foreach ($where as $id) {
                $ids[] = $id->value;
            }

            return $ids;
        }
        if ($where instanceof WorkCentreId) {
            return $this->store->centre($tenant, $where->value)?->calendarIds() ?? [];
        }
        foreach ($this->store->centres($tenant) as $centre) {
            if ($centre->isDefault()) {
                return $centre->calendarIds();
            }
        }

        return null;
    }

    /**
     * @param array<string, HolidayCalendar> $calendars
     */
    private static function nameOf(array $calendars, string $id): string
    {
        return isset($calendars[$id]) ? $calendars[$id]->getName() . "\0" . $id : "\u{10FFFF}" . $id;
    }
}
