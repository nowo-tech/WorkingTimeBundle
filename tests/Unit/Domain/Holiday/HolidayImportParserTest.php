<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Domain\Holiday;

use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\HolidayOrigin;
use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\HolidayScope;
use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\ImportStatus;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayEntry;
use Nowo\WorkingTimeBundle\Domain\Holiday\Import\HolidayImportParser;
use Nowo\WorkingTimeBundle\Domain\Holiday\ImportPreviewRow;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use PHPUnit\Framework\TestCase;

use function array_map;
use function str_repeat;

/**
 * CSV / ICS parsing for the import preview (contract section 9.2; spec 204 FR-008): statuses, separators, the BOM,
 * the header, the row and size limits, all-day ICS events; and the provider compare (Beacon's sync statuses).
 */
final class HolidayImportParserTest extends TestCase
{
    public function testACsvFileGetsOneStatusPerRow(): void
    {
        $csv     = "\xEF\xBB\xBFdate,name,scope\n2026-01-01,Año Nuevo,national\n20260106,Reyes,\n2026-05-02,Fiesta regional,autonomous\n02/04/2026,Jueves Santo,national\n2026-01-01,Otra vez,national\n2026-02-30,Mala fecha,national\n2027-01-01,Otro año,national\n2026-03-19,,national\n\n2026-08-15,Asunción,national\n";
        $preview = HolidayImportParser::parse($csv, 'festivos.csv', 2026, HolidayScope::National, ['2026-08-15']);
        $byRow   = array_map(static fn (ImportPreviewRow $row): array => [$row->date, $row->status], $preview->rows);

        self::assertNull($preview->error);
        self::assertSame([
            ['2026-01-01', ImportStatus::New],
            ['2026-01-06', ImportStatus::New],
            ['2026-05-02', ImportStatus::OtherScope],
            ['2026-04-02', ImportStatus::New],
            ['2026-01-01', ImportStatus::Duplicate],
            ['2026-02-30', ImportStatus::Invalid],
            ['2027-01-01', ImportStatus::Invalid],
            ['2026-03-19', ImportStatus::Invalid],
            ['2026-08-15', ImportStatus::Duplicate],
        ], $byRow);
        self::assertSame('Año Nuevo', $preview->rows[0]->name);
        self::assertSame(HolidayScope::National, $preview->rows[0]->scope);
    }

    public function testSemicolonsQuotesAndColumnsInAnyOrder(): void
    {
        $preview = HolidayImportParser::parse("Name;Date\r\n\"Fiesta; local\";2026-09-08\r\n", 'x.csv', 2026, HolidayScope::Local, []);

        self::assertSame('Fiesta; local', $preview->rows[0]->name);
        self::assertSame(ImportStatus::New, $preview->rows[0]->status);
    }

    public function testAnIcsCalendar(): void
    {
        $ics     = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20260101\r\nSUMMARY:Año\r\n  Nuevo\\, festivo\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nDTSTART:20260106T090000Z\r\nSUMMARY:Timed\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nSUMMARY:No date\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $preview = HolidayImportParser::parse($ics, 'calendar.ics', 2026, HolidayScope::National, []);

        self::assertSame('Año Nuevo, festivo', $preview->rows[0]->name);
        self::assertSame(ImportStatus::New, $preview->rows[0]->status);
        self::assertSame(ImportStatus::Invalid, $preview->rows[1]->status, 'a timed event is not an all-day holiday');
        self::assertSame(ImportStatus::Invalid, $preview->rows[2]->status);
        self::assertSame(ImportStatus::New, HolidayImportParser::parse("BEGIN:VCALENDAR\nBEGIN:VEVENT\nDTSTART:20260101\nSUMMARY:X\nEND:VEVENT", 'no-extension', 2026, HolidayScope::National, [])->rows[0]->status, 'detected by its content');
    }

    public function testWholeFileRefusals(): void
    {
        self::assertSame('holiday.import.error.file', HolidayImportParser::parse(str_repeat('a', 11), 'x.csv', 2026, HolidayScope::National, [], 200, 10)->error);
        self::assertSame('holiday.import.error.file', HolidayImportParser::parse("\xFF\xFE", 'x.csv', 2026, HolidayScope::National, [])->error);
        self::assertSame('holiday.import.error.header', HolidayImportParser::parse("day,label\n2026-01-01,X", 'x.csv', 2026, HolidayScope::National, [])->error);
        self::assertSame('holiday.import.error.too_many', HolidayImportParser::parse("date,name\n2026-01-01,A\n2026-01-02,B\n2026-01-03,C", 'x.csv', 2026, HolidayScope::National, [], 2)->error);
        self::assertSame('holiday.import.error.empty', HolidayImportParser::parse("date,name\n", 'x.csv', 2026, HolidayScope::National, [])->error);
        self::assertSame('holiday.import.error.empty', HolidayImportParser::parse('BEGIN:VCALENDAR', 'x.ics', 2026, HolidayScope::National, [])->error);
        self::assertNull(HolidayImportParser::date('2026-1-1'));
        self::assertSame('2026-12-31', HolidayImportParser::date(' 31/12/2026 ')?->format('Y-m-d'));
    }

    public function testAProviderImportComparesWithTheCalendar(): void
    {
        $day      = static fn (string $date, string $name, HolidayOrigin $origin): HolidayEntry => new HolidayEntry(new LocalDate($date), $name, HolidayScope::Local, $origin);
        $existing = [
            $day('2026-01-01', 'Año Nuevo', HolidayOrigin::FestivosIo),
            $day('2026-05-15', 'San Isidro', HolidayOrigin::Manual),
            $day('2026-11-10', 'Gone', HolidayOrigin::FestivosIo),
            $day('2026-12-24', 'Nochebuena', HolidayOrigin::OpenHolidays),
        ];
        $fetched = [
            new HolidayEntry(new LocalDate('2026-01-01'), 'Año Nuevo', HolidayScope::National, HolidayOrigin::FestivosIo, 'BOE-1'),
            new HolidayEntry(new LocalDate('2026-05-15'), 'San Isidro Labrador', HolidayScope::Local, HolidayOrigin::FestivosIo),
            new HolidayEntry(new LocalDate('2026-11-09'), 'Almudena', HolidayScope::Local, HolidayOrigin::FestivosIo, 'BOCM-2', 'https://www.bocm.es/2'),
        ];
        $rows = HolidayImportParser::compare($fetched, $existing, HolidayOrigin::FestivosIo);

        self::assertSame([
            ['2026-01-01', ImportStatus::Unchanged],
            ['2026-05-15', ImportStatus::Duplicate],
            ['2026-11-09', ImportStatus::New],
            ['2026-11-10', ImportStatus::Removed],
        ], array_map(static fn (ImportPreviewRow $row): array => [$row->date, $row->status], $rows));
        self::assertSame('https://www.bocm.es/2', $rows[2]->sourceUrl);
        self::assertSame('BOE-1', $rows[0]->sourceRef);
    }
}
