<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Holiday\Import;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\HolidayOrigin;
use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\HolidayScope;
use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\ImportStatus;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayEntry;
use Nowo\WorkingTimeBundle\Domain\Holiday\ImportPreview;
use Nowo\WorkingTimeBundle\Domain\Holiday\ImportPreviewRow;

use function array_fill_keys;
use function array_search;
use function array_shift;
use function count;
use function ltrim;
use function mb_check_encoding;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function preg_split;
use function str_contains;
use function str_ends_with;
use function str_getcsv;
use function str_replace;
use function strlen;
use function strtolower;
use function substr_count;
use function trim;
use function usort;

/**
 * Reads an official holiday list for the import preview (contract section 9.2; Beacon's `HolidayImportParser`): CSV
 * (`date,name,scope`, `,` or `;`, UTF-8, BOM stripped, a header row) or ICS (each `VEVENT` with an all-day `DTSTART`
 * and a `SUMMARY`), within the configured row and size limits. Pure: each row comes back with its status, and
 * {@see self::compare()} gives the preview of a provider import against the calendar.
 */
final class HolidayImportParser
{
    /** Default row limit (`holidays.providers.file.max_rows`). */
    public const MAX_ROWS = 200;

    /** Default size limit in bytes (`holidays.providers.file.max_bytes`). */
    public const MAX_BYTES = 262144;

    /**
     * The preview of a file against the calendar's year, scope and existing dates.
     *
     * @param list<string> $existing `Y-m-d` dates already in the calendar
     */
    public static function parse(string $content, string $filename, int $year, HolidayScope $scope, array $existing, int $maxRows = self::MAX_ROWS, int $maxBytes = self::MAX_BYTES): ImportPreview
    {
        if (strlen($content) > $maxBytes || !mb_check_encoding($content, 'UTF-8')) {
            return new ImportPreview([], 'holiday.import.error.file');
        }
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $ics     = str_ends_with(strtolower($filename), '.ics') || str_contains($content, 'BEGIN:VCALENDAR');
        $raw     = $ics ? self::ics($content) : self::csv($content);
        if ($raw === null) {
            return new ImportPreview([], 'holiday.import.error.header');
        }
        if (count($raw) > $maxRows) {
            return new ImportPreview([], 'holiday.import.error.too_many');
        }
        $seen = array_fill_keys($existing, true);
        $rows = [];
        foreach ($raw as [$date, $name, $rowScope]) {
            $parsed = self::date($date);
            $clean  = HolidayEntry::cleanName($name);
            $status = ImportStatus::New;
            if (!$parsed instanceof DateTimeImmutable || $clean === null || (int) $parsed->format('Y') !== $year) {
                $status = ImportStatus::Invalid;
            } elseif ($rowScope !== null && trim($rowScope) !== '' && HolidayScope::fromImport($rowScope) !== $scope) {
                $status = ImportStatus::OtherScope;
            } elseif (isset($seen[$parsed->format('Y-m-d')])) {
                $status = ImportStatus::Duplicate;
            }
            $key = $parsed?->format('Y-m-d') ?? trim($date);
            if ($status === ImportStatus::New) {
                $seen[$key] = true;
            }
            $rows[] = new ImportPreviewRow($key, $clean ?? '', $status, $scope);
        }

        return new ImportPreview($rows, $rows === [] ? 'holiday.import.error.empty' : null);
    }

    /**
     * The preview of a provider import against the calendar's days (Beacon's sync): `new`, `unchanged` (an earlier day
     * of the same origin, kept), `duplicate` (a day of another origin on that date: kept, not replaced) and `removed`
     * (a day of the same origin the provider no longer lists).
     *
     * @param list<HolidayEntry> $fetched one per date
     * @param list<HolidayEntry> $existing the calendar's days
     * @param string|null $provider the provider key (tells host providers, all of origin `provider`, apart)
     *
     * @return list<ImportPreviewRow> by date
     */
    public static function compare(array $fetched, array $existing, HolidayOrigin $origin, ?string $provider = null): array
    {
        $byDate = [];
        foreach ($existing as $day) {
            $byDate[$day->date->value] = $day;
        }
        $rows   = [];
        $listed = [];
        foreach ($fetched as $entry) {
            $listed[$entry->date->value] = true;
            $day                         = $byDate[$entry->date->value] ?? null;
            $status                      = $day === null ? ImportStatus::New : ($day->isFrom($origin, $provider) ? ImportStatus::Unchanged : ImportStatus::Duplicate);
            $rows[]                      = new ImportPreviewRow($entry->date->value, $entry->name, $status, $entry->scope, $entry->sourceRef, $entry->sourceUrl);
        }
        foreach ($existing as $day) {
            if ($day->isFrom($origin, $provider) && !isset($listed[$day->date->value])) {
                $rows[] = new ImportPreviewRow($day->date->value, $day->name, ImportStatus::Removed, $day->scope);
            }
        }
        usort($rows, static fn (ImportPreviewRow $a, ImportPreviewRow $b): int => $a->date <=> $b->date);

        return $rows;
    }

    /**
     * A strict date in one of the accepted forms (`Y-m-d`, `Ymd`, `d/m/Y`), or null.
     */
    public static function date(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        foreach (['!Y-m-d', '!Ymd', '!d/m/Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date instanceof DateTimeImmutable && $date->format(ltrim($format, '!')) === $value) {
                return $date;
            }
        }

        return null;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string|null}>|null null = no `date,name` header
     */
    private static function csv(string $content): ?array
    {
        $lines     = preg_split('/\r\n|\n|\r/', trim($content)) ?: [];
        $header    = (string) array_shift($lines);
        $separator = substr_count($header, ';') > substr_count($header, ',') ? ';' : ',';
        $columns   = [];
        foreach (str_getcsv($header, $separator, '"', '') as $column) {
            $columns[] = strtolower(trim((string) $column));
        }
        $dateAt  = array_search('date', $columns, true);
        $nameAt  = array_search('name', $columns, true);
        $scopeAt = array_search('scope', $columns, true);
        if ($dateAt === false || $nameAt === false) {
            return null;
        }
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells  = str_getcsv($line, $separator, '"', '');
            $rows[] = [$cells[$dateAt] ?? '', $cells[$nameAt] ?? '', $scopeAt === false ? null : $cells[$scopeAt] ?? ''];
        }

        return $rows;
    }

    /**
     * @return list<array{0: string, 1: string, 2: null}>
     */
    private static function ics(string $content): array
    {
        // Unfold continuation lines (RFC 5545 section 3.1).
        $content = (string) preg_replace('/\r?\n[ \t]/', '', $content);
        $rows    = [];
        preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $content, $events);
        foreach ($events[1] as $event) {
            $date    = preg_match('/^DTSTART[^:\r\n]*:(\d{8})(?!T)/m', $event, $m) === 1 ? $m[1] : '';
            $summary = preg_match('/^SUMMARY[^:\r\n]*:(.*)$/m', $event, $s) === 1 ? str_replace(['\\,', '\;', '\\n', '\\N', '\\\\'], [',', ';', ' ', ' ', '\\'], trim($s[1])) : '';
            $rows[]  = [$date, $summary, null];
        }

        return $rows;
    }
}
