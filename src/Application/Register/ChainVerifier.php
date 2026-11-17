<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register;

use Nowo\WorkingTimeBundle\Application\Register\Port\RegisterStoreInterface;
use Nowo\WorkingTimeBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeAuditSinkInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Chain\ChainCodecs;
use Nowo\WorkingTimeBundle\Domain\Register\Event\ChainBroken;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkdayRevision;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkSegment;
use Nowo\WorkingTimeBundle\Domain\Register\Read\VerificationReport;
use Nowo\WorkingTimeBundle\Domain\Register\Service\ChainVerifierInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\AuditEntry;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Psr\EventDispatcher\EventDispatcherInterface;

use function array_keys;
use function count;
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

    public function __construct(
        private RegisterStoreInterface $store,
        private TransactionRunnerInterface $runner,
        private ?WorkingTimeAuditSinkInterface $audit = null,
        private ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

    /**
     * Verifies the chains.
     */
    public function verify(?TenantRef $tenant = null, ?WorkerRef $worker = null): VerificationReport
    {
        $chains = $this->runner->read(fn (): array => $this->store->chains($tenant, $worker));
        $links  = 0;
        $broken = [];
        $heads  = [];
        foreach ($chains as [$chainTenant, $chainWorker]) {
            [$count, $failure, $head] = $this->walk($chainTenant, $chainWorker);
            $links += $count;
            $heads[$chainTenant->value . "\x1F" . $chainWorker->value] = $head;
            if ($failure !== null) {
                $broken[] = ['tenant' => $chainTenant->value, 'worker' => $chainWorker->value, ...$failure];
                $this->audit?->record(new AuditEntry('working_time.chain_broken', $chainTenant, null, $chainWorker, $failure));
                $this->dispatcher?->dispatch(new ChainBroken($chainTenant, $chainWorker, $failure['seq'], $failure['reason']));
            }
        }

        return new VerificationReport(count($chains), $links, $broken, $heads);
    }

    /**
     * The chain's head hash.
     */
    public function head(TenantRef $tenant, WorkerRef $worker): ?string
    {
        return $this->runner->read(fn (): ?string => $this->store->chainHead($tenant, $worker)?->getHash());
    }

    /**
     * @return array{0: int, 1: array{seq: int, reason: string}|null, 2: string}
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
        do {
            [$batch, $failure] = $this->runner->read(function () use ($tenant, $worker, &$previousHash, &$previousSeq, &$count, $anchors): array {
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
                    $mismatch = self::segmentMismatch($revision, $segments);
                    if ($mismatch !== null) {
                        return [$batch, ['seq' => $revision->getSeq(), 'reason' => $mismatch]];
                    }
                    $previousHash = $revision->getHash();
                    $previousSeq  = $revision->getSeq();
                }

                return [$batch, null];
            });
            if ($failure !== null) {
                return [$count, $failure, $previousHash];
            }
        } while (count($batch) === self::BATCH);

        return [$count, null, $previousHash];
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
    private static function segmentMismatch(WorkdayRevision $revision, array $segments): ?string
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
