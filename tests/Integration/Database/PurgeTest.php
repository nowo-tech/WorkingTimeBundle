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
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

use function getenv;
use function is_string;

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

        $this->act(ClockAction::ClockIn);

        self::assertSame(5, $this->int('SELECT seq FROM nowo_working_time_revision'));
        self::assertTrue($this->service(ChainVerifierInterface::class)->verify()->isIntact());
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

    private function dayAt(string $at): void
    {
        $this->clock()->modify($at);
        $this->workDay();
    }
}
