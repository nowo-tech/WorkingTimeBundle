<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Enum;

/**
 * Hash encoding of a chain link (contract F3, section 6.3, Q-O4): format 1 is Beacon's (integer ids frozen in
 * `legacy_refs`), format 2 the bundle's (string references). Migrated links are never re-hashed.
 */
enum ChainFormat: int
{
    case Beacon = 1;
    case Bundle = 2;
}
