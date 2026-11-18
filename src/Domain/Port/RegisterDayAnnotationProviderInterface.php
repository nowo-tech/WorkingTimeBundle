<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Port;

use Nowo\WorkingTimeBundle\Domain\Shared\DayAnnotation;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;

/**
 * Marks register days without the register importing the component that knows them (contract sections 4 and 8.2),
 * tagged `nowo_working_time.day_annotation_provider`: the holidays (S2, built in when the holiday component is on),
 * leave (S3). A host may add its own (a company closing day). Called per read; keeps nothing.
 */
interface RegisterDayAnnotationProviderInterface
{
    /**
     * The worker's annotations between two dates (inclusive).
     *
     * @param array<string, string|null> $centreOn `Y-m-d` => the work centre of that day's schedule row (every date of
     *                                             the range; null without one)
     *
     * @return array<string, list<DayAnnotation>> `Y-m-d` => annotations, only the annotated dates
     */
    public function annotations(TenantRef $tenant, WorkerProfile $worker, array $centreOn, LocalDate $from, LocalDate $to): array;
}
