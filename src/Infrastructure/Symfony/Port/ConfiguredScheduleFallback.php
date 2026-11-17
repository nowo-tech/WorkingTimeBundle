<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port;

use Nowo\WorkingTimeBundle\Domain\Port\ScheduleFallbackProviderInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Model\ScheduleDefinition;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;

/**
 * Default schedule fallback: `register.schedule.default` (checked when the container is built), else none.
 */
final readonly class ConfiguredScheduleFallback implements ScheduleFallbackProviderInterface
{
    private ?ScheduleDefinition $definition;

    /**
     * @param array<mixed>|null $default `register.schedule.default`
     */
    public function __construct(?array $default = null, string $defaultTimezone = 'UTC')
    {
        $this->definition = $default === null ? null : ScheduleDefinition::fromArray($default, $defaultTimezone);
    }

    /**
     * The configured schedule, the same for every tenant and date.
     */
    public function fallback(TenantRef $tenant, LocalDate $date): ?ScheduleDefinition
    {
        return $this->definition;
    }
}
