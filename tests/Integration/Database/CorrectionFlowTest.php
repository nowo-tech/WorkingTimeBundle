<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Database;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Application\Register\Exception\StaleCorrectionDecisionException;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ClockAction;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\CorrectionOperation;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\CorrectionStatus;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\DayFlag;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentType;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\CorrectionRefusedException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\PermissionDeniedException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\RegisterUnavailableException;
use Nowo\WorkingTimeBundle\Domain\Register\Model\CorrectionInput;
use Nowo\WorkingTimeBundle\Domain\Register\Model\CorrectionRequest;
use Nowo\WorkingTimeBundle\Domain\Register\Read\DayRecord;
use Nowo\WorkingTimeBundle\Domain\Register\Service\ChainVerifierInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Service\CorrectionServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Service\RegisterReaderInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

use const DATE_ATOM;

/**
 * Corrections (spec 198 FR-014 – FR-016, contract sections 9.1 and 15.2): request, decision on the version shown (a
 * stale version stores nothing and notifies nobody: 409), never on one's own day, manager-recorded corrections with
 * the worker's acknowledgement or contest, and the refusals.
 */
final class CorrectionFlowTest extends RegisterTestCase
{
    public function testARequestIsApprovedOnTheVersionShownAndTheDayChanges(): void
    {
        $request = $this->requestOnFirstSegment();
        $this->actAs('mia', 'manage_team:ana', 'manage_team');
        $this->ctx()->manages['acme']['mia'] = ['ana'];
        $notices                             = $this->noticeCount();

        $decided = $this->corrections()->decide($request->getId(), true, 'ok', $request->getVersion());

        self::assertSame(CorrectionStatus::Approved, $decided->getStatus());
        self::assertSame($notices + 1, $this->noticeCount());
        $day = $this->day('ana');
        self::assertTrue($day->has(DayFlag::Corrected));
        self::assertSame('2026-11-18T07:30:00+00:00', $day->segments[0]->start->format(DATE_ATOM));
        self::assertTrue($this->service(ChainVerifierInterface::class)->verify()->isIntact());
        self::assertContains('working_time.correction_approved', $this->recorder()->auditKeys());
    }

