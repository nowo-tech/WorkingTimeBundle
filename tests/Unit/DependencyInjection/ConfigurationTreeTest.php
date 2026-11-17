<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\DependencyInjection;

use Generator;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\DependencyInjection\ConfigurationTree;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

use function explode;

final class ConfigurationTreeTest extends TestCase
{
    public function testTheDefaultsAreTheContractDefaults(): void
    {
        $config = $this->process([]);

        self::assertTrue($this->at($config, 'register')['enabled']);
        self::assertTrue($this->at($config, 'holidays')['enabled']);
        self::assertFalse($this->at($config, 'leave')['enabled']);
        self::assertFalse($this->at($config, 'ui')['enabled']);
        self::assertSame('nowo_working_time', $this->at($config, 'doctrine')['entity_manager']);
        self::assertSame('nowo_', $this->at($config, 'doctrine')['table_prefix']);
        self::assertSame('xml', $this->at($config, 'doctrine')['mapping']);
        self::assertSame('auto', $config['notifier']);
        self::assertNull($config['tenant_resolver']);
        self::assertSame(404, $this->at($config, 'permissions')['not_available_status']);
        self::assertSame('warn', $this->at($config, 'integrity')['triggers']);
        self::assertSame(4, $this->at($config, 'register')['retention_years']);
        self::assertSame('Europe/Madrid', $this->at($config, 'register.schedule')['default_timezone']);
        self::assertSame(['pdf', 'csv'], $this->at($config, 'register.export')['formats']);
        self::assertSame('https://festivos.io', $this->at($config, 'holidays.providers.festivos_io')['base_uri']);
        self::assertSame([30, 7], $this->at($config, 'leave.planning_reminders')['days_before']);
        self::assertSame('/working-time/v1/ical', $this->at($config, 'api')['ical_path']);
    }

    public function testEveryPermissionListIsEmptyByDefaultSoEverythingIsDenied(): void
    {
        $roles = $this->at($this->process([]), 'permissions.roles');

        self::assertSame(ConfigurationTree::PERMISSIONS, array_keys($roles));
        foreach ($roles as $list) {
            self::assertSame([], $list);
        }
    }

    public function testTheLeaveLockDefaultsToTheDatabaseRow(): void
    {
        $lock = $this->at($this->process([]), 'leave.lock');

        self::assertSame('db_row', $lock['store']);
        self::assertNull($lock['lock_factory']);
        self::assertSame(5, $lock['wait_seconds']);
        self::assertSame(30, $lock['ttl_seconds']);
    }

    public function testThePdfRendererIsClosedByDefault(): void
    {
        $pdf = $this->at($this->process([]), 'register.export.pdf');

        self::assertSame('dompdf', $pdf['renderer']);
        self::assertFalse($pdf['remote_resources']);
        self::assertFalse($pdf['javascript']);
        self::assertFalse($pdf['php']);
        self::assertNull($pdf['chroot']);
    }

    public function testTheLeavePartCanBeEnabledWithTheOthersOn(): void
    {
        self::assertTrue($this->at($this->process(['leave' => ['enabled' => true]]), 'leave')['enabled']);
    }

    public function testALockFactoryStoreWithItsService(): void
    {
        $lock = $this->at($this->process(['leave' => ['lock' => ['store' => 'lock_factory', 'lock_factory' => 'lock.factory']]]), 'leave.lock');

        self::assertSame('lock_factory', $lock['store']);
        self::assertSame('lock.factory', $lock['lock_factory']);
    }

    public function testAttachmentsWithAStoreAndADbUserPurgeGuardAreAccepted(): void
    {
        $config = $this->process([
            'leave'     => ['attachments' => ['enabled' => true, 'store' => 'app.store']],
            'integrity' => ['purge_guard' => 'db_user', 'purge_db_user' => 'purger', 'purge_connection' => 'purge'],
        ]);

        self::assertSame('app.store', $this->at($config, 'leave.attachments')['store']);
        self::assertSame('purger', $this->at($config, 'integrity')['purge_db_user']);
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfigurationsAreRefused(array $config, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->process($config);
    }

    /**
     * @return Generator<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigurations(): Generator
    {
        yield 'unknown key' => [['nope' => 1], 'Unrecognized option "nope"'];
        yield 'leave without register' => [['leave' => ['enabled' => true], 'register' => ['enabled' => false]], 'requires nowo_working_time.register.enabled'];
        yield 'leave without holidays' => [['leave' => ['enabled' => true], 'holidays' => ['enabled' => false]], 'requires nowo_working_time.register.enabled'];
        yield 'lock factory without service' => [['leave' => ['lock' => ['store' => 'lock_factory']]], 'leave.lock.lock_factory'];
        yield 'unknown lock store' => [['leave' => ['lock' => ['store' => 'database']]], 'leave.lock.store'];
        yield 'attachments without store' => [['leave' => ['attachments' => ['enabled' => true]]], 'leave.attachments.store'];
        yield 'retention below four years' => [['register' => ['retention_years' => 3]], 'retention_years'];
        yield 'db_user guard without user' => [['integrity' => ['purge_guard' => 'db_user']], 'purge_db_user'];
        yield 'db_user guard without its connection' => [['integrity' => ['purge_guard' => 'db_user', 'purge_db_user' => 'purger']], 'purge_connection'];
        yield 'pdf remote resources' => [['register' => ['export' => ['pdf' => ['remote_resources' => true]]]], 'PDF renderer is closed'];
        yield 'pdf javascript' => [['register' => ['export' => ['pdf' => ['javascript' => true]]]], 'PDF renderer is closed'];
        yield 'pdf php' => [['register' => ['export' => ['pdf' => ['php' => true]]]], 'PDF renderer is closed'];
        yield 'not available status' => [['permissions' => ['not_available_status' => 403]], 'fixed to 404'];
        yield 'http provider' => [['holidays' => ['providers' => ['festivos_io' => ['base_uri' => 'http://festivos.io']]]], 'https URL'];
        yield 'http openholidays' => [['holidays' => ['providers' => ['openholidays' => ['base_uri' => 'ftp://x']]]], 'https URL'];
        yield 'ui path without slash' => [['ui' => ['leave_path' => 'leave']], 'must start with "/"'];
        yield 'ui paths not distinct' => [['ui' => ['leave_path' => '/holidays']], 'must be distinct'];
        yield 'grace out of range' => [['register' => ['missing_clock_out_grace_minutes' => 10]], 'missing_clock_out_grace_minutes'];
        yield 'wrong ip prefix' => [['register' => ['ipv4_prefix' => 16]], 'ipv4_prefix'];
    }

    /**
     * @param array<mixed, mixed> $config
     *
     * @return array<mixed, mixed>
     */
    private function at(array $config, string $path): array
    {
        $node = $config;
        foreach (explode('.', $path) as $key) {
            self::assertArrayHasKey($key, $node);
            $node = $node[$key];
            self::assertIsArray($node);
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        $builder = new TreeBuilder('nowo_working_time');
        $root    = $builder->getRootNode();
        self::assertInstanceOf(ArrayNodeDefinition::class, $root);
        ConfigurationTree::define($root);

        /** @var array<string, mixed> $processed */
        $processed = (new Processor())->process($builder->buildTree(), [$config]);

        return $processed;
    }
}
