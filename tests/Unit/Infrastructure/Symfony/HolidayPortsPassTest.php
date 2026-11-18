<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Infrastructure\Symfony;

use LogicException;
use Nowo\OutboundUrlGuardBundle\Guard\RuntimeOutboundUrlGuard;
use Nowo\WorkingTimeBundle\Application\Register\Port\ExportDispatcherInterface;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\DependencyInjection\Compiler\WorkingTimePortsPass;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Holiday\GuardedJsonClient;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Messenger\GenerateRegisterExportHandler;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Routing\UiRouteLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

use function array_keys;
use function array_values;
use function preg_grep;

/**
 * The holiday component's outbound guard and cache pool (contract section 10), and the route loader's three parts.
 */
final class HolidayPortsPassTest extends TestCase
{
    public function testWithoutTheGuardBundleTheBundleBuildsTheSamePolicy(): void
    {
        $container = $this->container(RuntimeOutboundUrlGuard::class, 'cache.app');
        (new WorkingTimePortsPass())->process($container);

        self::assertSame(RuntimeOutboundUrlGuard::class, $container->getDefinition('nowo_working_time.outbound_guard')->getClass());
        self::assertSame('cache.app', (string) $container->getAlias('nowo_working_time.holiday_cache'));
    }

    public function testTheHostsGuardIsUsed(): void
    {
        $container = $this->container('app.guard', 'cache.app');
        $container->setDefinition('app.guard', new Definition('stdClass'));
        (new WorkingTimePortsPass())->process($container);

        self::assertSame('app.guard', (string) $container->getAlias('nowo_working_time.outbound_guard'));
    }

    public function testAnUnknownGuardOrPoolFailsTheBuildWithTheFix(): void
    {
        try {
            (new WorkingTimePortsPass())->process($this->container('app.missing', 'cache.app'));
            self::fail('guard');
        } catch (LogicException $e) {
            self::assertStringContainsString('holidays.outbound_guard "app.missing"', $e->getMessage());
        }
        $this->expectExceptionMessage('holidays.cache_pool "cache.none"');
        (new WorkingTimePortsPass())->process($this->container(RuntimeOutboundUrlGuard::class, 'cache.none'));
    }

    public function testNothingIsWiredWhileTheHolidayComponentIsOff(): void
    {
        $container = $this->container(null, null, false);
        (new WorkingTimePortsPass())->process($container);

        self::assertFalse($container->has('nowo_working_time.outbound_guard'));
    }

    public function testTheRouteLoaderServesTheEnabledParts(): void
    {
        $all = (new UiRouteLoader(true, '/working-time', '/working-time/settings', true, true, true, '/', '/leave'))->load('.');
        self::assertNotNull($all->get('nowo_working_time_today'));
        self::assertSame('/', $all->get('nowo_working_time_holidays')?->getPath());
        self::assertSame('/working-time/settings/leave', $all->get('nowo_working_time_leave_settings')?->getPath());
        $holidaysOnly = (new UiRouteLoader(true, '/working-time', '/working-time/settings', false, true, false, '/holidays', '/'))->load('.');
        self::assertSame([], array_values(preg_grep('/^nowo_working_time_(today|leave)/', array_keys($holidaysOnly->all())) ?: []));
        self::assertCount(14, $holidaysOnly->all());
        $leaveAtRoot = (new UiRouteLoader(true, '/working-time', '/working-time/settings', false, false, true, '/holidays', '/'))->load('.');
        self::assertSame('/', $leaveAtRoot->get('nowo_working_time_leave')?->getPath());
    }

    private function container(?string $guard, ?string $pool, bool $holidays = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        foreach ([
            'doctrine.mapping'             => 'xml',
            'tenant_resolver'              => null,
            'permission_checker'           => null,
            'worker_directory'             => null,
            'brand_provider'               => null,
            'clock'                        => null,
            'notifier'                     => 'null',
            'notifier_from'                => 'no-reply@example.test',
            'audit_sink'                   => 'auto',
            'register.export.pdf.renderer' => 'app.pdf',
            'register.export.formats'      => ['csv'],
            'register.export.async'        => 'sync',
            'register.task_timer_link'     => 'off',
            'holidays.outbound_guard'      => $guard,
            'holidays.cache_pool'          => $pool,
        ] as $name => $value) {
            $container->setParameter('nowo_working_time.' . $name, $value);
        }
        $container->setDefinition('cache.app', new Definition('stdClass'));
        $container->setDefinition(GenerateRegisterExportHandler::class, new Definition(GenerateRegisterExportHandler::class));
        $container->setAlias(ExportDispatcherInterface::class, 'nowo_working_time.export_dispatcher.sync');
        if ($holidays) {
            $container->setDefinition(GuardedJsonClient::class, new Definition(GuardedJsonClient::class));
        }

        return $container;
    }
}
