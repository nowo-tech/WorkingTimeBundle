<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Read;

/**
 * The verifier's answer (contract section 6.3): chains and links walked, and the first broken link of each broken
 * chain with its reason (`missing_link`, `hash_mismatch`, `unanchored_start`, `segment_missing`, `segment_changed`,
 * `segment_unlinked`, `segment_close_unlinked`, `head_stale`, `anchor_out_of_retention`, `anchor_after_start`,
 * `published_head_missing`). `$headLinks` holds each chain's head as `--heads-out` / `--publish-heads` write it: the
 * last link's hash, `seq`, creation time (UTC ISO 8601) and workday date (`day`), or, for a chain the purge emptied,
 * its last anchor (time null, day the one before its `first_kept_date`).
 */
final readonly class VerificationReport
{
    /**
     * @param list<array{tenant: string, worker: string, seq: int, reason: string}> $broken
     * @param array<string, string> $heads "tenant\x1Fworker" => last link's hash
     * @param array<string, array{hash: string, seq: int, at: string|null, day: string|null}> $headLinks "tenant\x1Fworker" => head link
     */
    public function __construct(
        public int $chains,
        public int $links,
        public array $broken,
        public array $heads = [],
        public array $headLinks = [],
    ) {
    }

    /** Whether every chain verified. */
    public function isIntact(): bool
    {
        return $this->broken === [];
    }
}
