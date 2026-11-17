<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Export;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentType;
use Nowo\WorkingTimeBundle\Domain\Register\Read\DayRecord;
use RuntimeException;

use function array_keys;
use function array_values;
use function count;
use function fclose;
use function fopen;
use function fputcsv;
use function fwrite;
use function implode;
use function intdiv;
use function is_string;
use function ksort;
use function preg_match;
use function rewind;
use function stream_get_contents;

/**
 * The register CSV (spec 198 FR-021; owner answer 2026-11-08: stable columns a payroll tool imports — Beacon's
 * columns, in Beacon's order). UTF-8 with BOM; a text cell starting with `=`, `+`, `-`, `@`, tab or CR gets a leading
 * apostrophe (formula injection). No health data exists in the register; IP prefixes only when the tenant collects
 * them.
 */
final class RegisterCsv
{
    /** The columns. */
    public const COLUMNS = [
        'organization', 'worker_id', 'worker_name', 'date', 'timezone', 'first_start', 'last_end', 'segments',
        'work_minutes', 'break_minutes', 'worked_minutes', 'expected_minutes', 'overtime_minutes', 'flags', 'corrections',
        'ip_prefixes', 'leave',
    ];

    /**
     * The rows of one worker's days (no header), by date.
     *
     * @param array<string, DayRecord> $days
     *
     * @return list<list<string|int>>
     */
    public static function rows(string $organization, array $days, bool $ip): array
    {
        $rows = [];
        foreach ($days as $date => $record) {
            $zone        = $record->workday->schedule()->zone();
            $corrections = 0;
            foreach ($record->revisions as $revision) {
                $corrections += $revision->getKind()->isCorrection() ? 1 : 0;
            }
            $rows[(string) $date] = [
                $organization,
                $record->workday->getWorkerId(),
                $record->workday->getWorkerName(),
                (string) $date,
                $zone->getName(),
                self::local($record->firstStart(), $zone),
                self::local($record->lastEnd(), $zone),
                self::segments($record, $zone),
                intdiv($record->workSeconds, 60),
                intdiv($record->breakSeconds, 60),
                intdiv($record->workedSeconds, 60),
                intdiv($record->workday->schedule()->expectedSeconds, 60),
                intdiv($record->overtimeSeconds(), 60),
                implode('|', $record->flagValues()),
                $corrections,
                $ip ? self::ipPrefixes($record) : '',
                '',
            ];
        }
        ksort($rows);

        return array_values($rows);
    }

    /**
     * The CSV bytes of a header and rows.
     *
     * @param list<list<string|int>> $rows
     */
    public static function encode(array $rows): string
    {
        $stream = fopen('php://temp', 'r+') ?: throw new RuntimeException('No temp stream.');
        fwrite($stream, "\u{FEFF}");
        fputcsv($stream, self::COLUMNS, ',', '"', '');
        foreach ($rows as $row) {
            $safe = [];
            foreach ($row as $cell) {
                $safe[] = is_string($cell) && preg_match('/^[=+\-@\t\r]/', $cell) === 1 ? "'" . $cell : $cell;
            }
            fputcsv($stream, $safe, ',', '"', '');
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /**
     * `09:00–14:00, (b) 14:00–14:45, 14:45–18:10*` (breaks `(b)`, corrected `*`, voided left out).
     */
    public static function segments(DayRecord $record, DateTimeZone $zone): string
    {
        $parts = [];
        foreach ($record->segments as $segment) {
            if ($segment->voided) {
                continue;
            }
            $parts[] = ($segment->type === SegmentType::Break ? '(b) ' : '') . self::local($segment->start, $zone) . '–' . self::local($segment->end, $zone) . ($segment->corrected ? '*' : '');
        }

        return implode(', ', $parts);
    }

    /**
     * The distinct IP prefixes of the day.
     */
    public static function ipPrefixes(DayRecord $record): string
    {
        $prefixes = [];
        foreach ($record->segments as $segment) {
            foreach ([$segment->startIpPrefix, $segment->endIpPrefix] as $prefix) {
                if ($prefix !== null) {
                    $prefixes[$prefix] = true;
                }
            }
        }

        return count($prefixes) === 0 ? '' : implode(' ', array_keys($prefixes));
    }

    /**
     * A local time (`H:i`), or ''.
     */
    public static function local(?DateTimeImmutable $at, DateTimeZone $zone, string $format = 'H:i'): string
    {
        return $at?->setTimezone($zone)->format($format) ?? '';
    }
}
