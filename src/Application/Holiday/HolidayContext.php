<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Holiday;

use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;

/**
 * Who administers holidays where in one call, after the gate passed. Built per call; never kept.
 */
final readonly class HolidayContext
{
    /**
     * Wires the values.
     */
    public function __construct(
        public TenantRef $tenant,
        public ActorRef $actor,
        public string $locale = 'en',
    ) {
    }
}
