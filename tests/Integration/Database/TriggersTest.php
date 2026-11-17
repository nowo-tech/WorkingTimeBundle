<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ClockAction;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Trigger\RegisterTriggerInstaller;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Command\TriggersCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

use function bin2hex;
use function count;
use function random_bytes;
use function random_int;
use function str_repeat;
use function substr;

/**
 * The append-only triggers on the configured database (contract section 6.4; CI: SQLite, MySQL 8.4, MariaDB 11.4,
 * PostgreSQL 16): UPDATE and DELETE of workday, revision and anchor rows refused; a segment closes once and nothing
 * else of it changes; DELETE passes only with the purge guard (MySQL / MariaDB session variable, PostgreSQL SET LOCAL
 * inside the transaction); TRUNCATE refused on PostgreSQL; the guard is cleared in `finally` and on every connect.
 */
final class TriggersTest extends RegisterTestCase
{
    public function testUpdatesAndDeletesOfTheRecordAreRefused(): void
    {
        $this->bootWithDay();
        $c = $this->connection();
        $this->insertAnchor($c);
        foreach (['workday' => 'worker_name', 'revision' => 'author_name', 'chain_anchor' => 'purge_run_id'] as $table => $column) {
            $this->assertRefused(static fn (): int|string => $c->executeStatement("UPDATE nowo_working_time_{$table} SET {$column} = 'x'"), $table . ' update');
            $this->assertRefused(static fn (): int|string => $c->executeStatement("DELETE FROM nowo_working_time_{$table}"), $table . ' delete');
        }
        $this->assertRefused(static fn (): int|string => $c->executeStatement('DELETE FROM nowo_working_time_segment'), 'segment delete');
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_working_time_workday'));
    }

    public function testASegmentClosesOnceAndNothingElseChanges(): void
    {
        $this->boot();
        $this->enable();
        $this->actAs('ana', 'clock');
        $this->act(ClockAction::ClockIn);
        $c  = $this->connection();
        $id = $c->fetchOne('SELECT id FROM nowo_working_time_segment');
        self::assertIsString($id);
        $this->assertRefused(static fn (): int|string => $c->executeStatement("UPDATE nowo_working_time_segment SET started_at = '2020-01-01 00:00:00' WHERE id = ?", [$id]), 'start change');
        $this->assertRefused(static fn (): int|string => $c->executeStatement("UPDATE nowo_working_time_segment SET end_source = 'web' WHERE id = ?", [$id]), 'end source without end');
        self::assertSame(1, $c->executeStatement("UPDATE nowo_working_time_segment SET ended_at = '2026-11-18 12:00:00', end_source = 'web', end_received_at = '2026-11-18 12:00:00' WHERE id = ?", [$id]));
        $this->assertRefused(static fn (): int|string => $c->executeStatement("UPDATE nowo_working_time_segment SET ended_at = '2026-11-18 13:00:00' WHERE id = ?", [$id]), 'second close');
    }

    public function testThePurgeGuardLetsDeletesThroughInsideItsTransactionOnly(): void
    {
        $this->bootWithDay();
        $c = $this->connection();
        if ($this->platform() === 'sqlite') {
            $this->assertRefused(static fn (): int|string => $c->executeStatement('DELETE FROM nowo_working_time_revision'), 'SQLite has no purge exception');

            return;
        }
        $c->beginTransaction();
        if ($this->platform() === 'mysql') {
            $c->executeStatement('SET ' . RegisterTriggerInstaller::PURGE_VARIABLE . ' = 1');
        } else {
            $c->executeStatement('SET LOCAL ' . RegisterTriggerInstaller::PURGE_SETTING . " = '1'");
        }
        self::assertGreaterThan(0, $c->executeStatement('DELETE FROM nowo_working_time_revision'));
        $this->insertAnchor($c);
        $this->assertRefused(static fn (): int|string => $c->executeStatement('DELETE FROM nowo_working_time_chain_anchor WHERE 1 = 1'), 'anchors are never deleted');
        $c->rollBack();
        if ($this->platform() === 'mysql') {
            $c->executeStatement('SET ' . RegisterTriggerInstaller::PURGE_VARIABLE . ' = NULL');
        }
        $this->assertRefused(static fn (): int|string => $c->executeStatement('DELETE FROM nowo_working_time_revision'), 'guard gone after the transaction');
    }

    public function testTheMysqlGuardIsClearedOnEveryConnect(): void
    {
        $this->bootWithDay();
        $c = $this->connection();
        if ($this->platform() !== 'mysql') {
            self::assertSame(0, $this->int('SELECT 0'), 'MySQL / MariaDB only');

            return;
        }
        $c->executeStatement('SET ' . RegisterTriggerInstaller::PURGE_VARIABLE . ' = 1');
        $c->close();
        self::assertNull($c->fetchOne('SELECT ' . RegisterTriggerInstaller::PURGE_VARIABLE));
    }

