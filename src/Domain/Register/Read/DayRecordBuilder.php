<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Read;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\CorrectionOperation;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\DayFlag;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\RevisionKind;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentSource;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentType;
use Nowo\WorkingTimeBundle\Domain\Register\Model\CorrectionRequest;
use Nowo\WorkingTimeBundle\Domain\Register\Model\Workday;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkdayRevision;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkSegment;

use function abs;
use function array_diff;
use function array_values;
use function is_string;
use function usort;

/**
 * Builds a {@see DayRecord} from what was stored (spec 198): the clocked segments, then the day's applied corrections in
 * chain order (change / add / void), then the totals and the flags against the workday's own schedule snapshot (FR-004:
 * a later schedule never changes a past day). Pure: no queries.
 */
final class DayRecordBuilder
{
    /**
     * @param list<WorkSegment> $segments the workday's clocked segments
     * @param list<WorkdayRevision> $revisions the workday's chain links, in order
     * @param list<CorrectionRequest> $requests
     */
    public static function build(Workday $workday, array $segments, array $revisions, array $requests, DateTimeImmutable $now, RegisterRules $rules = new RegisterRules()): DayRecord
    {
        /** @var array<string, EffectiveSegment> $effective */
        $effective = [];
        foreach ($segments as $segment) {
            $effective[$segment->getId()] = new EffectiveSegment(
                $segment->getId(),
                $segment->getType(),
                $segment->getStartedAt(),
                $segment->getEndedAt(),
                $segment->getStartSource(),
                $segment->getEndSource(),
                $segment->isLate($rules->lateSeconds),
                false,
                false,
                $segment->getStartedAt(),
                $segment->getEndedAt(),
                $segment->getStartIpPrefix(),
                $segment->getEndIpPrefix(),
            );
        }
        $recorded  = [];
        $answered  = [];
        $contested = false;
        foreach ($revisions as $revision) {
            $kind = $revision->getKind();
            if ($kind->isCorrection()) {
                $effective = self::apply($effective, $revision);
                if ($kind === RevisionKind::RecordedCorrection) {
                    $recorded[] = $revision->getId();
                }
            } elseif ($kind === RevisionKind::Acknowledged || $kind === RevisionKind::Contested) {
                $answered[] = is_string($revision->getPayload()['revision'] ?? null) ? $revision->getPayload()['revision'] : '';
                $contested  = $contested || $kind === RevisionKind::Contested;
            }
        }
        $list = array_values($effective);
        usort($list, static fn (EffectiveSegment $a, EffectiveSegment $b): int => $a->start <=> $b->start);
        $schedule = $workday->schedule();
        $stale    = self::staleOpen($workday, $list, $now, $rules);
        $work     = 0;
        $break    = 0;
        foreach ($list as $segment) {
            if ($segment->isOpen() && $stale) {
                continue;
            }
            if ($segment->type === SegmentType::Work) {
                $work += $segment->seconds($now);
            } else {
                $break += $segment->seconds($now);
            }
        }
        $worked  = $work + ($schedule->breaksCounted ? $break : 0);
        $pending = array_values(array_diff($recorded, $answered));
        $record  = new DayRecord($workday, $list, $work, $break, $worked, [], $revisions, $requests, $pending);

        return new DayRecord($workday, $list, $work, $break, $worked, self::flags($record, $stale, $contested, $now, $rules), $revisions, $requests, $pending);
    }

    /**
     * Whether the day's open segment is stale (a forgotten clock-out): open longer than the long-day hours since the
     * day's first start, or started before the scheduled end and still open the grace after it.
     *
     * @param list<EffectiveSegment> $segments
     */
    public static function staleOpen(Workday $workday, array $segments, DateTimeImmutable $now, RegisterRules $rules = new RegisterRules()): bool
    {
        $open  = null;
        $first = null;
        foreach ($segments as $segment) {
            if ($segment->voided) {
                continue;
            }
            $first ??= $segment->start;
            if ($segment->isOpen()) {
                $open = $segment;
            }
        }
        if ($open === null || $first === null) {
            return false;
        }
        if ($now->getTimestamp() - $first->getTimestamp() > $rules->longDaySeconds) {
            return true;
        }
        $end = $workday->schedule()->end($workday->date());

        return $end instanceof DateTimeImmutable && $open->start <= $end && $now->getTimestamp() > $end->getTimestamp() + $rules->missingClockOutGraceSeconds;
    }

