<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Enum;

/**
 * A stretch of work or of break.
 */
enum SegmentType: string
{
    case Work  = 'work';
    case Break = 'break';
}
