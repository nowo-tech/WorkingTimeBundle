<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Kernel;

use Closure;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\WorkingTimeBundle\NowoWorkingTimeBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;

use function dirname;
use function getenv;
use function is_string;
use function sys_get_temp_dir;

/**
 * Kernel of the integration tests: FrameworkBundle, DoctrineBundle (ORM) and the bundle.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $bundleConfig `nowo_working_time` configuration
     * @param Closure(ContainerConfigurator, string): void $configure host configuration (Doctrine)
     */
    public function __construct(
        private readonly string $id,
        private readonly array $bundleConfig,
        private readonly Closure $configure,
    ) {
        parent::__construct('test', false);
    }

    /**
     * `NOWO_TEST_DATABASE_URL` (CI matrix: MySQL, MariaDB, PostgreSQL) or a SQLite file.
     */
    public static function defaultDatabaseUrl(): string
    {
        $url = getenv('NOWO_TEST_DATABASE_URL');
        if (is_string($url) && $url !== '') {
            return $url;
        }
        (new Filesystem())->mkdir(self::baseDir());

        return 'sqlite:///' . self::baseDir() . '/functional.sqlite';
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new NowoWorkingTimeBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 3);
    }

    public function getCacheDir(): string
    {
        return self::baseDir() . '/' . $this->id . '/cache';
    }

    public function getLogDir(): string
    {
        return self::baseDir() . '/' . $this->id . '/log';
    }

    public static function baseDir(): string
    {
        return sys_get_temp_dir() . '/nowo-working-time-tests';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test'                  => true,
            'secret'                => 'S0-' . $this->id,
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'php_errors'            => ['log' => true],
            'cache'                 => ['app' => 'cache.adapter.array'],
        ]);
        ($this->configure)($container, self::defaultDatabaseUrl());
        $container->extension('nowo_working_time', $this->bundleConfig);
    }
}
