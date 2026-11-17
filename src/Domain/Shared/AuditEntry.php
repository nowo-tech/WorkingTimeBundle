<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Shared;

use InvalidArgumentException;

use function is_scalar;
use function preg_match;

/**
 * One audit record (`working_time.*` keys): who, on whom, and counts or ids only — never a reason's content, a comment
 * or a document (contract section 8.2).
 */
final readonly class AuditEntry
{
    /**
     * @param array<string, scalar|null> $context ids, dates, counts
     */
    public function __construct(
        public string $key,
        public TenantRef $tenant,
        public ?ActorRef $actor = null,
        public ?WorkerRef $subject = null,
        public array $context = [],
    ) {
        if (preg_match('/^working_time\.[a-z_.]+$/D', $key) !== 1) {
            throw new InvalidArgumentException('Audit keys start with "working_time.".');
        }
        foreach ($context as $value) {
            if ($value !== null && !is_scalar($value)) {
                throw new InvalidArgumentException('Audit context values are scalars.');
            }
        }
    }
}
