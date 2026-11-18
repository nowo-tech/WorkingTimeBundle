<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Leave\Port;

/**
 * The seeded type names in every shipped locale (the bundle's translations).
 */
interface LeaveSeedNamesInterface
{
    /**
     * @return array<string, string> locale => name of the seed code
     */
    public function names(string $seedCode): array;
}
