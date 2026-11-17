<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Domain\Register\Read;

use Nowo\WorkingTimeBundle\Domain\Register\Enum\ClockAction;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ClockStatus;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\DayFlag;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\RevisionKind;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentSource;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentType;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkdayRevision;
use Nowo\WorkingTimeBundle\Domain\Register\Read\ClockState;
use Nowo\WorkingTimeBundle\Domain\Register\Read\DayRecord;
use Nowo\WorkingTimeBundle\Domain\Register\Read\EffectiveSegment;
use Nowo\WorkingTimeBundle\Domain\Register\Read\MonthSummary;
use Nowo\WorkingTimeBundle\Domain\Register\Read\RegisterReport;
use Nowo\WorkingTimeBundle\Domain\Register\Read\RegisterRules;
use Nowo\WorkingTimeBundle\Domain\Register\Read\VerificationReport;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Tests\Unit\Support\Fx;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * Read models: effective segments, clock state, month summary, reports.
 */
final class ReadModelsTest extends TestCase
{
    private function segment(string $start, ?string $end, bool $voided = false): EffectiveSegment
    {
        return new EffectiveSegment(Fx::uuid(1), SegmentType::Work, Fx::at($start), $end === null ? null : Fx::at($end), SegmentSource::Web, null, false, false, $voided, null, null);
    }

    public function testEffectiveSegmentSeconds(): void
    {
        $now = Fx::at('2026-11-10 12:00:00');

        self::assertSame(3600, $this->segment('2026-11-10 09:00:00', '2026-11-10 10:00:00')->seconds($now));
        self::assertSame(3 * 3600, $this->segment('2026-11-10 09:00:00', null)->seconds($now));
        self::assertSame(0, $this->segment('2026-11-10 09:00:00', '2026-11-10 10:00:00', true)->seconds($now));
        self::assertSame(0, $this->segment('2026-11-10 13:00:00', null)->seconds($now));
        self::assertTrue($this->segment('2026-11-10 09:00:00', null)->isOpen());
    }

    public function testEffectiveSegmentWith(): void
    {
        $segment = new EffectiveSegment(Fx::uuid(1), SegmentType::Break, Fx::at('2026-11-10 09:00:00'), null, SegmentSource::Desktop, null, true, false, false, Fx::at('2026-11-10 09:00:00'), null, '10.0.0.0/24', '10.0.1.0/24');

        $same = $segment->with(null, null, false);
        self::assertTrue($same->corrected);
        self::assertSame(SegmentSource::Desktop, $same->startSource);
        self::assertNull($same->endSource);
        self::assertTrue($same->late);
        self::assertSame('10.0.1.0/24', $same->endIpPrefix);
        self::assertFalse($same->voided);

        $changed = $segment->with(Fx::at('2026-11-10 08:00:00'), Fx::at('2026-11-10 10:00:00'), true);
        self::assertSame(SegmentSource::Correction, $changed->startSource);
        self::assertSame(SegmentSource::Correction, $changed->endSource);
        self::assertTrue($changed->voided);
        self::assertSame('09:00', $changed->originalStart?->format('H:i'));
        self::assertTrue($changed->with(null, null, false)->voided);
    }

    public function testClockStateActions(): void
    {
        $expected = [
            'idle'     => [[ClockAction::ClockIn], ClockAction::ClockIn],
            'closed'   => [[ClockAction::ClockIn], ClockAction::ClockIn],
            'working'  => [[ClockAction::BreakStart, ClockAction::ClockOut], ClockAction::ClockOut],
            'on_break' => [[ClockAction::BreakEnd, ClockAction::ClockOut], ClockAction::BreakEnd],
        ];

        foreach (ClockStatus::cases() as $status) {
            $state = new ClockState($status, new LocalDate('2026-11-10'), 'UTC', null, Fx::at('2026-11-10'));

            self::assertSame($expected[$status->value][0], $state->allowedActions(), $status->value);
            self::assertSame($expected[$status->value][1], $state->primaryAction(), $status->value);
            self::assertSame(0, $state->workedSeconds());
            self::assertSame(0, $state->breakSeconds());
            self::assertNull($state->reminder);
        }
    }

    public function testClockStateWithRecordAndReminder(): void
    {
        $record = new DayRecord(Fx::workday(), [], 100, 20, 120, [], [], [], []);
        $state  = new ClockState(ClockStatus::Working, new LocalDate('2026-11-10'), 'UTC', $record, Fx::at('2026-11-10'));
        $remind = $state->withReminder('out');

        self::assertSame(120, $state->workedSeconds());
        self::assertSame(20, $state->breakSeconds());
        self::assertSame('out', $remind->reminder);
        self::assertSame($record, $remind->record);
        self::assertNull($state->reminder);
        self::assertNull($remind->withReminder(null)->reminder);
    }

