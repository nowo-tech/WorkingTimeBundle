<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\DependencyInjection;

use function array_is_list;
use function is_array;

/**
 * Turns the validated configuration into flat container parameters: `nowo_working_time.register.enabled`, and so on.
 * Associative arrays are walked, lists and scalars are published as they are.
 */
final class ParameterFlattener
{
    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function flatten(string $prefix, array $config): array
    {
        $parameters = [];
        foreach ($config as $key => $value) {
            $name = $prefix . '.' . $key;
            if (is_array($value) && $value !== [] && !array_is_list($value)) {
                /** @var array<string, mixed> $value */
                $parameters += self::flatten($name, $value);

                continue;
            }
            $parameters[$name] = $value;
        }

        return $parameters;
    }
}
