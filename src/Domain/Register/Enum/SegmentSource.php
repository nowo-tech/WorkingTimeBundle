<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Enum;

/**
 * Where a start or an end came from (FR-011: client times are kept with their arrival time).
 */
enum SegmentSource: string
{
    case Web        = 'web';
    case Desktop    = 'desktop';
    case Extension  = 'extension';
    case Correction = 'correction';
    case Auto       = 'auto';
}
