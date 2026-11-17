<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Infrastructure\Symfony;

use DateTimeZone;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\RevisionKind;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentSource;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentType;
use Nowo\WorkingTimeBundle\Domain\Register\Model\Workday;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkdayRevision;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkSegment;
use Nowo\WorkingTimeBundle\Domain\Register\Read\DayRecord;
use Nowo\WorkingTimeBundle\Domain\Register\Read\DayRecordBuilder;
use Nowo\WorkingTimeBundle\Domain\Shared\IpPrefix;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Export\RegisterCsv;
use Nowo\WorkingTimeBundle\Tests\Unit\Support\Fx;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function count;
use function str_repeat;
use function str_starts_with;

/**
 * CSV export of the register: rows, formula-injection guard, BOM, header and IP prefixes.
 */
final class RegisterCsvTest extends TestCase
{
    private function record(string $name = 'Ana', string $date = '2026-11-10'): DayRecord
    {
        $workday = Workday::open(Fx::uuid(1), new TenantRef('t1'), new WorkerRef('w1'), $name, new LocalDate($date), Fx::schedule(), Fx::at('2026-11-10 07:00:00'));

        $work = WorkSegment::start(Fx::uuid(2), $workday, SegmentType::Work, Fx::at($date . ' 09:00:00'), SegmentSource::Web, Fx::at($date . ' 09:00:00'), IpPrefix::fromIp('10.0.0.5'));
        $work->close(Fx::at($date . ' 17:00:00'), SegmentSource::Web, Fx::at($date . ' 17:00:00'), IpPrefix::fromIp('10.0.0.9'));
        $break = WorkSegment::start(Fx::uuid(3), $workday, SegmentType::Break, Fx::at($date . ' 13:00:00'), SegmentSource::Web, Fx::at($date . ' 13:00:00'), IpPrefix::fromIp('10.0.1.1'));
        $break->close(Fx::at($date . ' 13:30:00'), SegmentSource::Web, Fx::at($date . ' 13:30:00'), null);
        $voided = WorkSegment::start(Fx::uuid(4), $workday, SegmentType::Work, Fx::at($date . ' 05:00:00'), SegmentSource::Web, Fx::at($date . ' 05:00:00'), null);

        $correction = WorkdayRevision::append(Fx::uuid(5), 1, str_repeat('0', 64), $workday, RevisionKind::Correction, null, ['op' => 'void', 'segment' => Fx::uuid(4)], null, null, 'Boss', Fx::at($date . ' 18:00:00'), null);
        $amend      = WorkdayRevision::append(Fx::uuid(6), 2, $correction->getHash(), $workday, RevisionKind::Correction, null, ['op' => 'amend', 'segment' => Fx::uuid(2), 'end' => $date . 'T17:00:00Z'], null, null, 'Boss', Fx::at($date . ' 18:00:00'), null);

        return DayRecordBuilder::build($workday, [$work, $break, $voided], [$correction, $amend], [], Fx::at($date . ' 19:00:00'));
    }

    public function testRowsAreSortedByDateAndHideIpPrefixesByDefault(): void
    {
        $rows = RegisterCsv::rows('Acme', ['2026-11-11' => $this->record('Bea', '2026-11-11'), '2026-11-10' => $this->record()], false);

        self::assertCount(2, $rows);
        self::assertSame('2026-11-10', $rows[0][3]);
        self::assertSame('Acme', $rows[0][0]);
        self::assertSame('w1', $rows[0][1]);
        self::assertSame('Ana', $rows[0][2]);
        self::assertSame('UTC', $rows[0][4]);
        self::assertSame('09:00', $rows[0][5]);
        self::assertSame('17:00', $rows[0][6]);
        self::assertSame('09:00–17:00*, (b) 13:00–13:30', $rows[0][7]);
        self::assertSame(480, $rows[0][8]);
        self::assertSame(30, $rows[0][9]);
        self::assertSame(480, $rows[0][10]);
        self::assertSame(480, $rows[0][11]);
        self::assertSame(0, $rows[0][12]);
        self::assertSame('corrected', $rows[0][13]);
        self::assertSame(2, $rows[0][14]);
        self::assertSame('', $rows[0][15]);
        self::assertSame('', $rows[0][16]);
        self::assertCount(count(RegisterCsv::COLUMNS), $rows[0]);
    }

    public function testIpPrefixesOnlyWhenAsked(): void
    {
        $rows = RegisterCsv::rows('Acme', ['2026-11-10' => $this->record()], true);

        self::assertSame('10.0.0.0/24 10.0.1.0/24', $rows[0][15]);
        self::assertSame('', RegisterCsv::ipPrefixes(DayRecordBuilder::build(Fx::workday(), [], [], [], Fx::at('2026-11-10'))));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function cells(): iterable
    {
        yield 'equals' => ['=SUM(A1)', true];
        yield 'plus' => ['+1', true];
        yield 'minus' => ['-1', true];
        yield 'at' => ['@cmd', true];
        yield 'tab' => ["\tx", true];
        yield 'carriage return' => ["\rx", true];
        yield 'plain' => ['Ana', false];
        yield 'inner equals' => ['a=b', false];
    }

    #[DataProvider('cells')]
    public function testFormulaInjectionGuard(string $cell, bool $prefixed): void
    {
        $csv   = RegisterCsv::encode([[$cell, 5, 'x', 'x', 'x', 'x', 'x', 'x', 1, 1, 1, 1, 1, 'x', 1, 'x', 'x']]);
        $lines = explode("\n", $csv);

        if ($prefixed) {
            self::assertStringContainsString("'" . $cell, $csv);
        } else {
            self::assertStringStartsWith($cell . ',5,', $lines[1]);
        }
    }

    public function testEncodeStartsWithABomAndTheHeader(): void
    {
        $csv = RegisterCsv::encode([]);

        self::assertTrue(str_starts_with($csv, "\u{FEFF}organization,worker_id,worker_name,date,timezone,"));
        self::assertStringEndsWith(",ip_prefixes,leave\n", $csv);
        self::assertCount(17, RegisterCsv::COLUMNS);
    }

    public function testLocal(): void
    {
        $zone = new DateTimeZone('Europe/Madrid');

        self::assertSame('10:00', RegisterCsv::local(Fx::at('2026-11-10 09:00:00'), $zone));
        self::assertSame('2026-11-10', RegisterCsv::local(Fx::at('2026-11-10 09:00:00'), $zone, 'Y-m-d'));
        self::assertSame('', RegisterCsv::local(null, $zone));
    }

    public function testSegmentsRenderOpenAndBreakSegments(): void
    {
        $workday = Fx::workday();
        $open    = WorkSegment::start(Fx::uuid(2), $workday, SegmentType::Work, Fx::at('2026-11-10 09:00:00'), SegmentSource::Web, Fx::at('2026-11-10 09:00:00'), null);
        $record  = DayRecordBuilder::build($workday, [$open], [], [], Fx::at('2026-11-10 10:00:00'));

        self::assertSame('09:00–', RegisterCsv::segments($record, new DateTimeZone('UTC')));
    }
}
