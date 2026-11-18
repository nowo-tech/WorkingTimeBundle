<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Holiday;

/**
 * What a confirmed import changed: days added and days removed (a provider's days it no longer lists).
 */
final readonly class ImportResult
{
    /**
     * Wires the counts.
     */
    public function __construct(public int $added, public int $removed = 0, public bool $provisional = false)
    {
    }
}
