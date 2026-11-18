<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register;

use Nowo\WorkingTimeBundle\Domain\Port\RegisterDayAnnotationProviderInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettings;
use Nowo\WorkingTimeBundle\Domain\Shared\DayAnnotation;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;

/**
 * The register days the other components mark (contract section 8.2): every tagged
 * {@see RegisterDayAnnotationProviderInterface} asked once per read with the work centre of each day. Stateless.
 */
final readonly class RegisterAnnotations
{
    /**
     * @param iterable<RegisterDayAnnotationProviderInterface> $providers
     */
    public function __construct(
        private ScheduleResolver $schedules,
        private iterable $providers = [],
    ) {
    }

    /**
     * The worker's annotations between two dates.
     *
     * @return array<string, list<DayAnnotation>> `Y-m-d` => annotations, only the annotated dates
     */
    public function between(RegisterSettings $settings, WorkerProfile $worker, LocalDate $from, LocalDate $to): array
    {
        $centreOn = null;
        $out      = [];
        foreach ($this->providers as $provider) {
            if ($centreOn === null) {
                $centreOn = [];
                foreach ($this->schedules->rangeWithCentres($settings, $worker, $from, $to) as $date => $day) {
                    $centreOn[$date] = $day['centre'];
                }
            }
            foreach ($provider->annotations(new TenantRef($settings->getTenantId()), $worker, $centreOn, $from, $to) as $date => $annotations) {
                foreach ($annotations as $annotation) {
                    $out[(string) $date][] = $annotation;
                }
            }
        }

        return $out;
    }

    /**
     * Whether the date is a full day off (a holiday, a full day of leave): no reminder, no clocking expected.
     */
    public function isDayOff(RegisterSettings $settings, WorkerProfile $worker, LocalDate $date): bool
    {
        foreach ($this->between($settings, $worker, $date, $date)[$date->value] ?? [] as $annotation) {
            if ($annotation->fullDay) {
                return true;
            }
        }

        return false;
    }
}
