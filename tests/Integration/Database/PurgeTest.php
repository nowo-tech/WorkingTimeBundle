<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Database;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Application\Register\RegisterPurger;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ClockAction;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\CorrectionOperation;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentType;
use Nowo\WorkingTimeBundle\Domain\Register\Model\CorrectionInput;
use Nowo\WorkingTimeBundle\Domain\Register\Service\ChainVerifierInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Service\CorrectionServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Trigger\RegisterTriggerInstaller;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

use function bin2hex;
use function file_get_contents;
use function getenv;
use function is_string;
use function json_decode;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

/**
 * The retention purge (contract section 6.5, spec 198 FR-024) on the configured database: months before the first kept
 * date (4 years by default, never less) are deleted with their segments, links and corrections, one transaction per
 * batch with the chain anchors written in it; the chain still verifies (anchored), a chain the purge emptied continues
 * from its anchor, a late correction link stays until its turn; the triggers let only the purge through (SQLite has no
 * exception: the command exits 2).
 */
final class PurgeTest extends RegisterTestCase
{
    public function testOldMonthsAreDeletedTheChainStaysAnchoredAndVerifies(): void
    {
        $this->bootForPurge();
        $this->dayAt('2022-09-05 08:00:00');
        $early = $this->service(ChainVerifierInterface::class)->verify()->headLinks;
        $bare  = $this->service(ChainVerifierInterface::class)->verify()->heads;
        $this->dayAt('2022-10-31 08:00:00');
        $this->dayAt('2022-11-02 08:00:00');
        // A late correction of 31 October, after 2 November's links: it stays (a chain only loses a prefix).
        $this->clock()->modify('2022-11-04 10:00:00');
        $this->ctx()->member('acme', 'mia', 'Mia');
        $this->actAs('mia', 'manage_team:ana');
        $this->service(CorrectionServiceInterface::class)->record(new WorkerRef('ana'), new LocalDate('2022-10-31'), new CorrectionInput(CorrectionOperation::Add, null, SegmentType::Work, new DateTimeImmutable('2022-10-31 18:00:00 UTC'), new DateTimeImmutable('2022-10-31 19:00:00 UTC'), 'Evening shift not clocked'));
        $this->clock()->modify('2026-11-18 10:00:00');

        $result = $this->service(RegisterPurger::class)->purge();

        self::assertSame('2022-11-01', $result['tenants']['acme']['first_kept']);
        self::assertSame(2, $result['tenants']['acme']['workdays']);
        self::assertSame(8, $result['tenants']['acme']['revisions']);
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_working_time_workday'));
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_working_time_chain_anchor'));
        self::assertSame(5, $this->int('SELECT COUNT(*) FROM nowo_working_time_revision'), 'four kept links and the late correction');
        self::assertTrue($this->service(ChainVerifierInterface::class)->verify()->isIntact());
        self::assertTrue($this->service(ChainVerifierInterface::class)->verify(null, null, $early)->isIntact(), 'a head published before the purge is covered by the anchor past its seq');
        self::assertSame('published_head_missing', $this->service(ChainVerifierInterface::class)->verify(null, null, $bare)->broken[0]['reason'] ?? null, 'a bare hash (0.1.0) has no seq: only its own anchor could excuse it');
        self::assertContains('working_time.register_purged', $this->recorder()->auditKeys());
        if ($this->platform() === 'mysql') {
            self::assertNull($this->connection()->fetchOne('SELECT @nowo_working_time_purge'), 'guard cleared in finally');
        }
    }

    public function testAnEmptiedChainContinuesFromItsAnchor(): void
    {
        $this->bootForPurge();
        $this->dayAt('2021-03-01 08:00:00');
        $this->clock()->modify('2026-11-18 10:00:00');
        $this->service(RegisterPurger::class)->purge();
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_working_time_revision'));

        $head = $this->service(ChainVerifierInterface::class)->verify()->headLinks;
        self::assertSame(['acme' . "\x1F" . 'ana' => ['hash' => $this->text('SELECT anchor_hash FROM nowo_working_time_chain_anchor'), 'seq' => 4, 'at' => null]], $head, 'the head of an emptied chain is its anchor');

        $this->act(ClockAction::ClockIn);

        self::assertSame(5, $this->int('SELECT seq FROM nowo_working_time_revision'));
        self::assertTrue($this->service(ChainVerifierInterface::class)->verify()->isIntact());
        self::assertTrue($this->service(ChainVerifierInterface::class)->verify(null, null, $head)->isIntact(), 'the anchor hash is the purged link\'s own');
    }

