<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Model;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\CorrectionOperation;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentType;

/**
 * What a correction changes on a day (spec 198 FR-014 / FR-016): the operation, the segment (change / void), the new
 * segment's type (add), the new start / end (UTC instants), and the reason (10–500 characters).
 */
final readonly class CorrectionInput
{
    public function __construct(
        public CorrectionOperation $operation,
        public ?string $segment = null,
        public ?SegmentType $segmentType = null,
        public ?DateTimeImmutable $start = null,
        public ?DateTimeImmutable $end = null,
        public ?string $reason = null,
    ) {
    }
}
