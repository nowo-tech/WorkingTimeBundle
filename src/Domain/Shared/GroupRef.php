<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Shared;

/**
 * A group of workers (schedules, team views), as the host identifies it. Opaque, compared byte for byte.
 */
final readonly class GroupRef
{
    use OpaqueRefTrait;
}
