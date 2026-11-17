<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register\Port;

/**
 * Builds the file of a pending export (claims it first: one generator wins).
 */
interface ExportGeneratorInterface
{
    /**
     * Generates the export; false when another generator claimed it or it is not pending.
     */
    public function generate(string $exportId): bool;
}
