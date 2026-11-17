<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Kernel;

use Closure;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * The two host kinds of the suite (REQ-ARCH-001): a standard Symfony app (default connection, default entity manager
 * plus the private `nowo_working_time` one the Flex recipe declares) and a DDD app (a dedicated connection and entity
 * manager `ddd`, another table prefix, XML mapping).
 */
final readonly class Host
{
    public const STANDARD = 'standard';

    public const DDD = 'ddd';

    private function __construct(public string $kind)
    {
    }

    public static function standard(): self
    {
        return new self(self::STANDARD);
    }

    public static function ddd(): self
    {
        return new self(self::DDD);
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    public function bundleConfig(array $override = []): array
    {
        $base = $this->kind === self::STANDARD
            ? []
            : ['doctrine' => ['entity_manager' => 'ddd', 'table_prefix' => 'ddd_', 'mapping' => 'xml']];

        /** @var array<string, mixed> $merged */
        $merged = array_replace_recursive($base, $override);

        return $merged;
    }

    /**
     * @return Closure(ContainerConfigurator, string): void
     */
    public function configure(): Closure
    {
        $kind = $this->kind;

        return static function (ContainerConfigurator $container, string $url) use ($kind): void {
            if ($kind === self::STANDARD) {
                $container->extension('doctrine', [
                    'dbal' => ['url' => $url],
                    'orm'  => ['default_entity_manager' => 'default', 'entity_managers' => ['default' => ['connection' => 'default'], 'nowo_working_time' => ['connection' => 'default']]],
                ]);

                return;
            }
            $container->extension('doctrine', [
                'dbal' => ['default_connection' => 'default', 'connections' => ['default' => ['url' => $url], 'ddd' => ['url' => $url]]],
                'orm'  => ['default_entity_manager' => 'default', 'entity_managers' => ['default' => ['connection' => 'default'], 'ddd' => ['connection' => 'ddd']]],
            ]);
        };
    }

    public function entityManagerName(): string
    {
        return $this->kind === self::STANDARD ? 'nowo_working_time' : 'ddd';
    }
}
