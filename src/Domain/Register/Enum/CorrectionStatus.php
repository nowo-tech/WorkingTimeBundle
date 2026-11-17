<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Enum;

/**
 * A worker's correction request. Manager-recorded corrections are chain links (their acknowledgement or contest too),
 * as in Beacon: the chain is the trail.
 */
enum CorrectionStatus: string
{
    case Pending  = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
