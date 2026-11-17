<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Export;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\CorrectionOperation;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\DayFlag;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\RevisionKind;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkdayRevision;
use Nowo\WorkingTimeBundle\Domain\Register\Read\DayRecord;
use Nowo\WorkingTimeBundle\Domain\Register\Read\DayRecordBuilder;
use Nowo\WorkingTimeBundle\Domain\Register\Read\MonthSummary;
use Nowo\WorkingTimeBundle\Domain\Register\Read\RegisterReport;
use Nowo\WorkingTimeBundle\NowoWorkingTimeBundle;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;
use function implode;
use function is_string;
use function mb_substr;

/**
 * Lays out one worker's register for the inspection PDF (spec 198 FR-021 / FR-022; R6 of Beacon's reports review):
 * the key figures, one row per day (start, end, segments, breaks, worked, expected, overtime, flags, IP prefixes when
 * collected), the corrections with their reasons and who approved or recorded them, and the integrity block. Every
 * text is translated in the export's locale.
 */
final readonly class RegisterReportBuilder
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * The report.
     *
     * @param array<string, DayRecord> $days
     */
    public function build(array $days, string $organization, string $worker, string $period, string $generatedBy, ?string $chainHead, ?string $integrity, bool $ip, DateTimeImmutable $now, string $locale): RegisterReport
    {
        $t       = fn (string $key, array $params = []): string => $this->translator->trans($key, $params, NowoWorkingTimeBundle::TRANSLATION_DOMAIN, $locale);
        $summary = MonthSummary::of($days);
        $head    = $chainHead ?? '—';
        $rows    = [];
        $changes = [];
        foreach ($days as $date => $record) {
            $zone = $record->workday->schedule()->zone();
            $row  = [
                (string) $date,
                RegisterCsv::local($record->firstStart(), $zone),
                RegisterCsv::local($record->lastEnd(), $zone),
                RegisterCsv::segments($record, $zone),
                MonthSummary::hours($record->breakSeconds),
                MonthSummary::hours($record->workedSeconds),
                MonthSummary::hours($record->workday->schedule()->expectedSeconds),
                MonthSummary::hours($record->overtimeSeconds()),
                implode(', ', array_map(static fn (DayFlag $flag): string => $t('flag.' . $flag->value), $record->flags)),
            ];
            if ($ip) {
                $row[] = RegisterCsv::ipPrefixes($record);
            }
            $rows[] = $row;
            foreach ($record->revisions as $revision) {
                if ($revision->getKind()->isCorrection() || $revision->getKind() === RevisionKind::Contested) {
                    $changes[] = $this->correctionRow((string) $date, $revision, $zone, $t);
                }
            }
        }
        $headers = [$t('export.col.date'), $t('export.col.start'), $t('export.col.end'), $t('export.col.segments'), $t('export.col.breaks'), $t('export.col.worked'), $t('export.col.expected'), $t('export.col.overtime'), $t('export.col.flags')];
        if ($ip) {
            $headers[] = $t('export.col.ip_prefix');
        }

        return new RegisterReport(
            $t('export.title_pdf', ['%worker%' => $worker]),
            $t('export.header', ['%organization%' => $organization, '%worker%' => $worker, '%period%' => $period, '%head%' => mb_substr($head, 0, 16)]),
            $now,
            [
                [$t('export.figure.worker'), $worker],
                [$t('export.figure.period'), $period],
                [$t('export.figure.worked'), MonthSummary::hours($summary->workedSeconds)],
                [$t('export.figure.expected'), MonthSummary::hours($summary->expectedSeconds)],
                [$t('export.figure.daily_overtime'), MonthSummary::hours($summary->dailyOvertimeSeconds)],
                [$t('export.figure.weekly_overtime'), MonthSummary::hours($summary->weeklyOvertimeSeconds())],
                [$t('export.figure.days'), (string) $summary->daysWorked],
                [$t('export.figure.missing'), (string) $summary->missingClockOuts],
                [$t('export.figure.corrections'), (string) $summary->corrections],
            ],
            $headers,
            $rows,
            [$t('export.col.date'), $t('export.col.change'), $t('export.col.reason'), $t('export.col.by'), $t('export.col.approved_by'), $t('export.col.when')],
            $changes,
            [
                [$t('export.figure.generated_at'), $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i') . ' UTC'],
                [$t('export.figure.generated_by'), $generatedBy],
                [$t('export.figure.chain_head'), $head],
                [$t('export.figure.integrity'), $integrity ?? $t('export.integrity_ok')],
                [$t('export.figure.file_hash'), $t('export.file_hash_note')],
            ],
            $t('export.legal_note'),
            $head,
            $locale,
            [
                'summary'     => $t('export.section.summary'),
                'days'        => $t('export.section.days'),
                'corrections' => $t('export.section.corrections'),
                'integrity'   => $t('export.section.integrity'),
                'empty'       => $t('export.empty'),
                'generated'   => $t('export.figure.generated_at'),
                'page'        => $t('export.page'),
            ],
        );
    }

    /**
     * @param callable(string, array<string, string>=): string $t
     *
     * @return list<string>
     */
    private function correctionRow(string $date, WorkdayRevision $revision, DateTimeZone $zone, callable $t): array
    {
        $payload = $revision->getPayload();
        $when    = $revision->getCreatedAt()->setTimezone($zone)->format('Y-m-d H:i');
        if ($revision->getKind() === RevisionKind::Contested) {
            return [$date, $t('history.contested'), (string) $revision->getReason(), $revision->getAuthorName(), '', $when];
        }
        $operation = CorrectionOperation::tryFrom(is_string($payload['op'] ?? null) ? $payload['op'] : '') ?? CorrectionOperation::Change;
        $start     = DayRecordBuilder::time($payload['start'] ?? null);
        $end       = DayRecordBuilder::time($payload['end'] ?? null);
        $change    = $t('correction.operation.' . $operation->value) . ($operation === CorrectionOperation::Void ? '' : ': ' . RegisterCsv::local($start, $zone) . ' – ' . RegisterCsv::local($end, $zone));
        $recorded  = $revision->getKind() === RevisionKind::RecordedCorrection;
        $approver  = is_string($payload['approved_by'] ?? null) ? $payload['approved_by'] : $revision->getAuthorName();

        return [
            $date,
            $change,
            (string) $revision->getReason(),
            $recorded ? $revision->getAuthorName() : $t('export.by_worker'),
            $recorded ? $t('export.recorded_by_manager') : $approver,
            $when,
        ];
    }
}
