<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Enum;

/**
 * What a correction does to a day: change a segment's start / end, add a missing segment, or void one. The value
 * `amend` is Beacon's (migrated chains carry it in their payloads).
 */
enum CorrectionOperation: string
{
    case Change = 'amend';
    case Add    = 'add';
    case Void   = 'void';
}
