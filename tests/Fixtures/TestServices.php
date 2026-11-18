<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Fixtures;

use Nowo\OutboundUrlGuardBundle\Guard\ConfiguredAllowPrivateResolver;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuardFactory;
use Nowo\OutboundUrlGuardBundle\Guard\RuntimeOutboundUrlGuard;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\MockHttpClient;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Test host services: the context, the ports over it, the recorder and a mock clock (2026-11-18 10:00 UTC).
 */
final class TestServices
{
    /** Users of the http_basic firewall (not used to grant anything: the context decides). */
    public const USERS = ['ana' => ['password' => 'x', 'roles' => ['ROLE_USER']]];

    /** Bundle configuration that points the ports at the test services. */
    public const PORTS = [
        'tenant_resolver'    => 'test.tenants',
        'permission_checker' => 'test.permissions',
        'worker_directory'   => 'test.directory',
        'clock'              => 'test.clock',
        'notifier'           => 'test.recorder',
        'audit_sink'         => 'test.recorder',
        'register'           => ['task_timer_link' => 'test.recorder'],
        'holidays'           => ['http_client' => 'test.http', 'outbound_guard' => 'test.guard', 'cache_pool' => 'test.holiday_cache'],
    ];

    /**
     * Registers the services (public: tests fetch them).
     */
    public static function register(ContainerConfigurator $container): void
    {
        $s = $container->services();
        $s->set('test.context', TestContext::class)->public();
        $s->set('test.tenants', TestTenants::class)->args([service('test.context')])->public();
        $s->set('test.permissions', TestPermissions::class)->args([service('test.context')])->public();
        $s->set('test.directory', TestDirectory::class)->args([service('test.context')])->public();
        $s->set('test.recorder', TestRecorder::class)->public();
        $s->set('test.holiday_api', RecordedHolidayApi::class)->public();
        $s->set('test.http', MockHttpClient::class)->factory([RecordedHolidayApi::class, 'client'])->args([service('test.holiday_api')])->public();
        // The guard's policy without DNS: a test never resolves a name either.
        $s->set('test.guard', RuntimeOutboundUrlGuard::class)->args([inline_service(OutboundUrlGuardFactory::class)->args([inline_service(ConfiguredAllowPrivateResolver::class)->args([false]), false])])->public();
        // A shared pool that survives the test kernel's per-request service reset (like Redis in production).
        $s->set('test.holiday_cache', ArrayAdapter::class)->public();
        $s->set('test.clock', MockClock::class)->args(['2026-11-18 10:00:00', 'UTC'])->public();
        $s->set('test.race', RaceInjector::class)->args([service('test.context')])->tag('doctrine.event_listener', ['event' => 'prePersist'])->tag('doctrine.event_listener', ['event' => 'preUpdate']);
    }
}
