<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Infrastructure\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use LogicException;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Trigger\RegisterTriggerInstaller;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Trigger\TriggerInstallException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function implode;
use function str_contains;
use function substr_count;

/**
 * SQL of the append-only triggers per platform (no database needed: only the statements are inspected).
 */
final class RegisterTriggerInstallerTest extends TestCase
{
    /**
     * @return iterable<string, array{AbstractPlatform}>
     */
    public static function mysqlPlatforms(): iterable
    {
        yield 'mysql 8' => [new MySQL80Platform()];
        yield 'mariadb' => [new MariaDBPlatform()];
    }

    #[DataProvider('mysqlPlatforms')]
    public function testMysqlStatementsGuardTheDeleteWithTheSessionVariable(AbstractPlatform $platform): void
    {
        $statements = (new RegisterTriggerInstaller())->statements($platform);
        $sql        = implode("\n", $statements);

        self::assertCount(8, $statements);
        self::assertStringContainsString('COALESCE(@nowo_working_time_purge, 0) <> 1', $sql);
        self::assertStringContainsString("SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'nowo_working_time_revision is append-only'", $sql);
        self::assertStringContainsString('CREATE TRIGGER nowo_wt_segment_no_update BEFORE UPDATE ON nowo_working_time_segment', $sql);
        self::assertStringContainsString('NOT (NEW.`workday_id` <=> OLD.`workday_id`)', $sql);
        self::assertStringNotContainsString('USER()', $sql);
        // The chain anchor is never deletable, not even by the purge.
        self::assertStringContainsString('CREATE TRIGGER nowo_wt_chain_anchor_no_delete BEFORE DELETE ON nowo_working_time_chain_anchor FOR EACH ROW BEGIN SIGNAL', $sql);
    }

    #[DataProvider('mysqlPlatforms')]
    public function testMysqlStatementsWithAPurgeUserCompareTheDatabaseUser(AbstractPlatform $platform): void
    {
        $sql = implode("\n", (new RegisterTriggerInstaller('x_', 'purge.user-1$'))->statements($platform));

        self::assertStringContainsString("SUBSTRING_INDEX(USER(), '@', 1) <> 'purge.user-1\$'", $sql);
        self::assertStringNotContainsString('@nowo_working_time_purge', $sql);
        self::assertStringContainsString('CREATE TRIGGER x_wt_workday_no_delete', $sql);
    }

    public function testPostgresqlStatements(): void
    {
        $statements = (new RegisterTriggerInstaller())->statements(new PostgreSQLPlatform());
        $sql        = implode("\n", $statements);

        self::assertCount(2 + 12, $statements);
        self::assertStringContainsString("current_setting('nowo_working_time.purge', true) = '1'", $sql);
        self::assertStringContainsString('CREATE OR REPLACE FUNCTION nowo_wt_refuse() RETURNS trigger', $sql);
        self::assertStringContainsString('CREATE OR REPLACE FUNCTION nowo_wt_segment_close_only() RETURNS trigger', $sql);
        self::assertStringContainsString('BEFORE TRUNCATE ON nowo_working_time_revision FOR EACH STATEMENT EXECUTE FUNCTION nowo_wt_refuse()', $sql);
        self::assertStringContainsString('EXECUTE FUNCTION nowo_wt_segment_close_only()', $sql);
        self::assertStringContainsString("TG_TABLE_NAME <> 'nowo_working_time_chain_anchor'", $sql);
        self::assertStringContainsString('(NEW.id, NEW.workday_id', $sql);
        self::assertStringContainsString('IS DISTINCT FROM (OLD.id, OLD.workday_id', $sql);
    }

    public function testPostgresqlWithAPurgeUserComparesCurrentUser(): void
    {
        $sql = implode("\n", (new RegisterTriggerInstaller('nowo_', 'purger'))->statements(new PostgreSQLPlatform()));

        self::assertStringContainsString("current_user = 'purger'", $sql);
        self::assertStringNotContainsString('current_setting', $sql);
    }

    public function testSqliteStatements(): void
    {
        $statements = (new RegisterTriggerInstaller())->statements(new SQLitePlatform());
        $sql        = implode("\n", $statements);

        self::assertCount(8, $statements);
        self::assertSame(4, substr_count($sql, 'BEFORE DELETE'));
        self::assertStringContainsString("SELECT RAISE(ABORT, 'nowo_working_time_workday is append-only');", $sql);
        self::assertStringContainsString('WHEN OLD.ended_at IS NOT NULL OR NEW.ended_at IS NULL OR NEW."id" IS NOT OLD."id"', $sql);
        self::assertStringContainsString('CREATE TRIGGER nowo_wt_workday_no_update BEFORE UPDATE ON nowo_working_time_workday BEGIN', $sql);
    }

    public function testAnUnknownPlatformIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no append-only triggers for ' . OraclePlatform::class);

        (new RegisterTriggerInstaller())->statements(new OraclePlatform());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badUsers(): iterable
    {
        yield 'empty' => [''];
        yield 'quote' => ["bob'; DROP"];
        yield 'space' => ['a b'];
        yield 'at sign' => ['a@b'];
        yield 'too long' => [str_repeat('a', 81)];
    }

    #[DataProvider('badUsers')]
    public function testAnInvalidPurgeUserIsRefused(string $user): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('integrity.purge_db_user');

        new RegisterTriggerInstaller('nowo_', $user);
    }

    public function testNames(): void
    {
        $installer = new RegisterTriggerInstaller('p_');

        $mysql = $installer->names(new MySQL80Platform());
        self::assertCount(8, $mysql);
        self::assertContains('p_wt_workday_no_update', $mysql);
        self::assertContains('p_wt_chain_anchor_no_delete', $mysql);
        self::assertNotContains('p_wt_workday_no_truncate', $mysql);

        $postgres = $installer->names(new PostgreSQLPlatform());
        self::assertCount(12, $postgres);
        self::assertContains('p_wt_revision_no_truncate', $postgres);

        self::assertCount(8, $installer->names(new SQLitePlatform()));
    }

    public function testIsRefusalWalksThePreviousChain(): void
    {
        $inner = new RuntimeException('SQLSTATE: nowo_working_time_revision is append-only');

        self::assertTrue(RegisterTriggerInstaller::isRefusal($inner));
        self::assertTrue(RegisterTriggerInstaller::isRefusal(new RuntimeException('wrapper', 0, $inner)));
        self::assertFalse(RegisterTriggerInstaller::isRefusal(new RuntimeException('deadlock', 0, new RuntimeException('other'))));
        self::assertTrue(str_contains($inner->getMessage(), 'append-only'));
    }

    public function testTriggerInstallExceptionMessages(): void
    {
        $previous = new RuntimeException('denied');

        $mysql = new TriggerInstallException(true, $previous);
        self::assertTrue($mysql->mysql);
        self::assertStringContainsString('TRIGGER privilege', $mysql->getMessage());
        self::assertSame($previous, $mysql->getPrevious());

        $other = new TriggerInstallException(false, $previous);
        self::assertFalse($other->mysql);
        self::assertStringContainsString('privilege to create triggers and functions', $other->getMessage());
    }
}
