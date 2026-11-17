<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Application\Register\Port\RegisterStoreInterface;
use Nowo\WorkingTimeBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\WorkingTimeBundle\Domain\Port\IdGeneratorInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Chain\ChainCodecs;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\RevisionKind;
use Nowo\WorkingTimeBundle\Domain\Register\Model\Workday;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkdayRevision;
use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\RequestKey;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

use function count;

/**
 * Appends the next link of a worker's chain (spec 198 FR-018), inside the caller's write: reads the head, stores
 * `seq + 1` and flushes at once so the next append of the same unit reads it as the head. Two writers racing for the
 * same `seq` hit the unique key: one fails and is replayed or refused, a chain never forks.
 */
final readonly class RevisionChain
{
    public function __construct(
        private RegisterStoreInterface $store,
        private TransactionRunnerInterface $runner,
        private IdGeneratorInterface $ids,
    ) {
    }

    /**
     * Appends a link.
     *
     * @param array<string, mixed> $payload
     */
    public function append(Workday $workday, RevisionKind $kind, ?string $segmentId, array $payload, ?string $reason, ?ActorRef $author, string $authorName, DateTimeImmutable $at, ?RequestKey $requestKey = null): WorkdayRevision
    {
        $tenant = new TenantRef($workday->getTenantId());
        $worker = new WorkerRef($workday->getWorkerId());
        $head   = $this->store->chainHead($tenant, $worker);
        if ($head !== null) {
            [$seq, $prevHash] = [$head->getSeq() + 1, $head->getHash()];
        } else {
            // A chain the purge emptied continues from its last anchor; a new chain starts at the genesis.
            $anchors          = $this->store->anchors($tenant, $worker);
            $anchor           = $anchors === [] ? null : $anchors[count($anchors) - 1];
            [$seq, $prevHash] = $anchor === null ? [1, ChainCodecs::GENESIS] : [$anchor->getFirstKeptSeq(), $anchor->getAnchorHash()];
        }
        $revision = WorkdayRevision::append($this->ids->generate(), $seq, $prevHash, $workday, $kind, $segmentId, $payload, $reason, $author, $authorName, $at, $requestKey);
        $this->store->addRevision($revision);
        $this->runner->flush();

        return $revision;
    }
}