    public function testTruncateIsRefusedOnPostgresql(): void
    {
        $this->bootWithDay();
        if ($this->platform() !== 'postgresql') {
            self::assertSame(0, $this->int('SELECT 0'), 'PostgreSQL only');

            return;
        }
        $c = $this->connection();
        foreach (['workday', 'segment', 'revision', 'chain_anchor'] as $table) {
            $this->assertRefused(static fn (): int|string => $c->executeStatement("TRUNCATE nowo_working_time_{$table} CASCADE"), $table . ' truncate');
        }
    }

    public function testTheCommandsInstallCheckAndRemoveTheTriggers(): void
    {
        $this->boot(triggers: false);
        $application = new Application($this->kernel);
        $check       = new CommandTester($application->find(TriggersCommand::CHECK));
        self::assertSame(1, $check->execute([]));
        self::assertStringContainsString('Missing triggers', $check->getDisplay());
        self::assertSame(0, (new CommandTester($application->find(TriggersCommand::INSTALL)))->execute([]));
        self::assertSame(0, $check->execute([]));
        self::assertSame([], $this->installer()->missing($this->connection()));
        self::assertTrue($this->installer()->installed($this->connection()));
        self::assertSame(0, (new CommandTester($application->find(TriggersCommand::UNINSTALL)))->execute([]));
        self::assertNotSame([], $this->installer()->missing($this->connection()));
    }

    public function testAReplacedOrDisabledTriggerIsNotInstalled(): void
    {
        $this->boot();
        $c    = $this->connection();
        $name = 'nowo_wt_revision_no_update';
        self::assertTrue($this->installer()->installed($c));
        if ($this->platform() === 'postgresql') {
            $c->executeStatement('ALTER TABLE nowo_working_time_revision DISABLE TRIGGER ' . $name);
            self::assertSame([$name], $this->installer()->missing($c), 'P6: a disabled trigger');
            $c->executeStatement('ALTER TABLE nowo_working_time_revision ENABLE TRIGGER ' . $name);
            $c->executeStatement('DROP TRIGGER ' . $name . ' ON nowo_working_time_revision');
            $c->executeStatement('CREATE OR REPLACE FUNCTION audit_noop() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NEW; END $$');
            $c->executeStatement('CREATE TRIGGER ' . $name . ' BEFORE UPDATE ON nowo_working_time_revision FOR EACH ROW EXECUTE FUNCTION audit_noop()');
        } elseif ($this->platform() === 'mysql') {
            $c->executeStatement('DROP TRIGGER ' . $name);
            $c->executeStatement('CREATE TRIGGER ' . $name . ' BEFORE UPDATE ON nowo_working_time_revision FOR EACH ROW BEGIN END');
        } else {
            $c->executeStatement('DROP TRIGGER ' . $name);
            $c->executeStatement('CREATE TRIGGER ' . $name . ' BEFORE UPDATE ON nowo_working_time_revision BEGIN SELECT 1; END');
        }

        self::assertSame([$name], $this->installer()->missing($c), 'P5: a same-name no-op trigger');
    }

    public function testAFailedInstallLeavesNothingBehind(): void
    {
        $this->boot(triggers: false);
        $this->connection()->executeStatement('DROP TABLE nowo_working_time_chain_anchor');
        $application = new Application($this->kernel);
        $tester      = new CommandTester($application->find(TriggersCommand::INSTALL));
        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('could not be created', $tester->getDisplay());
        self::assertCount(count($this->installer()->names($this->connection()->getDatabasePlatform())), $this->installer()->missing($this->connection()));
    }

    private function bootWithDay(): void
    {
        $this->boot();
        $this->enable();
        $this->actAs('ana', 'clock');
        $this->workDay();
    }

    private function insertAnchor(Connection $c): int
    {
        return (int) $c->insert('nowo_working_time_chain_anchor', [
            'id'             => '01900000-0000-7000-8000-' . substr(bin2hex(random_bytes(6)), 0, 12),
            'tenant_id'      => 'acme',
            'worker_id'      => 'zed',
            'first_kept_seq' => random_int(10, 999999),
            'anchor_hash'    => str_repeat('a', 64),
            'purged_links'   => 1,
            'purged_at'      => '2026-01-01 00:00:00',
            'purge_run_id'   => '01900000-0000-7000-8000-000000000000',
        ]);
    }

    /**
     * @param callable(): mixed $statement
     */
    private function assertRefused(callable $statement, string $what): void
    {
        try {
            $statement();
        } catch (Throwable $e) {
            self::assertTrue(RegisterTriggerInstaller::isRefusal($e), $what . ': ' . $e->getMessage());

            return;
        }
        self::fail($what . ' was not refused.');
    }
}
