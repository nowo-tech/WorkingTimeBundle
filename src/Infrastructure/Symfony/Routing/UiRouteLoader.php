<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Routing;

use Nowo\WorkingTimeBundle\Domain\Register\Enum\ClockAction;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Controller\ExportController;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Controller\RegisterController;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Controller\ScheduleController;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Controller\SettingsController;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Controller\TeamController;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function rtrim;

/**
 * Route loader `nowo_working_time_ui` (contract section 11.1): the register pages under `ui.register_path` and the
 * settings page at `ui.settings_path`, only when `ui.enabled` (REQ-UI-001: routes are opt-in). The host imports it
 * with its prefix: `{resource: ., type: nowo_working_time_ui}`. Route names `nowo_working_time_*`.
 */
final class UiRouteLoader extends Loader
{
    /** Loader type. */
    public const TYPE = 'nowo_working_time_ui';

    /** Route attribute that marks the bundle's routes (conflict formatter). */
    public const ROUTE_ATTRIBUTE = '_nowo_working_time';

    public function __construct(
        private readonly bool $enabled = false,
        private readonly string $registerPath = '/working-time',
        private readonly string $settingsPath = '/working-time/settings',
    ) {
        parent::__construct();
    }

    /**
     * The routes (none while the UI is off).
     */
    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = new RouteCollection();
        if (!$this->enabled) {
            return $routes;
        }
        $base = rtrim($this->registerPath, '/');
        $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
        $date = '\d{4}-\d{2}-\d{2}';
        $add  = static function (string $name, string $path, string $controller, array $methods, array $requirements = [], array $defaults = []) use ($routes): void {
            $routes->add('nowo_working_time_' . $name, new Route($path, ['_controller' => $controller, self::ROUTE_ATTRIBUTE => true] + $defaults, $requirements, [], '', [], $methods));
        };
        $add('settings', $this->settingsPath, SettingsController::class . '::settings', ['GET', 'POST']);
        $add('preferences', $base . '/preferences', SettingsController::class . '::preferences', ['GET', 'POST']);
        $add('today', $base === '' ? '/' : $base, RegisterController::class . '::today', ['GET']);
        $add('clock', $base . '/clock/{action}', RegisterController::class . '::clock', ['POST'], ['action' => ClockAction::REQUIREMENT]);
        $add('month_current', $base . '/month', RegisterController::class . '::month', ['GET']);
        $add('month', $base . '/month/{month}', RegisterController::class . '::month', ['GET'], ['month' => '\d{4}-\d{2}']);
        $add('day', $base . '/day/{id}', RegisterController::class . '::day', ['GET'], ['id' => $uuid]);
        $add('date', $base . '/date/{date}', RegisterController::class . '::date', ['GET'], ['date' => $date]);
        $add('correction_request', $base . '/date/{date}/correction', RegisterController::class . '::requestCorrection', ['POST'], ['date' => $date]);
        $add('correction_answer', $base . '/corrections/{id}/answer', RegisterController::class . '::answer', ['POST'], ['id' => $uuid]);
        $add('team', $base . '/team', TeamController::class . '::team', ['GET']);
        $add('team_day', $base . '/team/day', TeamController::class . '::teamDay', ['GET']);
        $add('correction_record', $base . '/team/record', TeamController::class . '::record', ['POST']);
        $add('correction_decide', $base . '/requests/{id}/{decision}', TeamController::class . '::decide', ['POST'], ['id' => $uuid, 'decision' => 'approve|reject']);
        $add('schedules', $base . '/schedules', ScheduleController::class . '::schedules', ['GET', 'POST']);
        $add('schedule_withdraw', $base . '/schedules/{id}/withdraw', ScheduleController::class . '::withdraw', ['POST'], ['id' => $uuid]);
        $add('exports', $base . '/exports', ExportController::class . '::exports', ['GET', 'POST']);
        $add('export_download', $base . '/exports/{id}/download', ExportController::class . '::download', ['GET'], ['id' => $uuid]);

        return $routes;
    }

    /**
     * Whether the resource is this loader's.
     */
    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }
}
