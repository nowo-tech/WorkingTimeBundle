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
     * @param array<string, string> $publishedHeads "tenant\x1Fworker" => a head published earlier (it must still be a link)
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
        $now    = $this->clock?->now() ?? new DateTimeImmutable();
        foreach ($chains as [$chainTenant, $chainWorker]) {
            $key                                        = $chainTenant->value . "\x1F" . $chainWorker->value;
            [$count, $failure, $head, $headAt, $walked] = $this->walk($chainTenant, $chainWorker);
            $links += $count;
            $heads[$key] = $head;
            $failure ??= $this->runner->read(fn (): ?array => $this->crossCheck($chainTenant, $chainWorker, $walked, $headAt, $now, $expected[$key] ?? [])); // @igor-ignore - the runner calls this closure at once and keeps nothing
            if ($failure !== null) {
                $broken[] = ['tenant' => $chainTenant->value, 'worker' => $chainWorker->value, ...$failure];
                $this->audit?->record(new AuditEntry('working_time.chain_broken', $chainTenant, null, $chainWorker, $failure));
                $this->dispatcher?->dispatch(new ChainBroken($chainTenant, $chainWorker, $failure['seq'], $failure['reason']));
            }
        }

        return new VerificationReport(count($chains), $links, $broken, $heads);
    }

    /**
     * Records every chain's head through the audit sink (`working_time.chain_head`): an external copy of the heads that
     * a later verification compares with (a removed tail or a removed chain no longer has its published head).
     */
    public function publishHeads(VerificationReport $report): int
    {
        $published = 0;
        foreach ($report->heads as $key => $head) {
            [$tenant, $worker] = explode("\x1F", $key, 2);
            $this->audit?->record(new AuditEntry('working_time.chain_head', new TenantRef($tenant), null, new WorkerRef($worker), ['head' => $head]));
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
     * @return array{0: int, 1: array{seq: int, reason: string}|null, 2: string, 3: ?DateTimeImmutable, 4: array{opened: array<string, string>, closed: array<string, string>}}
     */
    private function walk(TenantRef $tenant, WorkerRef $worker): array
    {
        $anchors = [];
        foreach ($this->runner->read(fn (): array => $this->store->anchors($tenant, $worker)) as $anchor) {
            $anchors[$anchor->getFirstKeptSeq()] = $anchor->getAnchorHash();
        }
        $previousHash = ChainCodecs::GENESIS;
        $previousSeq  = 0;
        $count        = 0;
        $headAt       = null;
        $seen         = ['opened' => [], 'closed' => []];
        do {
            [$batch, $failure] = $this->runner->read(function () use ($tenant, $worker, &$previousHash, &$previousSeq, &$count, &$headAt, &$seen, $anchors): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
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
                return [$count, $failure, $previousHash, $headAt, $seen];
            }
        } while (count($batch) === self::BATCH);

        return [$count, null, $previousHash, $headAt, $seen];
    }

    /**
     * The other direction of the check (audit ST-M3): every segment row was opened by a clock link with its start time,
     * every closed segment was closed by a clock link with its end time, and no segment is newer than the chain's head
     * (a forged or raw-closed segment, a removed tail, a removed chain). Every anchor carries the retention boundary of
     * its purge, at least four years before the purge. A head published earlier (an export's `chain_heads`, or a head
     * published through the audit sink) must still be a link of the chain unless a purge removed it since.
     *
     * @param array{opened: array<string, string>, closed: array<string, string>} $links
     * @param list<array{string, ?DateTimeImmutable}> $expected published heads and since when
     *
     * @return array{seq: int, reason: string}|null
     */
    private function crossCheck(TenantRef $tenant, WorkerRef $worker, array $links, ?DateTimeImmutable $headAt, DateTimeImmutable $now, array $expected): ?array
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
        foreach ($anchors as $anchor) {
            $boundary = $anchor->firstKeptDate();
            $latestOk = RetentionPolicy::firstKept(RegisterSettingsInput::MIN_RETENTION_YEARS, LocalDate::of($anchor->getPurgedAt(), new DateTimeZone('UTC')));
            if ($boundary === null || $boundary->compare($latestOk) > 0 || $anchor->getPurgedAt() > $now) {
                return ['seq' => $anchor->getFirstKeptSeq(), 'reason' => 'anchor_out_of_retention'];
            }
        }
        foreach ($expected as [$head, $since]) {
            if ($this->store->hasLinkHash($tenant, $worker, $head)) {
                continue;
            }
            $purgedSince = false;
            foreach ($anchors as $anchor) {
                // A head with a date (an export's) is covered by a purge after it; a head without one (`--heads`) by any
                // purge of the chain, since the purge may have taken its link.
                $purgedSince = $purgedSince || $anchor->getAnchorHash() === $head || !$since instanceof DateTimeImmutable || $anchor->getPurgedAt() > $since;
            }
            if (!$purgedSince) {
                return ['seq' => 0, 'reason' => 'published_head_missing'];
            }
        }

        return null;
    }

    /**
     * The heads published earlier, by chain: the exports' `chain_heads` (with their date) and the given ones.
     *
     * @param array<string, string> $published
     *
     * @return array<string, list<array{string, ?DateTimeImmutable}>>
     */
    private function expectedHeads(?TenantRef $tenant, ?WorkerRef $worker, array $published): array
    {
        $expected = [];
        foreach ($this->runner->read(fn (): array => $this->store->exportsWithHeads($tenant)) as $export) { // @igor-ignore - the runner calls this closure at once and keeps nothing
            foreach ($export->getChainHeads() as $exportWorker => $head) {
                if ($head !== '' && (!$worker instanceof WorkerRef || $worker->value === (string) $exportWorker)) {
                    $expected[$export->getTenantId() . "\x1F" . $exportWorker][] = [$head, $export->getCreatedAt()];
                }
            }
        }
        foreach ($published as $key => $head) {
            $expected[$key][] = [$head, null];
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
