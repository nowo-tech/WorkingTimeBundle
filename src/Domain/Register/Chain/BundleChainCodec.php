<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Chain;

use Nowo\WorkingTimeBundle\Domain\Register\Enum\ChainFormat;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkdayRevision;

/**
 * Format 2: Beacon's keys with the bundle's string references (`tenant`, `worker`, `workday` UUID, `author`) and
 * `format: 2`, so a format 2 link can never be read as a format 1 one.
 */
final class BundleChainCodec implements ChainCodecInterface
{
    /**
     * Format 2.
     */
    public function format(): ChainFormat
    {
        return ChainFormat::Bundle;
    }

    /**
     * The bundle's canonical JSON of the link.
     */
    public function canonical(WorkdayRevision $revision): string
    {
        return CanonicalJson::encode([
            'format'      => ChainFormat::Bundle->value,
            'tenant'      => $revision->getTenantId(),
            'worker'      => $revision->getWorkerId(),
            'workday'     => $revision->getWorkdayId(),
            'seq'         => $revision->getSeq(),
            'kind'        => $revision->getKind()->value,
            'segment'     => $revision->getSegmentId(),
            'payload'     => $revision->getPayload(),
            'reason'      => $revision->getReason(),
            'author'      => $revision->getAuthorId(),
            'author_name' => $revision->getAuthorName(),
            'at'          => WorkdayRevision::iso($revision->getCreatedAt()),
            'request_key' => $revision->getRequestKey(),
        ]);
    }
}
