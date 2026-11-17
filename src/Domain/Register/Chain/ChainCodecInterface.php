<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Chain;

use Nowo\WorkingTimeBundle\Domain\Register\Enum\ChainFormat;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkdayRevision;

/**
 * The canonical form of a chain link in one hash format (contract section 6.3). The verifier dispatches on the link's
 * `chain_format`.
 */
interface ChainCodecInterface
{
    /**
     * The format this codec encodes.
     */
    public function format(): ChainFormat;

    /**
     * The canonical bytes the link's hash covers.
     */
    public function canonical(WorkdayRevision $revision): string;
}
