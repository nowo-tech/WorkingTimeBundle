<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Fixtures;

/**
 * What the second writer of a two-connection race did.
 */
final class RaceOutcome
{
    public bool $fired = false;

    public string $result = 'not run';
}
