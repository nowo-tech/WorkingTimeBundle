<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\DependencyInjection\Compiler;

use Nowo\WorkingTimeBundle\NowoWorkingTimeBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function dirname;
use function is_dir;
use function is_string;
use function rtrim;

/**
 * Registers the bundle templates under the `@NowoWorkingTimeBundle` Twig namespace (REQ-TWIG-002), the same string as
 * the translation domain, and the host override directory `templates/bundles/NowoWorkingTimeBundle/` ahead of them
 * (REQ-TWIG-001). Symfony's automatic `@NowoWorkingTime` namespace keeps working as the legacy alias.
 */
final class TwigPathsPass implements CompilerPassInterface
{
    /**
     * Adds the namespace to the native Twig loader when Twig is installed.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('twig.loader.native_filesystem')) {
            return;
        }

        $definition = $container->getDefinition('twig.loader.native_filesystem');
        $projectDir = $container->hasParameter('kernel.project_dir') ? $container->getParameter('kernel.project_dir') : null;
        if (is_string($projectDir)) {
            $override = rtrim($projectDir, '/\\') . '/templates/bundles/' . NowoWorkingTimeBundle::TRANSLATION_DOMAIN;
            if (is_dir($override)) {
                // @igor-ignore - Container compile-time DI mutation; not runtime worker state.
                $definition->addMethodCall('prependPath', [$override, NowoWorkingTimeBundle::TRANSLATION_DOMAIN]);
            }
        }

        // @igor-ignore - Container compile-time DI mutation; not runtime worker state.
        $definition->addMethodCall('addPath', [dirname(__DIR__, 4) . '/Resources/views', NowoWorkingTimeBundle::TRANSLATION_DOMAIN]);
    }
}
