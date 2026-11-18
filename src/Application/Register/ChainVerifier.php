<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\WorkingTimeBundle\Application\Register\Port\RegisterStoreInterface;
use Nowo\WorkingTimeBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeAuditSinkInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Chain\ChainCodecs;
use Nowo\WorkingTimeBundle\Domain\Register\Event\ChainBroken;
use Nowo\WorkingTimeBundle\Domain\Register\Model\ChainAnchor;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettingsInput;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RetentionPolicy;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkdayRevision;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkSegment;
use Nowo\WorkingTimeBundle\Domain\Register\Read\VerificationReport;
use Nowo\WorkingTimeBundle\Domain\Register\Service\ChainVerifierInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\AuditEntry;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

use function array_keys;
use function count;
use function explode;
use function is_string;

/**
 * Checks every register chain (spec 198 FR-018 / SC-002, contract section 6.3): each link's hash against its row (in
 * the link's format) and the previous link, `seq` without gaps, the chain start (the genesis, or a recorded
 * `chain_anchor`: a chain whose oldest links were deleted without the purge is *unanchored*), and each clock link's
 * segment times against the segment rows. Reads in batches of 1 000 and clears the entity manager between them (flat
 * memory). Reports the first broken link of each chain, raises `ChainBroken` and an audit entry for it.
 */
