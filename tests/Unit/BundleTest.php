<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit;

use Nowo\WorkingTimeBundle\Infrastructure\Symfony\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\WorkingTimeBundle\NowoWorkingTimeBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class BundleTest extends TestCase
{
    public function testTheExtensionAliasIsTheConfigurationRoot(): void
    {
        $bundle = new NowoWorkingTimeBundle();

        self::assertSame('nowo_working_time', $bundle->getContainerExtension()?->getAlias());
    }

    public function testBuildAddsThePrivateEntityManagerPass(): void
    {
        $container = new ContainerBuilder();
        (new NowoWorkingTimeBundle())->build($container);

        $passes = array_map(static fn (object $pass): string => $pass::class, $container->getCompilerPassConfig()->getBeforeOptimizationPasses());
        self::assertContains(PrivateEntityManagerPass::class, $passes);
        self::assertContains(TwigPathsPass::class, $passes);
    }
}
