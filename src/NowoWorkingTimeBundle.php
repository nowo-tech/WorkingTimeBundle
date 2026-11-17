<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle;

use InvalidArgumentException;
use Nowo\WorkingTimeBundle\Domain\Register\Model\ScheduleDefinition;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\DependencyInjection\Compiler\WorkingTimePortsPass;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\DependencyInjection\ConfigurationTree;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\DependencyInjection\ParameterFlattener;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\DependencyInjection\ServiceDefinitions;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function array_key_exists;
use function is_array;
use function is_string;

/**
 * Working time for Symfony: a working-hours register (art. 34.9 ET) with a tamper-evident hash chain, public holidays
 * and leave in one bundle with three parts that can be switched on separately (register on, holidays on, leave off
 * by default).
 *
 * 0.1 (S1) ships the register: the append-only record (database triggers on MySQL, MariaDB, PostgreSQL and SQLite),
 * the hash chain in Beacon's and the bundle's formats with purge anchors and the verifier, clocking, schedules,
 * corrections, settings, exports, notices, the retention purge, the UiKit + FormKit pages and the TimeTrack, UserKit
 * and Dompdf bridges. Holidays and leave arrive with S2 / S3 (docs/ROADMAP.md).
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

    /** FormKit profile of the bundle's forms (REQ-TWIG-005 rule 11). */
    public const FORM_PROFILE = 'working_time';

    /** Doctrine mapping name. */
    public const MAPPING_NAME = 'NowoWorkingTimeBundle';

    protected string $extensionAlias = self::ALIAS;

    /**
     * Adds the compiler passes (private entity manager check, ports and optional infrastructure).
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new PrivateEntityManagerPass());
        $container->addCompilerPass(new WorkingTimePortsPass());
    }

    /**
     * Defines the strict configuration tree (contract section 10).
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        ConfigurationTree::define($definition->rootNode());
    }

    /**
     * Registers the XML mapping on the bundle's entity manager and the FormKit profile of the forms.
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('nowo_form_kit')) {
            $declared = false;
            foreach ($builder->getExtensionConfig('nowo_form_kit') as $config) {
                $declared = $declared || (is_array($config) && is_array($config['profiles'] ?? null) && array_key_exists(self::FORM_PROFILE, $config['profiles']));
            }
            if (!$declared) {
                $builder->prependExtensionConfig('nowo_form_kit', ['profiles' => [self::FORM_PROFILE => [
                    'alias'                           => self::FORM_PROFILE,
                    'translation_domain'              => self::TRANSLATION_DOMAIN,
                    'convention_requires_translation' => true,
                    'defaults'                        => ['attr' => ['class' => 'nowo-ui-input'], 'row_attr' => []],
                ]]]);
            }
        }
        if (!$builder->hasExtension('doctrine')) {
            return;
        }
        // Only the doctrine subtree is read here: the rest may hold env placeholders resolved later.
        $manager = self::ALIAS;
        foreach ($builder->getExtensionConfig(self::ALIAS) as $config) {
            $doctrine = is_array($config) && is_array($config['doctrine'] ?? null) ? $config['doctrine'] : [];
            if (array_key_exists('entity_manager', $doctrine)) {
                $manager = is_string($doctrine['entity_manager']) && $doctrine['entity_manager'] !== '' ? $doctrine['entity_manager'] : 'default';
            }
        }
        if ($manager !== 'default' && !self::declares($builder, $manager)) {
            // The private entity manager is the host's to declare (the Flex recipe does): PrivateEntityManagerPass
            // fails the build with the fix instead of creating one silently.
            return;
        }
        $builder->prependExtensionConfig('doctrine', ['orm' => ['entity_managers' => [$manager => ['mappings' => [self::MAPPING_NAME => [
            'type'      => 'xml',
            'dir'       => __DIR__ . '/Resources/config/doctrine',
            'prefix'    => 'Nowo\WorkingTimeBundle\Domain\Register\Model',
            'is_bundle' => false,
        ]]]]]]);
    }

    /**
     * Whether the host's Doctrine configuration declares the entity manager.
     */
    private static function declares(ContainerBuilder $builder, string $manager): bool
    {
        foreach ($builder->getExtensionConfig('doctrine') as $doctrine) {
            $orm = is_array($doctrine) && is_array($doctrine['orm'] ?? null) ? $doctrine['orm'] : [];
            if (is_array($orm['entity_managers'] ?? null) && array_key_exists($manager, $orm['entity_managers'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Publishes the validated configuration as container parameters, aliases the entity manager and registers the
     * services.
     *
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $parameters = $container->parameters();
        foreach (ParameterFlattener::flatten(self::ALIAS, $config) as $name => $value) {
            $parameters->set($name, $value);
        }
        /** @var array{permissions: array{roles: array<string, list<string>>}, doctrine: array{entity_manager: ?string}, integrity: array{triggers: string}, register: array{schedule: array{default: mixed, default_timezone: string}, late_client_minutes: int, missing_clock_out_grace_minutes: int, long_day_hours: int, ip_evidence: string}, ui: array{layout: ?string}} $config */
        $default = $config['register']['schedule']['default'];
        if ($default !== null) {
            try {
                ScheduleDefinition::fromArray(is_array($default) ? $default : [], $config['register']['schedule']['default_timezone']);
            } catch (InvalidArgumentException $e) {
                throw new InvalidConfigurationException('nowo_working_time.register.schedule.default is invalid: ' . $e->getMessage(), 0, $e);
            }
        }
        $p = self::ALIAS . '.';
        $parameters->set($p . 'register.schedule.default', is_array($default) ? $default : null);
        $parameters->set($p . 'permissions.roles.map', $config['permissions']['roles']);
        $parameters->set($p . 'register.late_client_seconds', $config['register']['late_client_minutes'] * 60);
        $parameters->set($p . 'register.missing_clock_out_grace_seconds', $config['register']['missing_clock_out_grace_minutes'] * 60);
        $parameters->set($p . 'register.long_day_seconds', $config['register']['long_day_hours'] * 3600);
        $parameters->set($p . 'register.ip_evidence_allowed', $config['register']['ip_evidence'] === 'prefix');
        $parameters->set($p . 'integrity.require_triggers', $config['integrity']['triggers'] === 'require');
        $parameters->set($p . 'ui.layout_template', $config['ui']['layout']);

        $manager = $config['doctrine']['entity_manager'];
        $builder->setAlias(
            self::ALIAS . '.entity_manager',
            is_string($manager) && $manager !== '' ? 'doctrine.orm.' . $manager . '_entity_manager' : 'doctrine.orm.entity_manager',
        )->setPublic(true);
        ServiceDefinitions::register($container, $config);
    }
}