    /**
     * Audit ST-D1: after the chain's first real purge, a head written with `--heads-out` must still catch a removed tail
     * (the last workday's links, segments and row deleted with the purge flag), which no other check sees.
     */
    public function testAHeadPublishedAfterAPurgeStillCatchesARemovedTail(): void
    {
        $this->bootForPurge();
        $this->dayAt('2021-03-01 08:00:00');
        $this->dayAt('2026-11-16 08:00:00');
        $this->dayAt('2026-11-17 08:00:00');
        $this->clock()->modify('2026-11-18 10:00:00');
        $this->service(RegisterPurger::class)->purge();
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_working_time_chain_anchor'), 'a real purge');
        $file   = sys_get_temp_dir() . '/wt-heads-' . bin2hex(random_bytes(4)) . '.json';
        $tester = new CommandTester((new Application($this->kernel))->find('nowo:working-time:verify'));
        self::assertSame(0, $tester->execute(['--heads-out' => $file]));
        $written = json_decode((string) file_get_contents($file), true);
        self::assertSame(['acme' . "\x1F" . 'ana' => ['hash' => $this->text('SELECT hash FROM nowo_working_time_revision ORDER BY seq DESC'), 'seq' => 12, 'at' => '2026-11-17T16:30:00Z']], $written);
        self::assertSame(0, $tester->execute(['--heads' => $file]), 'nothing removed yet');

        $workday = $this->text("SELECT id FROM nowo_working_time_workday WHERE local_date = '2026-11-17'");
        $this->withPurgeFlag([
            'DELETE FROM nowo_working_time_revision WHERE workday_id = ?',
            'DELETE FROM nowo_working_time_segment WHERE workday_id = ?',
            'DELETE FROM nowo_working_time_workday WHERE id = ?',
        ], $workday);
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_working_time_workday WHERE id = ?', [$workday]));

        $report = $this->service(ChainVerifierInterface::class)->verify(null, null, ['acme' . "\x1F" . 'ana' => ['hash' => (string) $written['acme' . "\x1F" . 'ana']['hash'], 'seq' => 12]]);
        self::assertFalse($report->isIntact(), 'verify --heads reports NOT intact');
        self::assertSame('published_head_missing', $report->broken[0]['reason']);
        self::assertSame(1, $tester->execute(['--heads' => $file]));
        self::assertStringContainsString('published_head_missing', $tester->getDisplay());
        unlink($file);
    }

    public function testTheDryRunCountsAndTheCommandReports(): void
    {
        $this->bootForPurge();
        $this->dayAt('2021-03-01 08:00:00');
        $this->clock()->modify('2026-11-18 10:00:00');
        $tester = new CommandTester((new Application($this->kernel))->find('nowo:working-time:purge'));

        self::assertSame(0, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('1 workday(s)', $tester->getDisplay());
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_working_time_workday'));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('export file(s) expired', $tester->getDisplay());
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_working_time_workday'));
    }

    public function testTheTriggersRefuseAPurgeWithoutTheGuard(): void
    {
        $this->boot();
        $this->enable();
        $this->actAs('ana', 'clock');
        $this->dayAt('2021-03-01 08:00:00');
        $this->clock()->modify('2026-11-18 10:00:00');
        $tester = new CommandTester((new Application($this->kernel))->find('nowo:working-time:purge'));
        if ($this->platform() !== 'sqlite') {
            self::assertSame(0, $tester->execute([]), 'MySQL / MariaDB / PostgreSQL: the guard lets the purge through');

            return;
        }
        self::assertSame(2, $tester->execute([]));
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_working_time_workday'), 'nothing deleted');
    }

    public function testAFailureExitsOne(): void
    {
        $this->bootForPurge();
        $this->dayAt('2021-03-01 08:00:00');
        $this->clock()->modify('2026-11-18 10:00:00');
        $this->connection()->executeStatement('DROP TABLE nowo_working_time_correction');
        $tester = new CommandTester((new Application($this->kernel))->find('nowo:working-time:purge'));

        self::assertSame(1, $tester->execute([]));
    }

    private function bootForPurge(): void
    {
        $this->boot(triggers: $this->usesGuard());
        $this->enable();
        $this->actAs('ana', 'clock');
    }

    private function usesGuard(): bool
    {
        $url = getenv('NOWO_TEST_DATABASE_URL');

        return is_string($url) && $url !== '';
    }

    /**
     * Runs the statements as the purge does: with the guard on MySQL / MariaDB / PostgreSQL; SQLite runs this suite
     * without the triggers.
     *
     * @param list<string> $statements
     */
    private function withPurgeFlag(array $statements, string $parameter): void
    {
        $connection = $this->connection();
        $platform   = $this->platform();
        $connection->beginTransaction();
        if ($platform === 'mysql') {
            $connection->executeStatement('SET ' . RegisterTriggerInstaller::PURGE_VARIABLE . ' = 1');
        } elseif ($platform === 'postgresql') {
            $connection->executeStatement('SET LOCAL ' . RegisterTriggerInstaller::PURGE_SETTING . " = '1'");
        }
        foreach ($statements as $sql) {
            $connection->executeStatement($sql, [$parameter]);
        }
        $connection->commit();
        if ($platform === 'mysql') {
            $connection->executeStatement('SET ' . RegisterTriggerInstaller::PURGE_VARIABLE . ' = NULL');
        }
    }

    private function dayAt(string $at): void
    {
        $this->clock()->modify($at);
        $this->workDay();
    }
}
