<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Enum;

/**
 * Whom a dated schedule row applies to; the most specific one wins (worker, then group, then tenant).
 */
enum ScheduleScope: string
{
    case Tenant = 'tenant';
    case Group  = 'group';
    case Worker = 'worker';
}
