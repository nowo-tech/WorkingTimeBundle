<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Port;

use Nowo\WorkingTimeBundle\Domain\Register\Model\ScheduleDefinition;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;

/**
 * The schedule used when no dated schedule row matches a worker's day (contract F5, section 8.2): Beacon answers with
 * its organization working hours, then the instance default. Default: ConfiguredScheduleFallback
 * (`register.schedule.default`).
 */
interface ScheduleFallbackProviderInterface
{
    /**
     * The fallback schedule of the tenant on the date; null = no schedule (time zone of the configuration).
     */
    public function fallback(TenantRef $tenant, LocalDate $date): ?ScheduleDefinition;
}