    public function testAStaleDecisionStoresNothingAndNotifiesNobody(): void
    {
        $request = $this->requestOnFirstSegment();
        $this->actAs('mia', 'manage_team');
        $this->corrections()->decide($request->getId(), false, null, $request->getVersion());
        $links   = (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM nowo_working_time_revision');
        $notices = $this->noticeCount();
        $this->ctx()->member('acme', 'leo', 'Leo');
        $this->actAs('leo', 'manage_team');

        try {
            $this->corrections()->decide($request->getId(), true, null, $request->getVersion());
            self::fail('A stale decision must be refused.');
        } catch (StaleCorrectionDecisionException $e) {
            self::assertSame('correction_decision_stale', $e::ERROR_CODE);
        }

        self::assertSame($links, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM nowo_working_time_revision'));
        self::assertSame($notices, $this->noticeCount());
        self::assertSame('rejected', $this->connection()->fetchOne('SELECT status FROM nowo_working_time_correction'));
    }

    public function testTwoManagersOnTheSameVersionStoreOneDecision(): void
    {
        $request = $this->requestOnFirstSegment();
        $this->ctx()->member('acme', 'leo', 'Leo');
        $this->actAs('mia', 'manage_team');
        $this->ctx()->grant('acme', 'leo', 'manage_team');
        $results = [];
        foreach (['mia', 'leo'] as $manager) {
            $this->ctx()->actor = $manager;
            try {
                $this->corrections()->decide($request->getId(), true, null, $request->getVersion());
                $results[] = 'stored';
            } catch (StaleCorrectionDecisionException) {
                $results[] = 'stale';
            }
        }

        self::assertSame(['stored', 'stale'], $results);
        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM nowo_working_time_revision WHERE kind = 'correction'"));
    }

    public function testNobodyDecidesOnTheirOwnDayOrAnotherTenantsRequest(): void
    {
        $request = $this->requestOnFirstSegment();
        $this->ctx()->grant('acme', 'ana', 'manage_team');
        $this->assertThrows(fn () => $this->corrections()->decide($request->getId(), true, null, $request->getVersion()), CorrectionRefusedException::class, 'correction.error.own_day');
        $this->actAs('mia', 'manage_team');
        $this->assertThrows(fn () => $this->corrections()->decide('01900000-0000-7000-8000-000000000999', true, null, 1), RegisterUnavailableException::class);
        $this->ctx()->tenant = 'other';
        $this->enable('other', ['mia']);
        $this->ctx()->grant('other', 'mia', 'manage_team');
        $this->assertThrows(fn () => $this->corrections()->decide($request->getId(), true, null, $request->getVersion()), RegisterUnavailableException::class);
        $this->ctx()->tenant = 'acme';
        $this->actAs('zoe');
        $this->ctx()->member('acme', 'zoe', 'Zoe');
        $this->assertThrows(fn () => $this->corrections()->decide($request->getId(), true, null, $request->getVersion()), PermissionDeniedException::class);
    }

    public function testAnAlreadyDecidedRequestOnItsNewVersionIsRefused(): void
    {
        $request = $this->requestOnFirstSegment();
        $this->actAs('mia', 'manage_team');
        $decided = $this->corrections()->decide($request->getId(), false, null, $request->getVersion());
        $this->assertThrows(fn () => $this->corrections()->decide($request->getId(), true, null, $decided->getVersion()), CorrectionRefusedException::class, 'correction.error.already_decided');
    }

    public function testAnAddedSegmentAndAVoidedOneAreApplied(): void
    {
        $this->bootWithDay();
        $day  = $this->day('ana');
        $add  = $this->corrections()->request(new LocalDate('2026-11-18'), new CorrectionInput(CorrectionOperation::Add, null, SegmentType::Work, new DateTimeImmutable('2026-11-18 06:00:00 UTC'), new DateTimeImmutable('2026-11-18 07:00:00 UTC'), 'Worked from home in the morning'));
        $void = $this->corrections()->request(new LocalDate('2026-11-18'), new CorrectionInput(CorrectionOperation::Void, $day->segments[1]->id, null, null, null, 'The break was not a break'));
        $this->actAs('mia', 'manage_team');
        $this->corrections()->decide($add->getId(), true, null, $add->getVersion());
        $this->corrections()->decide($void->getId(), true, null, $void->getVersion());
        $after = $this->day('ana');

        self::assertCount(4, $after->segments);
        self::assertTrue($after->segments[2]->voided);
        self::assertSame(SegmentType::Break, $after->segments[2]->type);
        self::assertTrue($this->service(ChainVerifierInterface::class)->verify()->isIntact());
    }

    public function testAManagerRecordsACorrectionAndTheWorkerContestsIt(): void
    {
        $this->bootWithDay();
        $this->actAs('mia', 'manage_team');
        $first    = $this->day('ana')->segments[0]->id;
        $recorded = $this->corrections()->record(new WorkerRef('ana'), new LocalDate('2026-11-18'), new CorrectionInput(CorrectionOperation::Change, $first, null, null, new DateTimeImmutable('2026-11-18 13:00:00 UTC'), 'Clock-out time from the door log'));
        self::assertTrue($this->day('ana')->has(DayFlag::PendingAcknowledgement));
        $this->assertThrows(fn () => $this->corrections()->record(new WorkerRef('mia'), new LocalDate('2026-11-18'), new CorrectionInput(CorrectionOperation::Void, $first, null, null, null, 'Own day test')), CorrectionRefusedException::class, 'correction.error.own_day');

        $this->ctx()->actor = 'ana';
        $this->assertThrows(fn () => $this->corrections()->answer($recorded->getId(), true, 'no'), CorrectionRefusedException::class, 'correction.error.reason');
        $this->corrections()->answer($recorded->getId(), true, 'I left at two, not at one');
        $day = $this->day('ana');
        self::assertTrue($day->has(DayFlag::Contested));
        self::assertFalse($day->has(DayFlag::PendingAcknowledgement));
        $this->assertThrows(fn () => $this->corrections()->answer($recorded->getId(), false, null), CorrectionRefusedException::class, 'correction.error.already_answered');
        $this->assertThrows(fn () => $this->corrections()->answer($day->revisions[0]->getId(), false, null), RegisterUnavailableException::class);
        self::assertContains('working_time.correction_contested', $this->recorder()->auditKeys());
    }

    public function testAWorkerAcknowledgesARecordedCorrection(): void
    {
        $this->bootWithDay();
        $this->actAs('mia', 'manage_team');
        $recorded           = $this->corrections()->record(new WorkerRef('ana'), new LocalDate('2026-11-17'), new CorrectionInput(CorrectionOperation::Add, null, SegmentType::Work, new DateTimeImmutable('2026-11-17 08:00:00 UTC'), new DateTimeImmutable('2026-11-17 16:00:00 UTC'), 'Worked but forgot the app'));
        $this->ctx()->actor = 'ana';
        $this->corrections()->answer($recorded->getId(), false, null);

        self::assertContains('working_time.correction_acknowledged', $this->recorder()->auditKeys());
        $this->assertThrows(fn () => $this->corrections()->record(new WorkerRef('ana'), new LocalDate('2026-11-30'), new CorrectionInput(CorrectionOperation::Void, 'x', null, null, null, 'Future day test')), PermissionDeniedException::class);
    }

    /**
     * @return iterable<string, array{CorrectionInput, string, string}>
     */
    public static function refusals(): iterable
    {
        yield 'future day' => [new CorrectionInput(CorrectionOperation::Void, 'x', null, null, null, 'Long enough reason'), '2026-11-19', 'correction.error.future'];
        yield 'outside the window' => [new CorrectionInput(CorrectionOperation::Void, 'x', null, null, null, 'Long enough reason'), '2026-09-01', 'correction.error.window'];
        yield 'short reason' => [new CorrectionInput(CorrectionOperation::Void, 'x', null, null, null, 'short'), '2026-11-18', 'correction.error.reason'];
        yield 'unknown segment' => [new CorrectionInput(CorrectionOperation::Void, 'x', null, null, null, 'Long enough reason'), '2026-11-18', 'correction.error.segment'];
        yield 'add without times' => [new CorrectionInput(CorrectionOperation::Add, null, SegmentType::Work, null, null, 'Long enough reason'), '2026-11-18', 'correction.error.times'];
        yield 'end before start' => [new CorrectionInput(CorrectionOperation::Add, null, SegmentType::Work, new DateTimeImmutable('2026-11-18 09:00:00 UTC'), new DateTimeImmutable('2026-11-18 08:00:00 UTC'), 'Long enough reason'), '2026-11-18', 'correction.error.times'];
        yield 'in the future' => [new CorrectionInput(CorrectionOperation::Add, null, SegmentType::Work, new DateTimeImmutable('2026-11-18 20:00:00 UTC'), new DateTimeImmutable('2026-11-19 21:00:00 UTC'), 'Long enough reason'), '2026-11-18', 'correction.error.times'];
    }

    #[DataProvider('refusals')]
    public function testRefusals(CorrectionInput $input, string $date, string $key): void
    {
        $this->bootWithDay();
        $this->assertThrows(fn () => $this->corrections()->request(new LocalDate($date), $input), CorrectionRefusedException::class, $key);
    }

    public function testTheLiveSegmentAndAChangeWithoutTimesAreRefused(): void
    {
        $this->boot();
        $this->enable();
        $this->actAs('ana', 'clock');
        $this->clock()->modify('-2 hours');
        $this->act(ClockAction::ClockIn);
        $this->act(ClockAction::BreakStart, 60);
        $this->clock()->modify('+30 minutes');
        $day = $this->day('ana');
        $this->assertThrows(fn () => $this->corrections()->request(new LocalDate('2026-11-18'), new CorrectionInput(CorrectionOperation::Change, $day->segments[1]->id, null, null, null, 'Long enough reason')), CorrectionRefusedException::class, 'correction.error.live_segment');
        $this->assertThrows(fn () => $this->corrections()->request(new LocalDate('2026-11-18'), new CorrectionInput(CorrectionOperation::Change, $day->segments[0]->id, null, null, null, 'Long enough reason')), CorrectionRefusedException::class, 'correction.error.times');
    }

    private function requestOnFirstSegment(): CorrectionRequest
    {
        $this->bootWithDay();
        $this->ctx()->member('acme', 'mia', 'Mia');
        $this->ctx()->manages['acme']['mia'] = ['ana'];
        $first                               = $this->day('ana')->segments[0]->id;

        return $this->corrections()->request(new LocalDate('2026-11-18'), new CorrectionInput(CorrectionOperation::Change, $first, null, new DateTimeImmutable('2026-11-18 07:30:00 UTC'), null, 'I came in earlier than clocked'));
    }

    private function bootWithDay(): void
    {
        $this->boot();
        $this->enable('acme', ['ana', 'mia']);
        $this->actAs('ana', 'clock');
        $this->clock()->modify('2026-11-18 08:00:00');
        $this->workDay();
    }

    private function day(string $worker): DayRecord
    {
        $days = $this->service(RegisterReaderInterface::class)->worker(new TenantRef('acme'), new WorkerRef($worker), new LocalDate('2026-11-18'), new LocalDate('2026-11-18'), $this->clock()->now());
        $this->em()->clear();

        return $days['2026-11-18'];
    }

    private function corrections(): CorrectionServiceInterface
    {
        return $this->service(CorrectionServiceInterface::class);
    }

    private function noticeCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM nowo_working_time_notice');
    }

    /**
     * @param callable(): mixed $call
     * @param class-string<Throwable> $class
     */
    private function assertThrows(callable $call, string $class, ?string $message = null): void
    {
        try {
            $call();
        } catch (Throwable $e) {
            self::assertInstanceOf($class, $e, $e->getMessage());
            if ($message !== null) {
                self::assertSame($message, $e->getMessage());
            }

            return;
        }
        self::fail($class . ' expected.');
    }
}