    public function testDayRecordHelpers(): void
    {
        $open   = $this->segment('2026-11-10 09:00:00', null);
        $closed = new EffectiveSegment(Fx::uuid(2), SegmentType::Work, Fx::at('2026-11-10 08:00:00'), Fx::at('2026-11-10 08:30:00'), SegmentSource::Web, SegmentSource::Web, false, false, false, null, null);
        $break  = new EffectiveSegment(Fx::uuid(3), SegmentType::Break, Fx::at('2026-11-10 07:00:00'), Fx::at('2026-11-10 20:00:00'), SegmentSource::Web, SegmentSource::Web, false, false, false, null, null);
        $voided = new EffectiveSegment(Fx::uuid(4), SegmentType::Work, Fx::at('2026-11-10 06:00:00'), Fx::at('2026-11-10 22:00:00'), SegmentSource::Web, SegmentSource::Web, false, false, true, null, null);

        $record = new DayRecord(Fx::workday(), [$voided, $break, $closed, $open], 0, 0, 30_000, [DayFlag::Overtime], [], [], []);

        self::assertSame($open, $record->openSegment());
        self::assertSame('08:00', $record->firstStart()?->format('H:i'));
        self::assertSame('20:00', $record->lastEnd()?->format('H:i'));
        self::assertSame(30_000 - 28_800, $record->overtimeSeconds());
        self::assertSame(['overtime'], $record->flagValues());

        $stale = new DayRecord(Fx::workday(), [$open], 0, 0, 0, [DayFlag::MissingClockOut], [], [], []);
        self::assertNull($stale->openSegment());
    }

    private function day(string $date, int $worked, ?int $weekly, bool $missing = false, bool $correction = false): DayRecord
    {
        $workday   = Fx::workday(Fx::schedule(weekly: $weekly), $date);
        $revisions = $correction ? [WorkdayRevision::append(Fx::uuid(70), 1, str_repeat('0', 64), $workday, RevisionKind::Correction, null, [], null, null, 'Ana', Fx::at('2026-11-10'), null), WorkdayRevision::append(Fx::uuid(71), 2, str_repeat('0', 64), $workday, RevisionKind::ClockIn, null, [], null, null, 'Ana', Fx::at('2026-11-10'), null)] : [];

        return new DayRecord($workday, [], $worked, 600, $worked, $missing ? [DayFlag::MissingClockOut] : [], $revisions, [], []);
    }

    public function testMonthSummary(): void
    {
        $summary = MonthSummary::of([
            '2026-11-09' => $this->day('2026-11-09', 36_000, 72_000, correction: true),
            '2026-11-10' => $this->day('2026-11-10', 36_000, 72_000, true),
            '2026-11-11' => $this->day('2026-11-11', 0, 72_000),
            '2026-11-16' => $this->day('2026-11-16', 3_600, 72_000),
            '2026-11-17' => $this->day('2026-11-17', 3_600, null),
        ]);

        self::assertSame(36_000 * 2 + 3_600 * 2, $summary->workedSeconds);
        self::assertSame(5 * 28_800, $summary->expectedSeconds);
        self::assertSame(2 * 7_200, $summary->dailyOvertimeSeconds);
        self::assertSame([], $summary->weeklyOvertime);
        self::assertSame(4, $summary->daysWorked);
        self::assertSame(1, $summary->missingClockOuts);
        self::assertSame(1, $summary->corrections);
        self::assertSame(5 * 600, $summary->breakSeconds);
    }

    public function testMonthSummaryWeeklyOvertime(): void
    {
        $summary = MonthSummary::of([
            '2026-11-09' => $this->day('2026-11-09', 40_000, 72_000),
            '2026-11-10' => $this->day('2026-11-10', 40_000, 72_000),
            '2026-11-16' => $this->day('2026-11-16', 3_600, 72_000),
        ]);

        self::assertSame(['2026-W46' => 8_000], $summary->weeklyOvertime);
        self::assertSame(8_000, $summary->weeklyOvertimeSeconds());
        self::assertSame(3, $summary->daysWorked);
        self::assertSame(0, MonthSummary::of([])->weeklyOvertimeSeconds());
    }

    public function testMonthSummaryHours(): void
    {
        self::assertSame('0:00', MonthSummary::hours(0));
        self::assertSame('0:00', MonthSummary::hours(-500));
        self::assertSame('1:01', MonthSummary::hours(3_660));
        self::assertSame('26:30', MonthSummary::hours(26 * 3600 + 1800 + 59));
    }

    public function testVerificationReport(): void
    {
        self::assertTrue((new VerificationReport(2, 10, []))->isIntact());

        $broken = new VerificationReport(2, 10, [['tenant' => 't', 'worker' => 'w', 'seq' => 2, 'reason' => 'hash']], ['w' => 'abc']);
        self::assertFalse($broken->isIntact());
        self::assertSame(['w' => 'abc'], $broken->heads);
    }

    public function testRegisterRulesDefaults(): void
    {
        $rules = new RegisterRules();

        self::assertSame(600, $rules->lateSeconds);
        self::assertSame(7_200, $rules->missingClockOutGraceSeconds);
        self::assertSame(57_600, $rules->longDaySeconds);
    }

    public function testRegisterReport(): void
    {
        $report = new RegisterReport('T', 'H', Fx::at('2026-11-10'), [['f', '1']], ['d'], [['r']], ['c'], [['x']], [['i', 'ok']], 'note', 'head', 'es');

        self::assertSame('T', $report->title);
        self::assertSame([], $report->labels);
        self::assertSame('es', $report->locale);
    }
}
