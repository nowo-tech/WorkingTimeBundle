<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Fixtures;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\WorkingTimeBundle\Tests\Fixtures\HostEntity\HostEvent;
use Nowo\WorkingTimeBundle\Tests\Fixtures\HostEntity\HostKid;
use Nowo\WorkingTimeBundle\Tests\Fixtures\HostEntity\HostLabel;
use Nowo\WorkingTimeBundle\Tests\Fixtures\HostEntity\HostNote;
use Nowo\WorkingTimeBundle\Tests\Fixtures\HostEntity\HostReadonly;
use Nowo\WorkingTimeBundle\Tests\Fixtures\HostEntity\HostTask;
use Nowo\WorkingTimeBundle\Tests\Fixtures\HostEntity\HostVersioned;

/**
 * The host's own tables (the HostEntity fixtures), dropped and created together (their foreign keys link them).
 */
final class HostSchema
{
    /**
     * Drops and creates the host's tables on $manager.
     */
    public static function reset(EntityManagerInterface $manager): void
    {
        $tool = new SchemaTool($manager);
        $meta = self::metadata($manager);
        $tool->dropSchema($meta);
        $tool->createSchema($meta);
    }

    /**
     * @return list<ClassMetadata<object>>
     */
    private static function metadata(EntityManagerInterface $manager): array
    {
        return array_map($manager->getClassMetadata(...), [HostNote::class, HostEvent::class, HostLabel::class, HostVersioned::class, HostReadonly::class, HostTask::class, HostKid::class]);
    }
}
