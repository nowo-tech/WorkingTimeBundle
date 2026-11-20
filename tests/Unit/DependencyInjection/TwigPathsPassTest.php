<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\DependencyInjection;

use Nowo\WorkingTimeBundle\Infrastructure\Symfony\DependencyInjection\Compiler\TwigPathsPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

use function dirname;
use function file_exists;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;

final class TwigPathsPassTest extends TestCase
{
    public function testNothingHappensWithoutTwig(): void
    {
        $container = new ContainerBuilder();

        (new TwigPathsPass())->process($container);

        self::assertFalse($container->hasDefinition('twig.loader.native_filesystem'));
    }

    public function testTheBundleViewsAreRegisteredUnderTheBundleNamespace(): void
    {
        $container = $this->container();

        (new TwigPathsPass())->process($container);

        $calls = $container->getDefinition('twig.loader.native_filesystem')->getMethodCalls();
        self::assertSame([['addPath', [dirname(__DIR__, 3) . '/src/Resources/views', 'NowoWorkingTimeBundle']]], $calls);
        self::assertDirectoryExists(dirname(__DIR__, 3) . '/src/Resources/views');
    }

    public function testTheHostOverrideDirectoryComesFirstWhenItExists(): void
    {
        $project  = sys_get_temp_dir() . '/wt-twig-' . uniqid('', true);
        $override = $project . '/templates/bundles/NowoWorkingTimeBundle';
        mkdir($override, 0o777, true);

        try {
            $container = $this->container($project);
            (new TwigPathsPass())->process($container);

            $calls = $container->getDefinition('twig.loader.native_filesystem')->getMethodCalls();
            self::assertSame(
                [
                    ['prependPath', [$override, 'NowoWorkingTimeBundle']],
                    ['addPath', [dirname(__DIR__, 3) . '/src/Resources/views', 'NowoWorkingTimeBundle']],
                ],
                $calls,
            );
        } finally {
            rmdir($override);
            rmdir($project . '/templates/bundles');
            rmdir($project . '/templates');
            rmdir($project);
        }
        self::assertFalse(file_exists($project));
    }

    private function container(?string $projectDir = '/nonexistent-project'): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition('twig.loader.native_filesystem', new Definition('stdClass'));
        if ($projectDir !== null) {
            $container->setParameter('kernel.project_dir', $projectDir);
        }

        return $container;
    }
}
