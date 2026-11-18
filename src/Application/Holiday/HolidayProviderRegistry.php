<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Holiday;

use Nowo\WorkingTimeBundle\Domain\Holiday\Exception\HolidayRefusedException;
use Nowo\WorkingTimeBundle\Domain\Port\HolidayProviderInterface;

use function in_array;

/**
 * The enabled holiday providers (built-in and the host's, tagged `nowo_working_time.holiday_provider`), by key.
 * Stateless: the providers come from the container once; nothing per call is kept.
 */
final readonly class HolidayProviderRegistry
{
    /**
     * @param iterable<HolidayProviderInterface> $providers
     * @param list<string> $disabled keys switched off in the configuration
     */
    public function __construct(private iterable $providers = [], private array $disabled = [])
    {
    }

    /**
     * The enabled providers, in tag order.
     *
     * @return list<HolidayProviderInterface>
     */
    public function all(): array
    {
        $list = [];
        foreach ($this->providers as $provider) {
            if (!in_array($provider->key(), $this->disabled, true)) {
                $list[] = $provider;
            }
        }

        return $list;
    }

    /**
     * An enabled provider.
     *
     * @throws HolidayRefusedException `holiday.provider.error.unknown`
     */
    public function get(string $key): HolidayProviderInterface
    {
        foreach ($this->all() as $provider) {
            if ($provider->key() === $key) {
                return $provider;
            }
        }

        throw new HolidayRefusedException('holiday.provider.error.unknown');
    }
}