final readonly class ChainVerifier implements ChainVerifierInterface
{
    /** Links per read. */
    public const BATCH = 1_000;

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private RegisterStoreInterface $store,
        private TransactionRunnerInterface $runner,
        private ?WorkingTimeAuditSinkInterface $audit = null,
        private ?EventDispatcherInterface $dispatcher = null,
        private ?ClockInterface $clock = null,
    ) {
    }

    /**
     * Verifies the chains.
     *
     * A published head is a hash with the link's `seq` and creation time (`--heads-out`, `--publish-heads`): it may be
     * missing only when its `seq` is below the chain's start and its workday (`day`) before the start anchor's
     * `first_kept_date`; without a `day`, below the start is enough (0.1.1 rule). A bare hash (0.1.0) carries no `seq`: the weaker rule applies, and an anchor carrying that
     * hash excuses it.
     *
     * @param array<string, array{hash: string, seq: int, at?: string|null, day?: string|null}|string> $publishedHeads "tenant\x1Fworker" => a head published earlier (it must still be a link)
     */
    public function verify(?TenantRef $tenant = null, ?WorkerRef $worker = null, array $publishedHeads = []): VerificationReport
    {
        $chains   = $this->runner->read(fn (): array => $this->store->chains($tenant, $worker)); // @igor-ignore - the runner calls this closure at once and keeps nothing
        $expected = $this->expectedHeads($tenant, $worker, $publishedHeads);
        $known    = [];
        foreach ($chains as [$chainTenant, $chainWorker]) {
            $known[$chainTenant->value . "\x1F" . $chainWorker->value] = true;
        }
        foreach (array_keys($expected) as $key) {
            if (!isset($known[$key])) {
                // A chain that has vanished entirely (links, segments and workdays): only its published head remains.
                [$lostTenant, $lostWorker] = explode("\x1F", $key, 2);
                $chains[]                  = [new TenantRef($lostTenant), new WorkerRef($lostWorker)];
            }
        }
        $links  = 0;
        $broken = [];
        $heads  = [];
        $tips   = [];
        $now    = $this->clock?->now() ?? new DateTimeImmutable();
        foreach ($chains as [$chainTenant, $chainWorker]) {
            $key                                                                       = $chainTenant->value . "\x1F" . $chainWorker->value;
            [$count, $failure, $head, $headAt, $walked, $headSeq, $startSeq, $headDay] = $this->walk($chainTenant, $chainWorker);
            $links += $count;
            $heads[$key] = $head;
            $tips[$key]  = ['hash' => $head, 'seq' => $headSeq, 'at' => $headAt instanceof DateTimeImmutable ? WorkdayRevision::iso($headAt) : null, 'day' => $headDay];
            $failure ??= $this->runner->read(fn (): ?array => $this->crossCheck($chainTenant, $chainWorker, $walked, $headAt, $startSeq, $now, $expected[$key] ?? [])); // @igor-ignore - the runner calls this closure at once and keeps nothing
            if ($failure !== null) {
                $broken[] = ['tenant' => $chainTenant->value, 'worker' => $chainWorker->value, ...$failure];
                $this->audit?->record(new AuditEntry('working_time.chain_broken', $chainTenant, null, $chainWorker, $failure));
                $this->dispatcher?->dispatch(new ChainBroken($chainTenant, $chainWorker, $failure['seq'], $failure['reason']));
            }
        }

        return new VerificationReport(count($chains), $links, $broken, $heads, $tips);
    }

    /**
     * Records every chain's head (`{hash, seq, at, day}`) through the audit sink (`working_time.chain_head`): an external
     * copy of the heads that a later verification compares with (a removed tail or a removed chain no longer has its
     * published head).
     */
    public function publishHeads(VerificationReport $report): int
    {
        $published = 0;
        foreach ($report->headLinks as $key => $head) {
            [$tenant, $worker] = explode("\x1F", $key, 2);
            $this->audit?->record(new AuditEntry('working_time.chain_head', new TenantRef($tenant), null, new WorkerRef($worker), $head));
            ++$published;
        }

        return $published;
    }

    /**
     * The chain's head hash.
     */
    public function head(TenantRef $tenant, WorkerRef $worker): ?string
    {
        return $this->runner->read(fn (): ?string => $this->store->chainHead($tenant, $worker)?->getHash());
    }

    /**
     * The walk's result: links walked, the first failure, the head (hash, time, seq), the first walked `seq` (null: no
     * link) and the head's workday date (`day`). A chain the purge emptied has its last anchor as its head: the next link
     * continues from it, and its day is the one before the anchor's `first_kept_date` (every purged day is older).
     *
     * @return array{0: int, 1: array{seq: int, reason: string}|null, 2: string, 3: ?DateTimeImmutable, 4: array{opened: array<string, string>, closed: array<string, string>}, 5: int, 6: ?int, 7: ?string}
     */
    private function walk(TenantRef $tenant, WorkerRef $worker): array
    {
        $anchors    = [];
        $lastAnchor = null;
        foreach ($this->runner->read(fn (): array => $this->store->anchors($tenant, $worker)) as $anchor) {
            $anchors[$anchor->getFirstKeptSeq()] = $anchor->getAnchorHash();
            $lastAnchor                          = $anchor;
        }
        $headWorkday  = null;
        $previousHash = ChainCodecs::GENESIS;
        $previousSeq  = 0;
        $count        = 0;
        $headAt       = null;
        $startSeq     = null;
        $seen         = ['opened' => [], 'closed' => []];
        do {
            [$batch, $failure] = $this->runner->read(function () use ($tenant, $worker, &$previousHash, &$previousSeq, &$count, &$headAt, &$headWorkday, &$startSeq, &$seen, $anchors): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
                $batch    = $this->store->chainBatch($tenant, $worker, $previousSeq, self::BATCH);
                $segments = $this->segments($batch);
                foreach ($batch as $revision) {
                    if ($count === 0 && $revision->getSeq() !== 1) {
                        // A purged chain starts at a recorded anchor; anything else is unanchored.
                        if (($anchors[$revision->getSeq()] ?? null) !== $revision->getPrevHash()) {
                            return [$batch, ['seq' => $revision->getSeq(), 'reason' => 'unanchored_start']];
                        }
                        $previousSeq  = $revision->getSeq() - 1;
                        $previousHash = $revision->getPrevHash();
                    }
                    $startSeq ??= $revision->getSeq();
                    ++$count;
                    if ($revision->getSeq() !== $previousSeq + 1) {
                        return [$batch, ['seq' => $previousSeq + 1, 'reason' => 'missing_link']];
                    }
                    if (!ChainCodecs::isIntact($revision, $previousHash)) {
                        return [$batch, ['seq' => $revision->getSeq(), 'reason' => 'hash_mismatch']];
                    }
                    $mismatch = $this->segmentMismatch($revision, $segments);
                    if ($mismatch !== null) {
                        return [$batch, ['seq' => $revision->getSeq(), 'reason' => $mismatch]];
                    }
                    $previousHash = $revision->getHash();
                    $previousSeq  = $revision->getSeq();
                    $headAt       = $revision->getCreatedAt();
                    $headWorkday  = $revision->getWorkdayId();
                    $payload      = $revision->getPayload();
                    foreach (['opened', 'closed'] as $side) {
                        if ($revision->getKind()->isClock() && is_string($payload[$side] ?? null) && is_string($payload['at'] ?? null)) {
                            $seen[$side][$payload[$side]] = $payload['at'];
                        }
                    }
                }

                return [$batch, null];
            });
            if ($failure !== null) {
                return [$count, $failure, $previousHash, $headAt, $seen, $previousSeq, $startSeq, null];
            }
        } while (count($batch) === self::BATCH);
        if ($count === 0 && $lastAnchor instanceof ChainAnchor) {
            return [0, null, $lastAnchor->getAnchorHash(), null, $seen, $lastAnchor->getFirstKeptSeq() - 1, null, $lastAnchor->firstKeptDate()?->plusDays(-1)->value];
        }
        $day = $headWorkday === null ? null : $this->runner->read(fn (): ?string => $this->store->workday($headWorkday)?->date()->value);

        return [$count, null, $previousHash, $headAt, $seen, $previousSeq, $startSeq, $day];
    }

    /**
     * The other direction of the check (audit ST-M3): every segment row was opened by a clock link with its start time,
     * every closed segment was closed by a clock link with its end time, and no segment is newer than the chain's head
     * (a forged or raw-closed segment, a removed tail, a removed chain). Every anchor carries the retention boundary of
     * its purge, at least four years before the purge; no anchor is past the chain's first link (a purge only ever
     * removes a prefix: an anchor beyond the start was not written by one) and none keeps less than `seq` 2 (a purge
     * removes at least one link). A head published earlier (an export's `chain_heads`, or a head published with
     * `--heads-out` / through the audit sink) must still be a link of the chain unless a purge removed it: its `seq` is
     * below the chain's start and its workday before the start anchor's retention boundary (audit ST-D1 / ST-D1b /
     * ST-H2).
     *
     * @param array{opened: array<string, string>, closed: array<string, string>} $links
     * @param list<array{0: string, 1: ?int, 2: ?LocalDate, 3: ?DateTimeImmutable}> $expected published heads: hash, seq, workday date, since when
     *
     * @return array{seq: int, reason: string}|null
     */
    private function crossCheck(TenantRef $tenant, WorkerRef $worker, array $links, ?DateTimeImmutable $headAt, ?int $startSeq, DateTimeImmutable $now, array $expected): ?array
    {
        $latest = null;
        foreach ($this->store->segmentTimes($tenant, $worker) as $segment) {
            if (($links['opened'][$segment['id']] ?? null) !== WorkdayRevision::iso($segment['start'])) {
                return ['seq' => 0, 'reason' => 'segment_unlinked'];
            }
            if ($segment['end'] !== null && ($links['closed'][$segment['id']] ?? null) !== WorkdayRevision::iso($segment['end'])) {
                return ['seq' => 0, 'reason' => 'segment_close_unlinked'];
            }
            foreach ([$segment['start'], $segment['end']] as $time) {
                $latest = $time !== null && ($latest === null || $time > $latest) ? $time : $latest;
            }
        }
        if ($latest !== null && (!$headAt instanceof DateTimeImmutable || $latest > $headAt)) {
            return ['seq' => 0, 'reason' => 'head_stale'];
        }
        $anchors = $this->store->anchors($tenant, $worker);
        $start   = $startSeq;
        foreach ($anchors as $anchor) {
            if ($anchor->getFirstKeptSeq() < 2) {
                return ['seq' => $anchor->getFirstKeptSeq(), 'reason' => 'anchor_seq_invalid'];
            }
            // A chain the purge emptied starts where its last anchor says (anchors come in `first_kept_seq` order).
            $start    = $startSeq ?? $anchor->getFirstKeptSeq();
            $boundary = $anchor->firstKeptDate();
            $latestOk = RetentionPolicy::firstKept(RegisterSettingsInput::MIN_RETENTION_YEARS, LocalDate::of($anchor->getPurgedAt(), new DateTimeZone('UTC')));
            if ($boundary === null || $boundary->compare($latestOk) > 0 || $anchor->getPurgedAt() > $now->modify('+5 minutes')) {
                return ['seq' => $anchor->getFirstKeptSeq(), 'reason' => 'anchor_out_of_retention'];
            }
            if ($startSeq !== null && $anchor->getFirstKeptSeq() > $startSeq) {
                return ['seq' => $anchor->getFirstKeptSeq(), 'reason' => 'anchor_after_start'];
            }
        }
        $startAnchor = null;
        foreach ($anchors as $anchor) {
            $startAnchor = $anchor->getFirstKeptSeq() === $start ? $anchor : $startAnchor;
        }
        foreach ($expected as [$head, $seq, $day, $since]) {
            if ($head === ChainCodecs::GENESIS ? $seq === 0 : $this->store->hasLinkHash($tenant, $worker, $head)) {
                // The genesis is the head of a chain that was still empty (`seq` 0); any other head must still be a link.
                continue;
            }
            // With its `seq`, a head was removed by a purge only when it is below the chain's start and, with its workday
            // date, when that day is before the start anchor's `first_kept_date`: exactly what the purge deletes (audit ST
            // hardening, ST-H2). Without a day (0.1.1 export rows and heads files) the 0.1.1 rule stays: below the start.
            // A bare hash (0.1.0: API callers, export rows) is weaker: covered by the anchor carrying it, or by a purge
            // after the export.
            $purged = $seq !== null
                ? $head !== ChainCodecs::GENESIS && $start !== null && $seq < $start && (!$day instanceof LocalDate || $this->beforeKept($startAnchor, $day))
                : $this->bareHeadPurged($anchors, $head, $since);
            if (!$purged) {
                return ['seq' => 0, 'reason' => 'published_head_missing'];
            }
        }

        return null;
    }

    /**
     * Whether a head's workday is older than the retention boundary of the anchor at the chain's start: the purge deletes
     * exactly the workdays before `first_kept_date`, and a forged anchor cannot move that boundary closer than four years
     * before its purge (`anchor_out_of_retention`), so a recent head is never excused (audit ST probes d2 and e2).
     */
    private function beforeKept(?ChainAnchor $startAnchor, LocalDate $day): bool
    {
        $boundary = $startAnchor?->firstKeptDate();

        return $boundary instanceof LocalDate && $day->compare($boundary) < 0;
    }

    /**
     * The 0.1.0 rule for a published head without its `seq` (documented as weaker): an anchor carries its hash, or a
     * purge ran after the export that recorded it.
     *
     * @param list<ChainAnchor> $anchors
     */
    private function bareHeadPurged(array $anchors, string $head, ?DateTimeImmutable $since): bool
    {
        foreach ($anchors as $anchor) {
            if ($head !== ChainCodecs::GENESIS && ($anchor->getAnchorHash() === $head || ($since instanceof DateTimeImmutable && $anchor->getPurgedAt() > $since))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The heads published earlier, by chain: the exports' `chain_heads` (with their date) and the given ones.
     *
     * @param array<string, array{hash: string, seq: int, at?: string|null, day?: string|null}|string> $published
     *
     * @return array<string, list<array{0: string, 1: ?int, 2: ?LocalDate, 3: ?DateTimeImmutable}>>
     */
    private function expectedHeads(?TenantRef $tenant, ?WorkerRef $worker, array $published): array
    {
        $expected = [];
        foreach ($this->runner->read(fn (): array => $this->store->exportsWithHeads($tenant)) as $export) { // @igor-ignore - the runner calls this closure at once and keeps nothing
            foreach ($export->getChainHeads() as $exportWorker => $head) {
                // 0.1.2 exports record `{hash, seq, at, day}`, 0.1.1 ones `{hash, seq}`, 0.1.0 ones a bare hash.
                [$hash, $seq, $day] = is_string($head) ? [$head, null, null] : [$head['hash'], $head['seq'], LocalDate::tryParse($head['day'] ?? null)];
                if ($hash !== '' && (!$worker instanceof WorkerRef || $worker->value === (string) $exportWorker)) {
                    $expected[$export->getTenantId() . "\x1F" . $exportWorker][] = [$hash, $seq, $day, $export->getCreatedAt()];
                }
            }
        }
        foreach ($published as $key => $head) {
            $expected[$key][] = is_string($head) ? [$head, null, null, null] : [$head['hash'], $head['seq'], LocalDate::tryParse($head['day'] ?? null), null];
        }

        return $expected;
    }

    /**
     * @param list<WorkdayRevision> $batch
     *
     * @return array<string, WorkSegment>
     */
    private function segments(array $batch): array
    {
        $ids = [];
        foreach ($batch as $revision) {
            foreach (['opened', 'closed'] as $key) {
                $id = $revision->getPayload()[$key] ?? null;
                if (is_string($id)) {
                    $ids[$id] = true;
                }
            }
        }

        return $ids === [] ? [] : $this->store->segmentsById(array_keys($ids));
    }

    /**
     * A clock link records the segment it opened (start) and the one it closed (end): the rows must still say so.
     *
     * @param array<string, WorkSegment> $segments
     */
    private function segmentMismatch(WorkdayRevision $revision, array $segments): ?string
    {
        $payload = $revision->getPayload();
        $at      = is_string($payload['at'] ?? null) ? $payload['at'] : null;
        foreach (['opened' => true, 'closed' => false] as $key => $start) {
            $id = $payload[$key] ?? null;
            if (!is_string($id)) {
                continue;
            }
            $segment = $segments[$id] ?? null;
            if ($segment === null) {
                return 'segment_missing';
            }
            $value = $start ? $segment->getStartedAt() : $segment->getEndedAt();
            if ($value === null || WorkdayRevision::iso($value) !== $at) {
                return 'segment_changed';
            }
        }

        return null;
    }
}