    /**
     * A stored ISO time (`2026-11-10T18:10:00Z`), or null.
     */
    public static function time(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }

    /**
     * @param array<string, EffectiveSegment> $effective
     *
     * @return array<string, EffectiveSegment>
     */
    private static function apply(array $effective, WorkdayRevision $revision): array
    {
        $payload   = $revision->getPayload();
        $operation = CorrectionOperation::tryFrom(is_string($payload['op'] ?? null) ? $payload['op'] : '');
        $id        = is_string($payload['segment'] ?? null) ? $payload['segment'] : '';
        $start     = self::time($payload['start'] ?? null);
        $end       = self::time($payload['end'] ?? null);
        if ($operation === CorrectionOperation::Add && $start instanceof DateTimeImmutable) {
            $type           = SegmentType::tryFrom(is_string($payload['type'] ?? null) ? $payload['type'] : '') ?? SegmentType::Work;
            $effective[$id] = new EffectiveSegment($id, $type, $start, $end, SegmentSource::Correction, $end instanceof DateTimeImmutable ? SegmentSource::Correction : null, false, true, false, null, null);
        } elseif (isset($effective[$id]) && ($operation === CorrectionOperation::Change || $operation === CorrectionOperation::Void)) {
            $effective[$id] = $effective[$id]->with($start, $end, $operation === CorrectionOperation::Void);
        }

        return $effective;
    }

    /**
     * @return list<DayFlag>
     */
    private static function flags(DayRecord $record, bool $stale, bool $contested, DateTimeImmutable $now, RegisterRules $rules): array
    {
        $schedule = $record->workday->schedule();
        $flags    = [];
        if ($stale) {
            $flags[] = DayFlag::MissingClockOut;
        }
        $late  = false;
        $open  = false;
        $first = null;
        foreach ($record->segments as $segment) {
            if ($segment->voided) {
                continue;
            }
            $late  = $late || $segment->late;
            $open  = $open || $segment->isOpen();
            $first = $first === null || $segment->start < $first ? $segment->start : $first;
        }
        if ($late) {
            $flags[] = DayFlag::LateEntry;
        }
        if ($record->overtimeSeconds() > 0) {
            $flags[] = DayFlag::Overtime;
        }
        $last = $stale ? null : ($record->lastEnd() ?? ($first === null ? null : $now));
        if ($first !== null && $last instanceof DateTimeImmutable && $last->getTimestamp() - $first->getTimestamp() > $rules->longDaySeconds) {
            $flags[] = DayFlag::LongDay;
        }
        if (!$open && $schedule->minBreakMinutes > 0 && $record->workSeconds > RegisterRules::BREAK_RULE_AFTER && $record->breakSeconds < $schedule->minBreakMinutes * 60) {
            $flags[] = DayFlag::ShortBreak;
        }
        $scheduled = $schedule->start($record->workday->date());
        $firstWork = $record->firstStart();
        if ($schedule->flexMinutes > 0 && $scheduled instanceof DateTimeImmutable && $firstWork instanceof DateTimeImmutable && abs($firstWork->getTimestamp() - $scheduled->getTimestamp()) > $schedule->flexMinutes * 60) {
            $flags[] = DayFlag::OutsideBand;
        }
        foreach ($record->revisions as $revision) {
            if ($revision->getKind()->isCorrection()) {
                $flags[] = DayFlag::Corrected;
                break;
            }
        }
        if ($record->pendingAcknowledgements !== []) {
            $flags[] = DayFlag::PendingAcknowledgement;
        }
        if ($contested) {
            $flags[] = DayFlag::Contested;
        }
        foreach ($record->requests as $request) {
            if ($request->isPending()) {
                $flags[] = DayFlag::CorrectionPending;
                break;
            }
        }

        return $flags;
    }
}
