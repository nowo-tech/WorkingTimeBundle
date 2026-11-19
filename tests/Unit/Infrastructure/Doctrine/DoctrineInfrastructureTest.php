<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Infrastructure\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitInterface;
use Nowo\WorkingTimeBundle\Application\Register\Port\PurgeRefusedException;
use Nowo\WorkingTimeBundle\Application\Shared\DuplicateRowException;
use Nowo\WorkingTimeBundle\Application\Shared\StaleRowException;
use Nowo\WorkingTimeBundle\Domain\Port\IdGeneratorInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Model\Workday;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Dbal\DbalRegisterPurgeStore;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Dbal\PurgeFlagResetDriver;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Dbal\PurgeFlagResetMiddleware;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\DoctrineTransactionRunner;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\RegisterMetadataListener;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Trigger\DoctrineTriggerInspector;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Trigger\RegisterTriggerInstaller;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Throwable;

use function str_starts_with;

/**
 * Doctrine infrastructure branches the SQLite suite cannot reach: the MySQL / MariaDB collations, the purge guard on
 * MySQL and PostgreSQL, the dedicated purge connection, the PostgreSQL trigger functions, the transaction runner's
 * error mapping and the purge-flag driver.
 */
final class DoctrineInfrastructureTest extends TestCase
{
    public function testTheMetadataListenerPrefixesAndCollatesTheBundlesEntitiesOnly(): void
    {
        $metadata = new ClassMetadata(Workday::class);
        $metadata->setPrimaryTable(['name' => 'working_time_workday', 'indexes' => ['wt_i' => ['columns' => ['worker_id']]], 'uniqueConstraints' => ['wt_u' => ['columns' => ['id']], 0 => ['columns' => ['x']]]]);
        $metadata->mapField(['fieldName' => 'id', 'columnName' => 'id', 'type' => 'string', 'length' => 36]);
        $metadata->mapField(['fieldName' => 'workerName', 'columnName' => 'worker_name', 'type' => 'string', 'length' => 180]);
        (new RegisterMetadataListener('app_'))->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $this->manager(new MariaDBPlatform())));

        self::assertSame('app_working_time_workday', $metadata->getTableName());
        self::assertArrayHasKey('app_wt_i', $metadata->table['indexes'] ?? []);
        self::assertArrayHasKey(0, $metadata->table['uniqueConstraints'] ?? []);
        self::assertSame('utf8mb4_nopad_bin', $metadata->fieldMappings['id']->options['collation'] ?? null);
        self::assertArrayNotHasKey('collation', $metadata->fieldMappings['workerName']->options ?? []);

        $other = new ClassMetadata(stdClass::class);
        $other->setPrimaryTable(['name' => 'host_table']);
        (new RegisterMetadataListener('app_'))->loadClassMetadata(new LoadClassMetadataEventArgs($other, $this->manager(new MySQL80Platform())));
        self::assertSame('host_table', $other->getTableName());

        $broken = $this->createStub(EntityManagerInterface::class);
        $broken->method('getConnection')->willThrowException(new RuntimeException('down'));
        $fresh = new ClassMetadata(Workday::class);
        $fresh->setPrimaryTable(['name' => 'working_time_workday']);
        $this->expectException(LogicException::class);
        (new RegisterMetadataListener())->loadClassMetadata(new LoadClassMetadataEventArgs($fresh, $broken));
    }

    public function testThePurgeGuardIsSetAndClearedOnMysqlAndScopedOnPostgresql(): void
    {
        foreach ([[new MySQL80Platform(), ['SET @nowo_working_time_purge = 1', 'SET @nowo_working_time_purge = NULL']], [new PostgreSQLPlatform(), ["SET LOCAL nowo_working_time.purge = '1'"]]] as [$platform, $guard]) {
            $statements = [];
            $connection = $this->connection($platform, $statements, [['tenant_id' => 't', 'worker_id' => 'w']], [['seq' => 3, 'hash' => 'h']]);
            $store      = new DbalRegisterPurgeStore($this->runner($connection), $this->ids());
            $totals     = $store->deleteBatch(['w1'], 'run', new DateTimeImmutable());
            self::assertSame(1, $totals['anchors']);
            foreach ($guard as $sql) {
                self::assertContains($sql, $statements);
            }
        }
    }

    public function testADedicatedPurgeConnectionAndAChainWithoutDeletableLinks(): void
    {
        $statements = [];
        $purge      = $this->connection(new MySQL80Platform(), $statements, [['tenant_id' => 't', 'worker_id' => 'w']], [false]);
        $purge->method('transactional')->willReturnCallback(static fn (callable $work): mixed => $work($purge));
        $store  = new DbalRegisterPurgeStore($this->runner($purge), $this->ids(), 'nowo_', $purge);
        $totals = $store->deleteBatch(['w1'], 'run', new DateTimeImmutable());
        self::assertSame(0, $totals['anchors']);
        self::assertNotContains('SET @nowo_working_time_purge = 1', $statements, 'db_user mode: no session variable');
        self::assertSame(['workdays' => 0, 'segments' => 0, 'revisions' => 0, 'corrections' => 0, 'anchors' => 0], $store->deleteBatch([], 'run', new DateTimeImmutable()));
    }

    public function testATriggerRefusalBecomesPurgeRefusedAndOtherFailuresPass(): void
    {
        foreach (['x is append-only' => PurgeRefusedException::class, 'disk full' => RuntimeException::class] as $message => $expected) {
            $connection = $this->createStub(Connection::class);
            $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
            $connection->method('fetchAllAssociative')->willThrowException(new RuntimeException($message));
            try {
                (new DbalRegisterPurgeStore($this->runner($connection), $this->ids()))->deleteBatch(['w1'], 'run', new DateTimeImmutable());
                self::fail('Thrown.');
            } catch (Throwable $e) {
                self::assertInstanceOf($expected, $e);
            }
        }
    }

    public function testWorkdaysBeforeStopsAtTheLimitAndSkipsOtherTenants(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([['id' => 'a', 'tenant_id' => 'T'], ['id' => 'b', 'tenant_id' => 't'], ['id' => 'c', 'tenant_id' => 't']]);
        $store = new DbalRegisterPurgeStore($this->runner($connection), $this->ids());
        self::assertSame(['b'], $store->workdaysBefore(new TenantRef('t'), new LocalDate('2020-01-01'), 1));
    }

    public function testTheInstallerDropsPostgresqlFunctionsAndIgnoresMissingTables(): void
    {
        $statements = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$statements): int {
            $statements[] = $sql;
            if (str_starts_with($sql, 'DROP TRIGGER')) {
                throw new RuntimeException('no table');
            }

            return 0;
        });
        (new RegisterTriggerInstaller())->uninstall($connection);
        self::assertContains('DROP FUNCTION IF EXISTS nowo_wt_refuse()', $statements);
        self::assertContains('DROP FUNCTION IF EXISTS nowo_wt_segment_close_only()', $statements);
    }

    public function testTheInspectorReportsEverythingMissingWhenTheDatabaseCannotBeAsked(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->method('fetchFirstColumn')->willThrowException(new RuntimeException('down'));
        $connection->method('fetchAllAssociative')->willThrowException(new RuntimeException('down'));
        $installer = new RegisterTriggerInstaller();
        self::assertSame($installer->names(new SQLitePlatform()), (new DoctrineTriggerInspector($this->runner($connection), $installer))->missing());
    }

    public function testTheRunnerMapsFailuresAndNestsInRunningTransactions(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);
        $connection = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $em->method('getConnection')->willReturn($connection);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $work): mixed => $work($em));
        $runner = $this->runner($connection, $em);
        try {
            $duplicate = $this->createStub(UniqueConstraintViolationException::class);
            $runner->write(static function () use ($duplicate): never {
                throw $duplicate;
            });
            self::fail('duplicate'); // @phpstan-ignore deadCode.unreachable
        } catch (DuplicateRowException) {
        }
        try {
            $runner->write(static function (): never {
                throw new OptimisticLockException('stale', null);
            });
            self::fail('stale'); // @phpstan-ignore deadCode.unreachable
        } catch (StaleRowException) {
        }
        self::assertSame(3, $runner->write(static fn (): int => 3));

        $inside = $this->createStub(Connection::class);
        $inside->method('isTransactionActive')->willReturn(true);
        $em2 = $this->createMock(EntityManagerInterface::class);
        $em2->method('isOpen')->willReturn(true);
        $em2->method('getConnection')->willReturn($inside);
        $em2->expects(self::once())->method('flush');
        $em2->expects(self::never())->method('clear');
        $nested = $this->runner($inside, $em2);
        self::assertSame(1, $nested->write(static fn (): int => 1));
        self::assertSame(2, $nested->read(static fn (): int => 2));

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createStub(ObjectManager::class));
        $this->expectException(LogicException::class);
        (new DoctrineTransactionRunner($registry, $this->writeUnit()))->manager();
    }

    public function testAClosedManagerIsResetBeforeTheNextUnit(): void
    {
        $closed = $this->createStub(EntityManagerInterface::class);
        $closed->method('isOpen')->willReturn(false);
        $fresh    = $this->createStub(EntityManagerInterface::class);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($closed);
        $registry->expects(self::once())->method('resetManager')->with('wt')->willReturn($fresh);
        self::assertSame($fresh, (new DoctrineTransactionRunner($registry, $this->writeUnit(), 'wt'))->manager());
    }

    public function testAConfiguredNameOfTheDefaultManagerIsSharedAndAnotherIsPrivate(): void
    {
        $default = $this->createMock(EntityManagerInterface::class);
        $default->expects(self::never())->method('clear');
        $default->method('getUnitOfWork')->willReturn($this->createStub(UnitOfWork::class));
        $private = $this->createMock(EntityManagerInterface::class);
        $private->expects(self::once())->method('clear');
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturnCallback(static fn (?string $name = null): EntityManagerInterface => $name === 'wt' ? $private : $default);

        // `entity_manager: default` resolves to the registry's default manager: only the bundle's entities are released (audit ST Info).
        (new DoctrineTransactionRunner($registry, $this->writeUnit(), 'default'))->release($default);
        (new DoctrineTransactionRunner($registry, $this->writeUnit(), 'wt'))->release($private);
    }

    /**
     * Audit UZ-1 guard: with the host's manager closed there is nothing to watch, so a transient failure goes back to
     * the write unit unchanged (its own repair resets the closed manager).
     */
    public function testAClosedHostManagerLeavesTheFailureToTheWriteUnit(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $deadlock = new DeadlockException(new class('Deadlock', '40001', 1213) extends Driver\AbstractException {
        }, null);
        $private = $this->createStub(EntityManagerInterface::class);
        $private->method('isOpen')->willReturn(true);
        $private->method('getConnection')->willReturn($connection);
        $private->method('wrapInTransaction')->willThrowException($deadlock);
        $host = $this->createStub(EntityManagerInterface::class);
        $host->method('isOpen')->willReturn(false);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturnCallback(static fn (?string $name = null): EntityManagerInterface => $name === 'wt' ? $private : $host);

        try {
            (new DoctrineTransactionRunner($registry, $this->writeUnit(), 'wt'))->write(static fn (): int => 1);
            self::fail('The deadlock is rethrown.');
        } catch (DeadlockException $e) {
            self::assertSame($deadlock, $e);
        }
    }

    public function testThePurgeFlagDriverRecognisesMysql(): void
    {
        self::assertTrue(PurgeFlagResetDriver::isMysql(['driver' => 'pdo_mysql']));
        self::assertTrue(PurgeFlagResetDriver::isMysql(['driverClass' => 'App\MySQLDriver']));
        self::assertFalse(PurgeFlagResetDriver::isMysql(['driver' => 'pdo_pgsql']));
        $driver     = $this->createStub(Driver::class);
        $connection = $this->createMock(Driver\Connection::class);
        $connection->expects(self::once())->method('exec')->with('SET @nowo_working_time_purge = NULL');
        $driver->method('connect')->willReturn($connection);
        (new PurgeFlagResetMiddleware())->wrap($driver)->connect(['driver' => 'pdo_mysql']);
        (new PurgeFlagResetMiddleware())->wrap($driver)->connect(['driver' => 'pdo_sqlite']);
    }

    /**
     * @param list<string> $statements
     * @param list<array<string, mixed>> $chains
     * @param list<array<string, mixed>|false> $last
     */
    private function connection(object $platform, array &$statements, array $chains, array $last): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$statements): int {
            $statements[] = $sql;

            return 1;
        });
        $connection->method('fetchAllAssociative')->willReturn($chains);
        $connection->method('fetchOne')->willReturn(null);
        $connection->method('fetchAssociative')->willReturnOnConsecutiveCalls(...$last);
        $connection->method('insert')->willReturn(1);

        return $connection;
    }

    private function runner(Connection $connection, ?EntityManagerInterface $em = null): DoctrineTransactionRunner
    {
        if (!$em instanceof EntityManagerInterface) {
            $em = $this->createStub(EntityManagerInterface::class);
            $em->method('isOpen')->willReturn(true);
            $em->method('getConnection')->willReturn($connection);
        }
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);

        return new DoctrineTransactionRunner($registry, $this->writeUnit());
    }

    private function writeUnit(): WriteUnitInterface
    {
        $unit = $this->createStub(WriteUnitInterface::class);
        $unit->method('run')->willReturnCallback(static fn (callable $work): mixed => $work(1, null));

        return $unit;
    }

    private function ids(): IdGeneratorInterface
    {
        $ids = $this->createStub(IdGeneratorInterface::class);
        $ids->method('generate')->willReturn('01900000-0000-7000-8000-000000000001');

        return $ids;
    }

    private function manager(object $platform): EntityManagerInterface
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        return $em;
    }
}
