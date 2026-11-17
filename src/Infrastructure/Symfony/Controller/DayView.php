<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\UiKitBundle\View\DetailItem;
use Nowo\UiKitBundle\View\DetailSection;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\CorrectionOperation;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentType;
use Nowo\WorkingTimeBundle\Domain\Register\Model\CorrectionInput;
use Nowo\WorkingTimeBundle\Domain\Register\Read\DayRecord;
use Nowo\WorkingTimeBundle\Domain\Register\Read\MonthSummary;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Export\RegisterCsv;

use function array_filter;
use function implode;
use function is_string;
use function sprintf;
use function substr;

/**
 * Turns register records into the UiKit view models and the correction form's data into a correction (local `HH:MM`
 * times in the day's zone; an end before the start is the next day). Pure.
 *
 * @internal
 */
final class DayView
{
    /**
     * The sections of a day page.
     *
     * @return list<DetailSection>
     */
    public static function sections(LocalDate $date, ?DayRecord $record, string $timezone, bool $showIp): array
    {
        $zone    = new DateTimeZone($timezone);
        $summary = [
            new DetailItem(UiSupport::label('day.date'), $date->value),
            new DetailItem(UiSupport::label('day.timezone'), $timezone),
            new DetailItem(UiSupport::label('day.worked'), MonthSummary::hours($record->workedSeconds ?? 0)),
            new DetailItem(UiSupport::label('day.breaks'), MonthSummary::hours($record->breakSeconds ?? 0)),
            new DetailItem(UiSupport::label('day.expected'), MonthSummary::hours($record?->workday->schedule()->expectedSeconds ?? 0)),
        ];
        foreach ($record->flags ?? [] as $flag) {
            $summary[] = new DetailItem(UiSupport::label('day.flag'), UiSupport::label('flag.' . $flag->value), badge: 'warning');
        }
        $segments = [];
        foreach ($record->segments ?? [] as $segment) {
            $text = RegisterCsv::local($segment->start, $zone) . ' – ' . RegisterCsv::local($segment->end, $zone);
            if ($showIp && ($segment->startIpPrefix !== null || $segment->endIpPrefix !== null)) {
                $text .= sprintf(' (%s)', implode(' ', array_filter([$segment->startIpPrefix, $segment->endIpPrefix])));
            }
            $segments[] = new DetailItem(
                UiSupport::label('segment.type.' . $segment->type->value),
                $text,
                badge: $segment->voided ? 'neutral' : ($segment->corrected ? 'info' : null),
                attributes: ['data-testid' => 'wt-segment', 'data-voided' => $segment->voided ? 'true' : 'false'],
            );
        }
        $trail = [];
        foreach ($record->revisions ?? [] as $revision) {
            $trail[] = new DetailItem(
                UiSupport::label('history.' . $revision->getKind()->value),
                $revision->getCreatedAt()->setTimezone($zone)->format('Y-m-d H:i') . ' · ' . $revision->getAuthorName() . ($revision->getReason() === null ? '' : ' · ' . $revision->getReason()),
                attributes: ['data-testid' => 'wt-trail'],
            );
        }
        foreach ($record->requests ?? [] as $request) {
            $trail[] = new DetailItem(
                UiSupport::label('correction.status.' . $request->getStatus()->value),
                $request->getCreatedAt()->setTimezone($zone)->format('Y-m-d H:i') . ' · ' . $request->getReason(),
                badge: $request->isPending() ? 'warning' : null,
                attributes: ['data-testid' => 'wt-request'],
            );
        }

        return [
            new DetailSection(UiSupport::label('day.section.summary'), $summary, 'wt-summary', 'facts'),
            new DetailSection(UiSupport::label('day.section.segments'), $segments, 'wt-segments'),
            new DetailSection(UiSupport::label('day.section.trail'), $trail, 'wt-trail'),
        ];
    }

    /**
     * The segments a correction may change (`label => id`), voided ones left out.
     *
     * @return array<string, string>
     */
    public static function segmentChoices(?DayRecord $record, string $timezone): array
    {
        $zone    = new DateTimeZone($timezone);
        $choices = [];
        foreach ($record->segments ?? [] as $segment) {
            if (!$segment->voided) {
                $choices[($segment->type === SegmentType::Break ? '(b) ' : '') . RegisterCsv::local($segment->start, $zone) . '–' . RegisterCsv::local($segment->end, $zone)] = $segment->id;
            }
        }

        return $choices;
    }

    /**
     * The correction of the form's data.
     *
     * @param array<string, mixed> $data
     */
    public static function correction(array $data, LocalDate $date, string $timezone): CorrectionInput
    {
        $zone  = new DateTimeZone($timezone);
        $start = self::time($data['start'] ?? null, $date, $zone);
        $end   = self::time($data['end'] ?? null, $date, $zone);
        if ($start instanceof DateTimeImmutable && $end instanceof DateTimeImmutable && $end <= $start) {
            $end = $end->modify('+1 day');
        }

        return new CorrectionInput(
            CorrectionOperation::tryFrom(is_string($data['operation'] ?? null) ? $data['operation'] : '') ?? CorrectionOperation::Change,
            is_string($data['segment'] ?? null) && $data['segment'] !== '' ? $data['segment'] : null,
            SegmentType::tryFrom(is_string($data['segmentType'] ?? null) ? $data['segmentType'] : '') ?? SegmentType::Work,
            $start,
            $end,
            is_string($data['reason'] ?? null) ? $data['reason'] : null,
        );
    }

    private static function time(mixed $value, LocalDate $date, DateTimeZone $zone): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date->value . ' ' . substr($value, 0, 5), $zone);

        return $at === false ? null : $at;
    }
}
