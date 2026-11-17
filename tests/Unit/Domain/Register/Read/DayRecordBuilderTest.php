<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Domain\Register\Read;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\CorrectionOperation;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\DayFlag;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\RevisionKind;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentSource;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentType;
use Nowo\WorkingTimeBundle\Domain\Register\Model\CorrectionRequest;
use Nowo\WorkingTimeBundle\Domain\Register\Model\DaySchedule;
use Nowo\WorkingTimeBundle\Domain\Register\Model\Workday;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkdayRevision;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkSegment;
use Nowo\WorkingTimeBundle\Domain\Register\Read\DayRecordBuilder;
use Nowo\WorkingTimeBundle\Domain\Register\Read\RegisterRules;
use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Tests\Unit\Support\Fx;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * Derivation of a day record (effective segments, totals and flags) from stored segments, revisions and requests.
 */
final class DayRecordBuilderTest extends TestCase
{
    private int $counter = 100;

    private function segment(Workday $workday, int $n, SegmentType $type, string $start, ?string $end, ?string $receivedStart = null, ?string $receivedEnd = null): WorkSegment
    {
        $segment = WorkSegment::start(Fx::uuid($n), $workday, $type, Fx::at($start), SegmentSource::Web, Fx::at($receivedStart ?? $start), null);
        if ($end !== null) {
            $segment->close(Fx::at($end), SegmentSource::Web, Fx::at($receivedEnd ?? $end), null);
        }

        return $segment;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function revision(Workday $workday, RevisionKind $kind, array $payload): WorkdayRevision
    {
        ++$this->counter;

        return WorkdayRevision::append(Fx::uuid($this->counter), $this->counter, str_repeat('0', 64), $workday, $kind, null, $payload, null, new ActorRef('u'), 'Ana', Fx::at('2026-11-10 18:00:00'), null);
    }

    /**
     * @param list<DayFlag> $flags
     *
     * @return list<string>
     */
    private function values(array $flags): array
    {
        return array_map(static fn (DayFlag $f): string => $f->value, $flags);
    }

    public function testNormalDayTotals(): void
    {
        $workday = Fx::workday(Fx::schedule(flex: 30));
        $record  = DayRecordBuilder::build($workday, [
            $this->segment($workday, 3, SegmentType::Work, '2026-11-10 13:20:00', '2026-11-10 17:00:00'),
            $this->segment($workday, 1, SegmentType::Work, '2026-11-10 09:00:00', '2026-11-10 13:00:00'),
            $this->segment($workday, 2, SegmentType::Break, '2026-11-10 13:00:00', '2026-11-10 13:20:00'),
        ], [], [], Fx::at('2026-11-10 18:00:00'));

        self::assertSame(4 * 3600 + 3 * 3600 + 2400, $record->workSeconds);
        self::assertSame(1200, $record->breakSeconds);
        self::assertSame($record->workSeconds, $record->workedSeconds);
        self::assertSame([], $record->flags);
        self::assertSame(Fx::uuid(1), $record->segments[0]->id);
        self::assertSame(0, $record->overtimeSeconds());
        self::assertSame('09:00', $record->firstStart()?->format('H:i'));
        self::assertSame('17:00', $record->lastEnd()?->format('H:i'));
        self::assertNull($record->openSegment());
    }

    public function testBreaksCountedAddToTheWorkedTime(): void
    {
        $workday = Fx::workday(Fx::schedule(breaksCounted: true));
        $record  = DayRecordBuilder::build($workday, [
            $this->segment($workday, 1, SegmentType::Work, '2026-11-10 09:00:00', '2026-11-10 12:00:00'),
            $this->segment($workday, 2, SegmentType::Break, '2026-11-10 12:00:00', '2026-11-10 12:30:00'),
        ], [], [], Fx::at('2026-11-10 18:00:00'));

        self::assertSame(3 * 3600, $record->workSeconds);
        self::assertSame(3 * 3600 + 1800, $record->workedSeconds);
    }

    public function testOvertimeAndShortBreakAndLongDay(): void
    {
        $workday = Fx::workday(Fx::schedule(minBreak: 30));
        $record  = DayRecordBuilder::build($workday, [
            $this->segment($workday, 1, SegmentType::Work, '2026-11-10 06:00:00', '2026-11-10 13:00:00'),
            $this->segment($workday, 2, SegmentType::Break, '2026-11-10 13:00:00', '2026-11-10 13:10:00'),
            $this->segment($workday, 3, SegmentType::Work, '2026-11-10 13:10:00', '2026-11-10 23:30:00'),
        ], [], [], Fx::at('2026-11-11 08:00:00'));

        self::assertSame(['overtime', 'long_day', 'short_break'], $this->values($record->flags));
        self::assertGreaterThan(0, $record->overtimeSeconds());
        self::assertSame(['overtime', 'long_day', 'short_break'], $record->flagValues());
    }

    public function testLateEntry(): void
    {
        $workday = Fx::workday();
        $record  = DayRecordBuilder::build($workday, [
            $this->segment($workday, 1, SegmentType::Work, '2026-11-10 09:00:00', '2026-11-10 17:00:00', '2026-11-10 09:30:00'),
        ], [], [], Fx::at('2026-11-10 18:00:00'));

        self::assertSame(['late_entry'], $this->values($record->flags));
        self::assertTrue($record->has(DayFlag::LateEntry));
        self::assertTrue($record->segments[0]->late);
    }

    public function testVoidedSegmentsDoNotMakeAFlagLate(): void
    {
        $workday = Fx::workday();
        $record  = DayRecordBuilder::build($workday, [
            $this->segment($workday, 1, SegmentType::Work, '2026-11-10 09:00:00', '2026-11-10 17:00:00'),
            $this->segment($workday, 2, SegmentType::Work, '2026-11-10 08:00:00', '2026-11-10 08:30:00', '2026-11-10 09:30:00'),
        ], [$this->revision($workday, RevisionKind::Correction, ['op' => 'void', 'segment' => Fx::uuid(2)])], [], Fx::at('2026-11-10 18:00:00'));

        self::assertSame(['corrected'], $this->values($record->flags));
        self::assertSame(8 * 3600, $record->workSeconds);
        self::assertSame('09:00', $record->firstStart()?->format('H:i'));
    }

    public function testOutsideBand(): void
    {
        $workday = Fx::workday(Fx::schedule(flex: 30));
        $record  = DayRecordBuilder::build($workday, [
            $this->segment($workday, 1, SegmentType::Work, '2026-11-10 10:00:00', '2026-11-10 17:00:00'),
        ], [], [], Fx::at('2026-11-10 18:00:00'));

        self::assertSame(['outside_band'], $this->values($record->flags));
    }

    public function testNoFlexMeansNoBand(): void
    {
        $workday = Fx::workday();
        $record  = DayRecordBuilder::build($workday, [
            $this->segment($workday, 1, SegmentType::Work, '2026-11-10 10:00:00', '2026-11-10 17:00:00'),
        ], [], [], Fx::at('2026-11-10 18:00:00'));

        self::assertSame([], $record->flags);
    }

    public function testMissingClockOutAfterTheScheduleEndAndGrace(): void
    {
        $workday = Fx::workday();
        $open    = [$this->segment($workday, 1, SegmentType::Work, '2026-11-10 09:00:00', null)];

        $stale = DayRecordBuilder::build($workday, $open, [], [], Fx::at('2026-11-10 19:00:01'));
        self::assertSame(['missing_clock_out'], $this->values($stale->flags));
        self::assertSame(0, $stale->workSeconds);
        self::assertNull($stale->openSegment());

        $running = DayRecordBuilder::build($workday, $open, [], [], Fx::at('2026-11-10 12:00:00'));
        self::assertSame([], $running->flags);
        self::assertSame(3 * 3600, $running->workSeconds);
        self::assertSame(Fx::uuid(1), $running->openSegment()?->id);

        self::assertFalse(DayRecordBuilder::staleOpen($workday, $running->segments, Fx::at('2026-11-10 19:00:00')));
        self::assertTrue(DayRecordBuilder::staleOpen($workday, $running->segments, Fx::at('2026-11-10 19:00:01')));
    }

    public function testMissingClockOutByALongDay(): void
    {
        $workday = Fx::workday(Fx::schedule([], 0));
        $open    = [$this->segment($workday, 1, SegmentType::Work, '2026-11-10 06:00:00', null)];

        $record = DayRecordBuilder::build($workday, $open, [], [], Fx::at('2026-11-11 00:00:01'));
        self::assertSame(['missing_clock_out'], $this->values($record->flags));

        $young = DayRecordBuilder::build($workday, $open, [], [], Fx::at('2026-11-10 20:00:00'));
        self::assertSame([], $young->flags);
    }

    public function testAnOpenSegmentStartedAfterTheScheduleEndIsNeverStaleBySchedule(): void
    {
        $workday = Fx::workday();
        $open    = [$this->segment($workday, 1, SegmentType::Work, '2026-11-10 18:00:00', null)];

        self::assertFalse(DayRecordBuilder::staleOpen($workday, array_values(DayRecordBuilder::build($workday, $open, [], [], Fx::at('2026-11-10 23:00:00'))->segments), Fx::at('2026-11-10 23:00:00')));
    }

    public function testStaleOpenIgnoresClosedAndVoidedDays(): void
    {
        $workday = Fx::workday();
        $closed  = DayRecordBuilder::build($workday, [$this->segment($workday, 1, SegmentType::Work, '2026-11-10 09:00:00', '2026-11-10 10:00:00')], [], [], Fx::at('2026-11-12'));
        self::assertFalse(DayRecordBuilder::staleOpen($workday, $closed->segments, Fx::at('2026-11-12')));
        self::assertFalse(DayRecordBuilder::staleOpen($workday, [], Fx::at('2026-11-12')));

        $voided = DayRecordBuilder::build($workday, [$this->segment($workday, 1, SegmentType::Work, '2026-11-10 09:00:00', null)], [$this->revision($workday, RevisionKind::Correction, ['op' => 'void', 'segment' => Fx::uuid(1)])], [], Fx::at('2026-11-12'));
        self::assertFalse(DayRecordBuilder::staleOpen($workday, $voided->segments, Fx::at('2026-11-12')));
        self::assertNull($voided->firstStart());
    }

    public function testCorrectionsAddChangeAndVoid(): void
    {
        $workday = Fx::workday();
        $record  = DayRecordBuilder::build($workday, [
            $this->segment($workday, 1, SegmentType::Work, '2026-11-10 09:00:00', '2026-11-10 12:00:00'),
            $this->segment($workday, 2, SegmentType::Work, '2026-11-10 13:00:00', '2026-11-10 14:00:00'),
        ], [
            $this->revision($workday, RevisionKind::Correction, ['op' => 'amend', 'segment' => Fx::uuid(1), 'start' => '2026-11-10T08:00:00Z', 'end' => '2026-11-10T12:30:00+00:00']),
            $this->revision($workday, RevisionKind::RecordedCorrection, ['op' => 'add', 'segment' => Fx::uuid(3), 'start' => '2026-11-10T15:00:00Z', 'end' => '2026-11-10T16:00:00Z', 'type' => 'break']),
            $this->revision($workday, RevisionKind::Correction, ['op' => 'add', 'segment' => Fx::uuid(4), 'start' => '2026-11-10T16:00:00Z']),
            $this->revision($workday, RevisionKind::Correction, ['op' => 'add', 'segment' => Fx::uuid(5), 'end' => '2026-11-10T16:00:00Z']),
            $this->revision($workday, RevisionKind::Correction, ['op' => 'void', 'segment' => Fx::uuid(2)]),
            $this->revision($workday, RevisionKind::Correction, ['op' => 'amend', 'segment' => Fx::uuid(99), 'start' => '2026-11-10T01:00:00Z']),
            $this->revision($workday, RevisionKind::Correction, ['op' => 'weird', 'segment' => Fx::uuid(1)]),
            $this->revision($workday, RevisionKind::Correction, []),
        ], [], Fx::at('2026-11-10 16:30:00'));

        self::assertCount(4, $record->segments);
        $first = $record->segments[0];
        self::assertSame(Fx::uuid(1), $first->id);
        self::assertTrue($first->corrected);
        self::assertSame('08:00', $first->start->format('H:i'));
        self::assertSame('12:30', $first->end?->format('H:i'));
        self::assertSame(SegmentSource::Correction, $first->startSource);
        self::assertSame('09:00', $first->originalStart?->format('H:i'));
        self::assertSame(4 * 3600 + 1800 + 1800, $record->workSeconds);
        self::assertSame(3600, $record->breakSeconds);
        self::assertContains(DayFlag::Corrected, $record->flags);
        self::assertContains(DayFlag::PendingAcknowledgement, $record->flags);
        self::assertSame([Fx::uuid(102)], $record->pendingAcknowledgements);

        $added = null;
        foreach ($record->segments as $segment) {
            if ($segment->id === Fx::uuid(4)) {
                $added = $segment;
            }
        }
        self::assertNotNull($added);
        self::assertTrue($added->isOpen());
        self::assertSame(SegmentType::Work, $added->type);
        self::assertNull($added->endSource);
    }

    public function testAcknowledgementsAnswerRecordedCorrections(): void
    {
        $workday  = Fx::workday();
        $recorded = $this->revision($workday, RevisionKind::RecordedCorrection, ['op' => 'add', 'segment' => Fx::uuid(3), 'start' => '2026-11-10T10:00:00Z', 'end' => '2026-11-10T11:00:00Z']);
        $other    = $this->revision($workday, RevisionKind::RecordedCorrection, ['op' => 'add', 'segment' => Fx::uuid(4), 'start' => '2026-11-10T11:00:00Z', 'end' => '2026-11-10T12:00:00Z']);

        $pending = DayRecordBuilder::build($workday, [], [$recorded, $other, $this->revision($workday, RevisionKind::Acknowledged, ['revision' => $recorded->getId()])], [], Fx::at('2026-11-10 18:00:00'));
        self::assertSame([$other->getId()], $pending->pendingAcknowledgements);
        self::assertNotContains(DayFlag::Contested, $pending->flags);

        $contested = DayRecordBuilder::build($workday, [], [$recorded, $this->revision($workday, RevisionKind::Contested, ['revision' => $recorded->getId()]), $this->revision($workday, RevisionKind::Acknowledged, ['revision' => 5])], [], Fx::at('2026-11-10 18:00:00'));
        self::assertSame([], $contested->pendingAcknowledgements);
        self::assertSame(['corrected', 'contested'], $this->values($contested->flags));
    }

    public function testPendingRequestsFlagTheDay(): void
    {
        $workday = Fx::workday();
        $request = CorrectionRequest::file(Fx::uuid(60), $workday, CorrectionOperation::Void, Fx::uuid(1), null, null, null, 'entered by mistake', Fx::at('2026-11-11'));

        $pending = DayRecordBuilder::build($workday, [], [], [$request], Fx::at('2026-11-12'));
        self::assertSame(['correction_pending'], $this->values($pending->flags));

        $request->reject(new ActorRef('boss'), 'Boss', null, Fx::at('2026-11-12'));
        $decided = DayRecordBuilder::build($workday, [], [], [$request], Fx::at('2026-11-12'));
        self::assertSame([], $decided->flags);
    }

    public function testEmptyDay(): void
    {
        $record = DayRecordBuilder::build(Fx::workday(), [], [], [], Fx::at('2026-11-10 12:00:00'));

        self::assertSame(0, $record->workedSeconds);
        self::assertNull($record->firstStart());
        self::assertNull($record->lastEnd());
        self::assertNull($record->openSegment());
        self::assertSame([], $record->flags);
    }

    public function testNoThresholdMeansNoOvertime(): void
    {
        $workday = Workday::open(Fx::uuid(1), new TenantRef('t'), new WorkerRef('w'), 'Ana', new LocalDate('2026-11-10'), new DaySchedule('UTC'), Fx::at('2026-11-10'));
        $record  = DayRecordBuilder::build($workday, [$this->segment($workday, 1, SegmentType::Work, '2026-11-10 00:00:00', '2026-11-10 12:00:00')], [], [], Fx::at('2026-11-11'));

        self::assertSame(0, $record->overtimeSeconds());
        self::assertSame([], $record->flags);
    }

    public function testCustomRules(): void
    {
        $workday = Fx::workday();
        $record  = DayRecordBuilder::build($workday, [
            $this->segment($workday, 1, SegmentType::Work, '2026-11-10 09:00:00', '2026-11-10 17:00:00', '2026-11-10 09:01:00'),
        ], [], [], Fx::at('2026-11-10 18:00:00'), new RegisterRules(lateSeconds: 30, longDaySeconds: 3600));

        self::assertSame(['late_entry', 'long_day'], $this->values($record->flags));
    }

    /**
     * @return iterable<string, array{mixed, ?string}>
     */
    public static function times(): iterable
    {
        yield 'iso zulu' => ['2026-11-10T08:00:00Z', '2026-11-10 08:00:00'];
        yield 'offset' => ['2026-11-10T10:00:00+02:00', '2026-11-10 08:00:00'];
        yield 'empty' => ['', null];
        yield 'not a string' => [123, null];
        yield 'null' => [null, null];
        yield 'garbage' => ['not a date at all', null];
    }

    #[DataProvider('times')]
    public function testTime(mixed $value, ?string $expected): void
    {
        $time = DayRecordBuilder::time($value);

        self::assertSame($expected, $time instanceof DateTimeImmutable ? $time->format('Y-m-d H:i:s') : null);
        if ($time instanceof DateTimeImmutable) {
            self::assertSame('UTC', $time->getTimezone()->getName());
        }
    }
}
