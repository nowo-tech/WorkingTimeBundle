<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Fixtures;

/**
 * Counts the attempts of a write unit in a test.
 */
final class AttemptCounter
{
    public int $count = 0;
}
