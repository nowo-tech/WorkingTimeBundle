<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\DependencyInjection\Compiler\PrivateEntityManagerPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class PrivateEntityManagerPassTest extends TestCase
{
    public function testDoctrineBundleIsRequired(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('needs DoctrineBundle');

        (new PrivateEntityManagerPass())->process($this->container(null, false));
    }

    public function testADeclaredEntityManagerPasses(): void
    {
        $container = $this->container('nowo_working_time');
        $container->register('doctrine.orm.nowo_working_time_entity_manager', 'stdClass');

        (new PrivateEntityManagerPass())->process($container);

        $this->addToAssertionCount(1);
    }

    public function testANullOrEmptyNameUsesTheDefaultManagerWithoutChecks(): void
    {
        (new PrivateEntityManagerPass())->process($this->container(null));
        (new PrivateEntityManagerPass())->process($this->container(''));

        $this->addToAssertionCount(1);
    }

    public function testAMissingEntityManagerFailsWithTheFix(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('doctrine.orm.entity_managers.nowo_working_time is not declared');

        (new PrivateEntityManagerPass())->process($this->container('nowo_working_time'));
    }

    private function container(?string $manager, bool $doctrine = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_working_time.doctrine.entity_manager', $manager);
        if ($doctrine) {
            $container->register('doctrine', 'stdClass');
        }

        return $container;
    }
}
