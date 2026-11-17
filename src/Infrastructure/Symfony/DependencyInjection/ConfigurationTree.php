<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;

use function array_keys;
use function array_unique;
use function count;
use function is_array;
use function is_string;
use function str_starts_with;

/**
 * The strict configuration tree of `nowo_working_time` (REQ-SF-006, contract section 10): unknown keys fail, every key
 * has its documented default. S0 reserves the whole tree; each slice starts reading its part.
 */
final class ConfigurationTree
{
    /** Permission keys of the role map (deny by default: every list is empty). */
    public const PERMISSIONS = ['clock', 'manage_team', 'export', 'manage_settings', 'leave_request', 'leave_approve', 'leave_view_team', 'leave_manage', 'holidays_manage'];

    /**
     * Fills the root node.
     */
    public static function define(ArrayNodeDefinition $root): void
    {
        $root->validate()
            ->ifTrue(static fn (array $v): bool => self::enabled($v, 'leave') && (!self::enabled($v, 'register') || !self::enabled($v, 'holidays')))
            ->thenInvalid('nowo_working_time.leave.enabled requires nowo_working_time.register.enabled and nowo_working_time.holidays.enabled.')
            ->end();
        $children = $root->addDefaultsIfNotSet()->children();
        foreach (['tenant_resolver', 'permission_checker', 'worker_directory', 'brand_provider', 'clock'] as $port) {
            $children->scalarNode($port)->defaultNull()->info('Service id of the host implementation; null = the bundle default.')->end();
        }
        $children->scalarNode('notifier')->defaultValue('auto')->info('auto | null | <service id>')->end();
        $children->scalarNode('audit_sink')->defaultValue('auto')->info('auto | <service id>')->end();
        $children->scalarNode('notifier_from')->defaultValue('no-reply@localhost')->info('Sender address of the default Mailer notifier.')->end();
        self::permissions($children);
        self::doctrine($children);
        self::integrity($children);
        self::register($children);
        self::holidays($children);
        self::leave($children);
        self::ui($children);
        self::api($children);
        $children->arrayNode('messenger')->addDefaultsIfNotSet()->children()
            ->scalarNode('transport')->defaultNull()->end()
        ->end()->end();
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private static function enabled(array $config, string $part): bool
    {
        $section = $config[$part] ?? null;

        return is_array($section) && ($section['enabled'] ?? false) === true;
    }

    private static function permissions(NodeBuilder $children): void
    {
        $permissions = $children->arrayNode('permissions')->addDefaultsIfNotSet()->children();
        $roles       = $permissions->arrayNode('roles')->addDefaultsIfNotSet()->children();
        foreach (self::PERMISSIONS as $permission) {
            $roles->arrayNode($permission)->scalarPrototype()->cannotBeEmpty()->end()->defaultValue([])->end();
        }
        $roles->end()->end();
        $permissions->integerNode('not_available_status')->defaultValue(404)->info('Fixed: a tenant without the feature answers 404.')
            ->validate()->ifTrue(static fn (int $v): bool => $v !== 404)->thenInvalid('not_available_status is fixed to 404.')->end()
        ->end();
        $permissions->end()->end();
    }

    private static function doctrine(NodeBuilder $children): void
    {
        $children->arrayNode('doctrine')->addDefaultsIfNotSet()->children()
            ->scalarNode('entity_manager')->defaultValue('nowo_working_time')->info('Private entity manager declared by the Flex recipe; null = the default one with explicit transactions.')->end()
            ->scalarNode('table_prefix')->defaultValue('nowo_')->end()
            ->enumNode('mapping')->values(['xml', 'attribute'])->defaultValue('xml')->end()
        ->end()->end();
    }

    private static function integrity(NodeBuilder $children): void
    {
        $children->arrayNode('integrity')
            ->addDefaultsIfNotSet()
            ->validate()
                ->ifTrue(static fn (array $v): bool => $v['purge_guard'] === 'db_user' && ($v['purge_db_user'] ?? null) === null)
                ->thenInvalid('integrity.purge_db_user is required when integrity.purge_guard is "db_user".')
            ->end()
            ->children()
                ->enumNode('triggers')->values(['warn', 'require', 'off'])->defaultValue('warn')->end()
                ->enumNode('purge_guard')->values(['session_variable', 'db_user'])->defaultValue('session_variable')->end()
                ->scalarNode('purge_db_user')->defaultNull()->end()
                ->scalarNode('purge_connection')->defaultNull()->end()
                ->booleanNode('verify_before_export')->defaultTrue()->end()
            ->end()
        ->end();
    }

    private static function register(NodeBuilder $children): void
    {
        $register = $children->arrayNode('register')->addDefaultsIfNotSet()->children();
        $register->booleanNode('enabled')->defaultTrue()->end();
        $register->arrayNode('schedule')->addDefaultsIfNotSet()->children()
            ->scalarNode('default_timezone')->defaultValue('Europe/Madrid')->end()
            ->variableNode('default')->defaultNull()->info('{ ranges: {mon: [...]} } or { daily_target_minutes, weekly_target_minutes, flex_minutes }')->end()
        ->end()->end();
        $register->integerNode('missing_clock_out_grace_minutes')->defaultValue(120)->min(30)->max(480)->end();
        $register->integerNode('long_day_hours')->defaultValue(16)->min(1)->max(24)->end();
        $register->integerNode('late_client_minutes')->defaultValue(10)->min(0)->end();
        $register->integerNode('correction_window_days')->defaultValue(31)->min(1)->max(31)->end();
        $register->integerNode('retention_years')->defaultValue(4)->min(4)->max(10)->info('Tenants may set 4..10; values below 4 are refused (art. 34.9 ET).')->end();
        $register->enumNode('ip_evidence')->values(['off', 'prefix'])->defaultValue('prefix')->end();
        $register->integerNode('ipv4_prefix')->defaultValue(24)->min(24)->max(24)->info('Fixed range 24.')->end();
        $register->integerNode('ipv6_prefix')->defaultValue(64)->min(64)->max(64)->end();
        $register->arrayNode('reminders')->addDefaultsIfNotSet()->children()
            ->integerNode('window_minutes')->defaultValue(15)->min(1)->end()
        ->end()->end();
        $register->arrayNode('monthly_summary')->addDefaultsIfNotSet()->children()
            ->booleanNode('enabled')->defaultTrue()->end()
        ->end()->end();
        $export = $register->arrayNode('export')->addDefaultsIfNotSet()->children();
        $export->arrayNode('formats')->enumPrototype()->values(['pdf', 'csv', 'zip'])->end()->defaultValue(['pdf', 'csv'])->end();
        $export->integerNode('max_months')->defaultValue(12)->min(1)->end();
        $export->integerNode('download_days')->defaultValue(7)->min(1)->end();
        $export->enumNode('async')->values(['auto', 'sync', 'messenger'])->defaultValue('auto')->end();
        self::pdf($export);
        $export->end()->end();
        $register->enumNode('task_timer_link')->values(['auto', 'off'])->defaultValue('auto')->end();
        $register->end()->end();
    }

    /**
     * PDF rendering is closed (REQ-REPORT-001, Beacon Q-B6): no remote resources, no JavaScript, no PHP. The switches
     * exist so the posture is visible in the configuration; turning one on is refused.
     */
    private static function pdf(NodeBuilder $export): void
    {
        $pdf = $export->arrayNode('pdf')
            ->addDefaultsIfNotSet()
            ->validate()
                ->ifTrue(static fn (array $v): bool => $v['remote_resources'] || $v['javascript'] || $v['php'])
                ->thenInvalid('The PDF renderer is closed (REQ-REPORT-001): remote_resources, javascript and php cannot be enabled.')
            ->end()
            ->children();
        $pdf->scalarNode('renderer')->defaultValue('dompdf')->info('dompdf (default, needs dompdf/dompdf) or the service id of a RegisterPdfRendererInterface.')->end();
        $pdf->booleanNode('remote_resources')->defaultFalse()->info('Fixed false: no remote fetches while rendering.')->end();
        $pdf->booleanNode('javascript')->defaultFalse()->info('Fixed false: no JavaScript.')->end();
        $pdf->booleanNode('php')->defaultFalse()->info('Fixed false: no inline PHP.')->end();
        $pdf->scalarNode('chroot')->defaultNull()->info('Directory the renderer may read; null = the bundle assets only.')->end();
        $pdf->end()->end();
    }

    private static function holidays(NodeBuilder $children): void
    {
        $holidays = $children->arrayNode('holidays')->addDefaultsIfNotSet()->children();
        $holidays->booleanNode('enabled')->defaultTrue()->end();
        $holidays->scalarNode('http_client')->defaultNull()->end();
        $holidays->scalarNode('outbound_guard')->defaultValue('Nowo\OutboundUrlGuardBundle\Guard\RuntimeOutboundUrlGuard')->end();
        $holidays->scalarNode('cache_pool')->defaultValue('cache.app')->end();
        $providers = $holidays->arrayNode('providers')->addDefaultsIfNotSet()->children();
        $providers->arrayNode('festivos_io')->addDefaultsIfNotSet()->children()
            ->booleanNode('enabled')->defaultTrue()->end()
            ->scalarNode('base_uri')->defaultValue('https://festivos.io')->validate()->ifTrue(static fn (string $v): bool => !str_starts_with($v, 'https://'))->thenInvalid('base_uri must be an https URL.')->end()->end()
            ->integerNode('timeout')->defaultValue(10)->min(1)->end()
            ->integerNode('municipalities_ttl')->defaultValue(604800)->min(0)->end()
        ->end()->end();
        $providers->arrayNode('openholidays')->addDefaultsIfNotSet()->children()
            ->booleanNode('enabled')->defaultTrue()->end()
            ->scalarNode('base_uri')->defaultValue('https://openholidaysapi.org')->validate()->ifTrue(static fn (string $v): bool => !str_starts_with($v, 'https://'))->thenInvalid('base_uri must be an https URL.')->end()->end()
            ->integerNode('timeout')->defaultValue(10)->min(1)->end()
            ->integerNode('subdivisions_ttl')->defaultValue(86400)->min(0)->end()
        ->end()->end();
        $providers->arrayNode('file')->addDefaultsIfNotSet()->children()
            ->booleanNode('enabled')->defaultTrue()->end()
            ->integerNode('max_rows')->defaultValue(200)->min(1)->end()
            ->integerNode('max_bytes')->defaultValue(262144)->min(1)->end()
        ->end()->end();
        $providers->end()->end();
        $holidays->end()->end();
    }

    private static function leave(NodeBuilder $children): void
    {
        $leave = $children->arrayNode('leave')->addDefaultsIfNotSet()->children();
        $leave->booleanNode('enabled')->defaultFalse()->info('Requires register.enabled and holidays.enabled.')->end();
        $leave->arrayNode('lock')
            ->addDefaultsIfNotSet()
            ->validate()
                ->ifTrue(static fn (array $v): bool => $v['store'] === 'lock_factory' && ($v['lock_factory'] ?? null) === null)
                ->thenInvalid('leave.lock.lock_factory (a service id of a shared lock store) is required when leave.lock.store is "lock_factory".')
            ->end()
            ->children()
                ->enumNode('store')->values(['db_row', 'lock_factory'])->defaultValue('db_row')->info('db_row: row lock in the bundle table (default); lock_factory: symfony/lock key leave:<org>:<user>.')->end()
                ->scalarNode('lock_factory')->defaultNull()->info('Service id of the lock factory when store is lock_factory (must be a shared store: Redis, PDO).')->end()
                ->integerNode('wait_seconds')->defaultValue(5)->min(1)->max(30)->end()
                ->integerNode('ttl_seconds')->defaultValue(30)->min(1)->end()
            ->end()
        ->end();
        $leave->arrayNode('approval')->addDefaultsIfNotSet()->children()
            ->scalarNode('flow')->defaultValue('auto')->info('auto | builtin | <service id>')->end()
            ->scalarNode('workflow_slug')->defaultValue('leave_approval')->end()
            ->integerNode('max_chain_steps')->defaultValue(3)->min(1)->max(3)->end()
        ->end()->end();
        $leave->arrayNode('attachments')->addDefaultsIfNotSet()
            ->validate()->ifTrue(static fn (array $v): bool => $v['enabled'] && ($v['store'] ?? null) === null)->thenInvalid('leave.attachments.store is required when leave.attachments.enabled is true.')->end()
            ->children()
                ->booleanNode('enabled')->defaultFalse()->end()
                ->scalarNode('store')->defaultNull()->end()
                ->integerNode('max_bytes')->defaultValue(10485760)->min(1)->end()
                ->arrayNode('mime_types')->scalarPrototype()->end()->defaultValue(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])->end()
            ->end()
        ->end();
        $leave->arrayNode('ical')->addDefaultsIfNotSet()->children()
            ->booleanNode('enabled')->defaultTrue()->end()
            ->integerNode('cache_seconds')->defaultValue(900)->min(0)->end()
        ->end()->end();
        $leave->arrayNode('staffing')->addDefaultsIfNotSet()->children()->booleanNode('enabled')->defaultTrue()->end()->end()->end();
        $leave->arrayNode('planning_reminders')->addDefaultsIfNotSet()->children()
            ->arrayNode('days_before')->integerPrototype()->min(1)->end()->defaultValue([30, 7])->end()
        ->end()->end();
        $leave->integerNode('stale_pending_reminder_working_days')->defaultValue(3)->min(1)->end();
        $leave->arrayNode('payroll_csv')->addDefaultsIfNotSet()->children()->booleanNode('enabled')->defaultTrue()->end()->end()->end();
        $leave->end()->end();
    }

