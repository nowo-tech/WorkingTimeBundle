<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Read;

/**
 * The verifier's answer (contract section 6.3): chains and links walked, and the first broken link of each broken
 * chain with its reason (`missing_link`, `hash_mismatch`, `unanchored_start`, `segment_missing`, `segment_changed`).
 */
final readonly class VerificationReport
{
    /**
     * @param list<array{tenant: string, worker: string, seq: int, reason: string}> $broken
     * @param array<string, string> $heads "tenant\x1Fworker" => last link's hash
     */
    public function __construct(
        public int $chains,
        public int $links,
        public array $broken,
        public array $heads = [],
    ) {
    }

    /** Whether every chain verified. */
    public function isIntact(): bool
    {
        return $this->broken === [];
    }
}
