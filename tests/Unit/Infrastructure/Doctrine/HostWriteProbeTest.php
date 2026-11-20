<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Infrastructure\Doctrine;

use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\HostWriteProbe;
use PHPUnit\Framework\TestCase;

/**
 * Audit UZ8-2: taking collections off Doctrine's scheduled collection deletions fails closed when the unit of work's
 * property is missing or has another shape (a Doctrine change); the real, private one is checked in the Database suite.
 */
final class HostWriteProbeTest extends TestCase
{
    public function testItRemovesTheGivenCollectionsFromTheList(): void
    {
        $unitOfWork = new class {
            /** @var array<int, string> */
            public array $collectionDeletions = [1 => 'a', 2 => 'b', 3 => 'c'];
        };

        self::assertTrue(HostWriteProbe::unscheduleCollectionDeletions($unitOfWork, [1, 3]));
        self::assertSame([2 => 'b'], $unitOfWork->collectionDeletions);
    }

    public function testItFailsClosedWhenThePropertyIsMissing(): void
    {
        self::assertFalse(HostWriteProbe::unscheduleCollectionDeletions(new class {
        }, [1]));
    }

    public function testItFailsClosedWhenThePropertyHasAnotherShape(): void
    {
        $unitOfWork = new class {
            public string $collectionDeletions = 'not a list';
        };

        self::assertFalse(HostWriteProbe::unscheduleCollectionDeletions($unitOfWork, [1]));
        self::assertSame('not a list', $unitOfWork->collectionDeletions, 'nothing touched');
    }
}
