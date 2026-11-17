<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Chain;

use LogicException;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ChainFormat;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkdayRevision;

use function is_int;

/**
 * Format 1: Beacon's `WorkdayRevision::canonical()` exactly — the organization, user, workday and author **integer**
 * ids come from the frozen `legacy_refs`; every other key is the stored row. Migrated links are never re-hashed.
 */
final class BeaconChainCodec implements ChainCodecInterface
{
    /**
     * Format 1.
     */
    public function format(): ChainFormat
    {
        return ChainFormat::Beacon;
    }

    /**
     * Beacon's canonical JSON of the link.
     */
    public function canonical(WorkdayRevision $revision): string
    {
        $refs = $revision->getLegacyRefs();
        if ($refs === null || !is_int($refs['organization'] ?? null) || !is_int($refs['user'] ?? null) || !is_int($refs['workday'] ?? null)) {
            throw new LogicException('A format 1 chain link needs its legacy integer refs.');
        }
        $author = $refs['author'] ?? null;

        return CanonicalJson::encode([
            'organization' => $refs['organization'],
            'user'         => $refs['user'],
            'workday'      => $refs['workday'],
            'seq'          => $revision->getSeq(),
            'kind'         => $revision->getKind()->value,
            'segment'      => $revision->getSegmentId(),
            'payload'      => $revision->getPayload(),
            'reason'       => $revision->getReason(),
            'author'       => is_int($author) ? $author : null,
            'author_name'  => $revision->getAuthorName(),
            'at'           => WorkdayRevision::iso($revision->getCreatedAt()),
            'request_key'  => $revision->getRequestKey(),
        ]);
    }
}