    private static function ui(NodeBuilder $children): void
    {
        $paths = ['register_path' => '/working-time', 'leave_path' => '/leave', 'holidays_path' => '/holidays', 'settings_path' => '/working-time/settings'];
        $ui    = $children->arrayNode('ui')
            ->addDefaultsIfNotSet()
            ->validate()
                ->ifTrue(static function (array $v) use ($paths): bool {
                    $values = [];
                    foreach (array_keys($paths) as $key) {
                        if (is_string($v[$key] ?? null)) {
                            $values[] = $v[$key];
                        }
                    }

                    return count($values) !== count(array_unique($values));
                })
                ->thenInvalid('The ui paths must be distinct.')
            ->end()
            ->children();
        $ui->booleanNode('enabled')->defaultFalse()->info('Routes are opt-in (REQ-UI-001).')->end();
        foreach ($paths as $key => $default) {
            $ui->scalarNode($key)->defaultValue($default)
                ->validate()->ifTrue(static fn (string $v): bool => !str_starts_with($v, '/'))->thenInvalid('ui paths must start with "/".')->end()
            ->end();
        }
        $ui->integerNode('page_size')->defaultValue(25)->min(10)->max(100)->end();
        $ui->scalarNode('layout')->defaultNull()->info('Layout of the pages; null = UiKit\'s nowo_ui_layout.admin (the host\'s configured layout).')->end();
        $ui->end()->end();
    }

    private static function api(NodeBuilder $children): void
    {
        $children->arrayNode('api')->addDefaultsIfNotSet()->children()
            ->scalarNode('prefix')->defaultValue('/api')->end()
            ->scalarNode('ical_path')->defaultValue('/working-time/v1/ical')->end()
        ->end()->end();
    }
}
