<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Kernel;

use Closure;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\TransactionDoctrine\Symfony\NowoTransactionDoctrineBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Nowo\WorkingTimeBundle\NowoWorkingTimeBundle;
use Nowo\WorkingTimeBundle\Tests\Fixtures\TestServices;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;
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
        private readonly bool $full = true,
        private readonly bool $transactionBundle = true,
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
        yield new TwigBundle();
        if ($this->full) {
            yield new SecurityBundle();
            yield new TwigExtraBundle();
            yield new NowoUiKitBundle();
            yield new NowoFormKitBundle();
        }
        if ($this->transactionBundle) {
            yield new NowoTransactionDoctrineBundle();
        }
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

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'nowo_working_time_ui');
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
            'router'                => ['utf8' => true],
            'translator'            => ['fallbacks' => ['en']],
        ]);
        $container->extension('twig', ['strict_variables' => true]);
        if ($this->full) {
            $container->extension('framework', [
                'csrf_protection' => true,
                'form'            => ['enabled' => true],
                'session'         => ['storage_factory_id' => 'session.storage.factory.mock_file'],
                'assets'          => ['enabled' => true],
            ]);
            $container->extension('security', [
                'providers' => ['memory' => ['memory' => ['users' => TestServices::USERS]]],
                'firewalls' => ['main' => ['lazy' => true, 'provider' => 'memory', 'http_basic' => null]],
            ]);
        }
        ($this->configure)($container, self::defaultDatabaseUrl());
        $container->extension('nowo_working_time', $this->bundleConfig);
        $container->services()->set('logger', NullLogger::class);
        TestServices::register($container);
    }
}
