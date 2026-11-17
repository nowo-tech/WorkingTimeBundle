<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Database;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Domain\Register\Chain\ChainCodecs;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ChainFormat;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ClockAction;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ExportFormat;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\RevisionKind;
use Nowo\WorkingTimeBundle\Domain\Register\Model\DaySchedule;
use Nowo\WorkingTimeBundle\Domain\Register\Model\Workday;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkdayRevision;
use Nowo\WorkingTimeBundle\Domain\Register\Service\ChainVerifierInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Service\RegisterExportServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

use function bin2hex;
use function file_put_contents;
use function json_decode;
use function json_encode;
use function microtime;
use function random_bytes;
use function str_repeat;
use function sys_get_temp_dir;
use function unlink;

/**
 * The hash chain on the configured database (contract section 6.3, Q-O4): a migrated Beacon chain (format 1) is never
 * re-hashed and the bundle's first link (format 2) continues from its last hash; tampering of any kind is found and
 * named (changed payload, removed link, removed oldest links without an anchor, changed or removed segment).
 */
final class ChainIntegrityTest extends RegisterTestCase
{
    public function testAMigratedBeaconChainVerifiesAndTheBundleContinuesIt(): void
    {
        $this->boot();
        $this->enable();
        $this->actAs('ana', 'clock');
        $em      = $this->em();
        $workday = Workday::migrated('01900000-0000-4000-8000-00000000000a', new TenantRef('acme'), new WorkerRef('ana'), 'Ana', new LocalDate('2026-11-10'), new DaySchedule('Europe/Madrid'), new DateTimeImmutable('2026-11-10 08:00:00 UTC'), 11);
        $em->persist($workday);
        $first = WorkdayRevision::migrated('01900000-0000-4000-8000-0000000000b1', $workday, 1, RevisionKind::Correction, null, ['op' => 'add', 'segment' => 'x'], 'Forgot to clock', '7', 'Mia', new DateTimeImmutable('2026-11-10 09:00:00 UTC'), null, ChainCodecs::GENESIS, ['organization' => 3, 'user' => 5, 'workday' => 11, 'author' => 7]);
        $em->persist($first);
        $second = WorkdayRevision::migrated('01900000-0000-4000-8000-0000000000b2', $workday, 2, RevisionKind::Acknowledged, null, ['revision' => $first->getId()], null, '5', 'Ana', new DateTimeImmutable('2026-11-10 10:00:00 UTC'), null, $first->getHash(), ['organization' => 3, 'user' => 5, 'workday' => 11, 'author' => 5]);
        $em->persist($second);
        $em->flush();
        $em->clear();

        $this->act(ClockAction::ClockIn);
        $verifier = $this->service(ChainVerifierInterface::class);
        $report   = $verifier->verify(new TenantRef('acme'));

        self::assertTrue($report->isIntact(), (string) json_encode($report->broken));
        self::assertSame(3, $report->links);
        $rows = $this->connection()->fetchAllAssociative('SELECT seq, chain_format, prev_hash, hash FROM nowo_working_time_revision ORDER BY seq');
        self::assertSame([1, 1, 2], [is_numeric($rows[0]['chain_format']) ? (int) $rows[0]['chain_format'] : 0, is_numeric($rows[1]['chain_format']) ? (int) $rows[1]['chain_format'] : 0, is_numeric($rows[2]['chain_format']) ? (int) $rows[2]['chain_format'] : 0]);
        self::assertSame($second->getHash(), $rows[2]['prev_hash']);
        self::assertSame($first->getHash(), $rows[0]['hash'], 'never re-hashed');
        self::assertSame($rows[2]['hash'], $verifier->head(new TenantRef('acme'), new WorkerRef('ana')));
        self::assertSame(ChainFormat::Bundle->value, is_numeric($rows[2]['chain_format']) ? (int) $rows[2]['chain_format'] : 0);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function tampering(): iterable
    {
        yield 'changed payload' => ["UPDATE nowo_working_time_revision SET reason = 'edited' WHERE seq = 2", 'hash_mismatch'];
        yield 'removed link' => ['DELETE FROM nowo_working_time_revision WHERE seq = 2', 'missing_link'];
        yield 'removed oldest link, no anchor' => ['DELETE FROM nowo_working_time_revision WHERE seq = 1', 'unanchored_start'];
        yield 'changed segment start' => ["UPDATE nowo_working_time_segment SET started_at = '2026-11-18 06:00:00'", 'segment_changed'];
        yield 'removed segment' => ['DELETE FROM nowo_working_time_segment', 'segment_missing'];
        yield 'forged segment without a link (P10)' => ["INSERT INTO nowo_working_time_segment (id, workday_id, type, started_at, start_source, start_received_at, ended_at, end_source, end_received_at) SELECT '01900000-0000-7000-8000-0000000000aa', id, 'work', '2026-11-18 20:00:00', 'web', '2026-11-18 20:00:00', '2026-11-18 23:00:00', 'web', '2026-11-18 23:00:00' FROM nowo_working_time_workday", 'segment_unlinked'];
        yield 'removed tail (P12)' => ['DELETE FROM nowo_working_time_revision WHERE seq = 4', 'segment_close_unlinked'];
        yield 'removed chain, segments kept (MySQL TRUNCATE, P7)' => ['DELETE FROM nowo_working_time_revision', 'segment_unlinked'];
        yield 'forged anchor past the chain start (ST-D1)' => ["INSERT INTO nowo_working_time_chain_anchor (id, tenant_id, worker_id, first_kept_seq, anchor_hash, purged_links, purged_at, purge_run_id, first_kept_date) SELECT '01900000-0000-7000-8000-0000000000bc', tenant_id, worker_id, 5, hash, 4, '2026-11-18 07:00:00', 'run', '2022-11-01' FROM nowo_working_time_revision WHERE seq = 4", 'anchor_after_start'];
        yield 'forged anchor re-rooting a recent chain (P12)' => ["INSERT INTO nowo_working_time_chain_anchor (id, tenant_id, worker_id, first_kept_seq, anchor_hash, purged_links, purged_at, purge_run_id, first_kept_date) SELECT '01900000-0000-7000-8000-0000000000bb', tenant_id, worker_id, 2, prev_hash, 1, '2026-11-18 20:00:00', 'run', '2026-11-01' FROM nowo_working_time_revision WHERE seq = 2", 'anchor_out_of_retention'];
    }

    #[DataProvider('tampering')]
    public function testTamperingIsFoundAndNamed(string $sql, string $reason): void
    {
        $this->boot();
        $this->enable();
        $this->actAs('ana', 'clock');
        $this->workDay();
        $this->installer()->uninstall($this->connection());
        $this->connection()->executeStatement($sql);

        $report = $this->service(ChainVerifierInterface::class)->verify(null, new WorkerRef('ana'));

        self::assertFalse($report->isIntact());
        self::assertSame($reason, $report->broken[0]['reason']);
        self::assertContains('working_time.chain_broken', $this->recorder()->auditKeys());
        $tester = new CommandTester((new Application($this->kernel))->find('nowo:working-time:verify'));
        self::assertSame(1, $tester->execute(['--worker' => 'ana', '--tenant' => 'acme']));
        self::assertStringContainsString($reason, $tester->getDisplay());
    }

    public function testARawCloseOfAnOpenSegmentIsFound(): void
    {
        $this->boot();
        $this->enable();
        $this->actAs('ana', 'clock');
        $this->act(ClockAction::ClockIn);
        $this->connection()->executeStatement("UPDATE nowo_working_time_segment SET ended_at = '2026-11-18 18:00:00', end_source = 'web', end_received_at = '2026-11-18 18:00:00'");

        self::assertSame('segment_close_unlinked', $this->service(ChainVerifierInterface::class)->verify()->broken[0]['reason'] ?? null, 'P11: the trigger allows one close, the chain has no closing link');
    }

    public function testARehashedHeadThatMovesTheLastSegmentIsStale(): void
    {
        $this->boot();
        $this->enable();
        $this->actAs('ana', 'clock');
        $this->workDay();
        $this->installer()->uninstall($this->connection());
        $head    = $this->text('SELECT id FROM nowo_working_time_revision ORDER BY seq DESC');
        $segment = $this->text('SELECT id FROM nowo_working_time_segment WHERE ended_at IS NOT NULL ORDER BY ended_at DESC');
        $later   = new DateTimeImmutable('2026-11-18 23:30:00 UTC');
        $this->connection()->executeStatement('UPDATE nowo_working_time_segment SET ended_at = ? WHERE id = ?', [$later->format('Y-m-d H:i:s'), $segment]);
        $revision = $this->em()->find(WorkdayRevision::class, $head);
        self::assertInstanceOf(WorkdayRevision::class, $revision);
        $payload       = $revision->getPayload();
        $payload['at'] = WorkdayRevision::iso($later);
        $this->connection()->executeStatement('UPDATE nowo_working_time_revision SET payload = ? WHERE id = ?', [json_encode($payload), $head]);
        $this->em()->clear();
        $forged = $this->em()->find(WorkdayRevision::class, $head);
        self::assertInstanceOf(WorkdayRevision::class, $forged);
        $hash = ChainCodecs::hashOf($forged->getPrevHash(), ChainCodecs::for($forged->getChainFormat())->canonical($forged));
        $this->connection()->executeStatement('UPDATE nowo_working_time_revision SET hash = ? WHERE id = ?', [$hash, $head]);
        $this->em()->clear();

        self::assertSame('head_stale', $this->service(ChainVerifierInterface::class)->verify()->broken[0]['reason'] ?? null, 'the head was written before the segment it closes');
    }

    public function testAHeadPublishedEarlierMustStillBeALink(): void
    {
        $this->boot();
        $this->enable();
        $this->actAs('ana', 'clock', 'export');
        $this->workDay();
        $verifier = $this->service(ChainVerifierInterface::class);
        self::assertSame(1, $verifier->publishHeads($verifier->verify()));
        self::assertContains('working_time.chain_head', $this->recorder()->auditKeys());
        $this->service(RegisterExportServiceInterface::class)->request(new WorkerRef('ana'), null, '2026-11', '2026-11', ExportFormat::Csv);
        $file   = sys_get_temp_dir() . '/wt-heads-' . bin2hex(random_bytes(4)) . '.json';
        $tester = new CommandTester((new Application($this->kernel))->find('nowo:working-time:verify'));
        self::assertSame(0, $tester->execute(['--heads-out' => $file, '--publish-heads' => true]));
        $recorded = json_decode($this->text('SELECT chain_heads FROM nowo_working_time_export'), true);
        self::assertEquals(['ana' => ['hash' => $this->text('SELECT hash FROM nowo_working_time_revision ORDER BY seq DESC'), 'seq' => 4]], $recorded, 'the export records the head with its seq (ST-D1b)');

        // The whole register removed with the purge flag (or TRUNCATE): nothing left but published heads to compare.
        $this->installer()->uninstall($this->connection());
        $this->connection()->executeStatement('DELETE FROM nowo_working_time_revision');
        $this->connection()->executeStatement('DELETE FROM nowo_working_time_segment');
        $this->connection()->executeStatement('DELETE FROM nowo_working_time_workday');
        self::assertSame('published_head_missing', $verifier->verify()->broken[0]['reason'] ?? null, 'the export\'s chain head is gone');
        $this->connection()->executeStatement('UPDATE nowo_working_time_export SET chain_heads = ?', [json_encode(['ana' => str_repeat('c', 64)])]);
        self::assertSame('published_head_missing', $verifier->verify()->broken[0]['reason'] ?? null, 'a 0.1.0 export row (bare hash) still counts');
        self::assertSame(1, $tester->execute(['--heads' => $file]));
        file_put_contents($file, 'nope');
        self::assertSame(2, $tester->execute(['--heads' => $file]));
        file_put_contents($file, (string) json_encode(['acme' . "\x1F" . 'ana' => str_repeat('a', 64)]));
        self::assertSame(2, $tester->execute(['--heads' => $file]), 'a 0.1.0 file (bare hashes) is refused');
        self::assertStringContainsString('written by 0.1.0', $tester->getDisplay());
        file_put_contents($file, (string) json_encode(['acme' . "\x1F" . 'ana' => ['hash' => str_repeat('a', 64)]]));
        self::assertSame(2, $tester->execute(['--heads' => $file]), 'an entry without its seq');
        self::assertStringContainsString('invalid entry', $tester->getDisplay());
        unlink($file);
    }

    public function testAnEmptyChainHeadIsTheGenesis(): void
    {
        $this->boot();
        $report = $this->service(ChainVerifierInterface::class)->verify(null, null, ['acme' . "\x1F" . 'ana' => ['hash' => ChainCodecs::GENESIS, 'seq' => 0, 'at' => null]]);

        self::assertTrue($report->isIntact(), 'a chain published while it was still empty has nothing to lose');
        self::assertSame(['acme' . "\x1F" . 'ana' => ['hash' => ChainCodecs::GENESIS, 'seq' => 0, 'at' => null]], $report->headLinks);
    }

    public function testTheVerifyCommandReportsAnIntactRegister(): void
    {
        $this->boot();
        $this->enable();
        $this->actAs('ana', 'clock');
        $this->workDay();
        $tester = new CommandTester((new Application($this->kernel))->find('nowo:working-time:verify'));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('1 chain(s), 4 link(s)', $tester->getDisplay());
    }

    public function testALongChainVerifiesInBatchesWithinTheBudget(): void
    {
        $this->boot(triggers: false);
        $this->enable();
        $this->actAs('ana', 'clock');
        for ($day = 0; $day < 260; ++$day) {
            $this->workDay();
            $this->clock()->modify('+15 hours 30 minutes');
        }
        $start  = microtime(true);
        $report = $this->service(ChainVerifierInterface::class)->verify();
        self::assertTrue($report->isIntact());
        self::assertSame(1040, $report->links);
        self::assertLessThan(5.0, microtime(true) - $start);
    }
}
