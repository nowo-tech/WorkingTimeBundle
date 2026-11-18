<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register;

use Nowo\WorkingTimeBundle\Domain\Holiday\Service\HolidayCalendarReaderInterface;
use Nowo\WorkingTimeBundle\Domain\Port\RegisterDayAnnotationProviderInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\DayAnnotation;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;

/**
 * The register's holiday days (contract section 8.2): a public holiday of the worker's calendars (the work centre of
 * each day's schedule row, else the tenant's default centre, else every calendar) is a full day off: no clock
 * reminder, the month view names it. The holiday component knows nothing of the register; this adapter lives on the
 * register side.
 */
final readonly class HolidayDayAnnotations implements RegisterDayAnnotationProviderInterface
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private HolidayCalendarReaderInterface $holidays)
    {
    }

    /**
     * See the implemented interface.
     */
    public function annotations(TenantRef $tenant, WorkerProfile $worker, array $centreOn, LocalDate $from, LocalDate $to): array
    {
        $out = [];
        foreach ($this->holidays->holidayMap($tenant, $centreOn, $from, $to) as $date => $name) {
            $out[(string) $date] = [new DayAnnotation(DayAnnotation::HOLIDAY, $name)];
        }

        return $out;
    }
}
