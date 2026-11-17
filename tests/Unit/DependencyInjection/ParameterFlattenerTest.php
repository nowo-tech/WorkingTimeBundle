<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\DependencyInjection;

use Nowo\WorkingTimeBundle\Infrastructure\Symfony\DependencyInjection\ParameterFlattener;
use PHPUnit\Framework\TestCase;

final class ParameterFlattenerTest extends TestCase
{
    public function testAssociativeArraysAreWalkedAndListsAndEmptyArraysArePublishedAsTheyAre(): void
    {
        $parameters = ParameterFlattener::flatten('p', [
            'a'     => ['b' => 1, 'c' => ['d' => null]],
            'list'  => ['x', 'y'],
            'empty' => [],
            'flag'  => true,
        ]);

        self::assertSame(['p.a.b' => 1, 'p.a.c.d' => null, 'p.list' => ['x', 'y'], 'p.empty' => [], 'p.flag' => true], $parameters);
    }
}
