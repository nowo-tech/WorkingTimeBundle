<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle;

use Nowo\WorkingTimeBundle\Infrastructure\Symfony\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\DependencyInjection\ConfigurationTree;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\DependencyInjection\ParameterFlattener;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function is_array;
use function is_string;

/**
 * Working time for Symfony: a working-hours register, public holidays and leave in one bundle with three parts that can
 * be switched on separately (register on, holidays on, leave off by default).
 *
 * S0 is the scaffold: the bundle class, the strict configuration tree, the three part toggles and the private entity
 * manager wiring. The domain arrives slice by slice (docs/ROADMAP.md).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoWorkingTimeBundle extends AbstractBundle
{
    /** Configuration root and container parameter prefix. */
    public const ALIAS = 'nowo_working_time';

    /** Translation domain of the bundle UI copy (REQ-I18N-003) and Twig namespace (REQ-TWIG-002). */
    public const TRANSLATION_DOMAIN = 'NowoWorkingTimeBundle';

    protected string $extensionAlias = self::ALIAS;

    /**
     * Adds the compiler pass that checks the private entity manager.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new PrivateEntityManagerPass());
    }

    /**
     * Defines the strict configuration tree (contract section 10).
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        ConfigurationTree::define($definition->rootNode());
    }

    /**
     * Publishes the validated configuration as container parameters and aliases the private entity manager.
     *
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        foreach (ParameterFlattener::flatten(self::ALIAS, $config) as $name => $value) {
            $container->parameters()->set($name, $value);
        }
        $doctrine = $config['doctrine'] ?? [];
        $manager  = is_array($doctrine) ? ($doctrine['entity_manager'] ?? null) : null;
        $builder->setAlias(
            self::ALIAS . '.entity_manager',
            is_string($manager) && $manager !== '' ? 'doctrine.orm.' . $manager . '_entity_manager' : 'doctrine.orm.entity_manager',
        )->setPublic(true);
    }
}
