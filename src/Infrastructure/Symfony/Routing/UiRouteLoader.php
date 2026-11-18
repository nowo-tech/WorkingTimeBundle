<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Routing;

use Nowo\WorkingTimeBundle\Domain\Register\Enum\ClockAction;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Controller\ExportController;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Controller\HolidayController;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Controller\LeaveController;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Controller\RegisterController;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Controller\ScheduleController;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Controller\SettingsController;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Controller\TeamController;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function is_string;
use function rtrim;

/**
 * Route loader `nowo_working_time_ui` (contract section 11.1), only when `ui.enabled` (REQ-UI-001: routes are opt-in):
 * the register pages under `ui.register_path` and its settings at `ui.settings_path` (register on), the holiday pages
 * under `ui.holidays_path` (holidays on; they need neither the register nor leave), the leave pages under
 * `ui.leave_path` and the leave settings at `ui.settings_path`/leave (leave on). The host imports it
 * with its prefix: `{resource: ., type: nowo_working_time_ui}`. Route names `nowo_working_time_*`.
 */
final class UiRouteLoader extends Loader
{
    /** Loader type. */
    public const TYPE = 'nowo_working_time_ui';

    /** Route attribute that marks the bundle's routes (conflict formatter). */
    public const ROUTE_ATTRIBUTE = '_nowo_working_time';

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private readonly bool $enabled = false,
        private readonly string $registerPath = '/working-time',
        private readonly string $settingsPath = '/working-time/settings',
        private readonly bool $register = true,
        private readonly bool $holidays = false,
        private readonly bool $leave = false,
        private readonly string $holidaysPath = '/holidays',
        private readonly string $leavePath = '/leave',
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
        $add  = static function (string $name, string $path, string $controller, array $methods, array $requirements = []) use ($routes): void {
            $routes->add('nowo_working_time_' . $name, self::route($path, $controller, $methods, $requirements));
        };
        if ($this->register) {
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
        }
        if ($this->holidays) {
            $this->holidayRoutes($add, rtrim($this->holidaysPath, '/'), $uuid);
        }
        if ($this->leave) {
            $leave = rtrim($this->leavePath, '/');
            $add('leave', $leave === '' ? '/' : $leave, LeaveController::class . '::index', ['GET']);
            $add('leave_manage', $leave . '/manage', LeaveController::class . '::types', ['GET', 'POST']);
            $add('leave_seed', $leave . '/manage/seed', LeaveController::class . '::seed', ['POST']);
            $add('leave_type', $leave . '/manage/types/{id}', LeaveController::class . '::type', ['GET', 'POST'], ['id' => $uuid]);
            $add('leave_type_archive', $leave . '/manage/types/{id}/archive', LeaveController::class . '::archive', ['POST'], ['id' => $uuid]);
            $add('leave_allowances', $leave . '/manage/allowances', LeaveController::class . '::allowances', ['GET', 'POST']);
            $add('leave_policy_remove', $leave . '/manage/allowances/{id}/remove', LeaveController::class . '::removePolicy', ['POST'], ['id' => $uuid]);
            $add('leave_settings', rtrim($this->settingsPath, '/') . '/leave', LeaveController::class . '::settings', ['GET', 'POST']);
        }

        return $routes;
    }

    /**
     * The holiday component's pages (stand-alone: they need neither the register nor leave).
     *
     * @param callable(string, string, string, list<string>, array<string, string>=): void $add
     */
    private function holidayRoutes(callable $add, string $base, string $uuid): void
    {
        $controller = HolidayController::class;
        $add('holidays', $base === '' ? '/' : $base, $controller . '::index', ['GET', 'POST']);
        $add('holiday_municipalities', $base . '/municipalities', $controller . '::municipalities', ['GET']);
        $add('holiday_municipalities_refresh', $base . '/municipalities/refresh', $controller . '::refreshMunicipalities', ['POST']);
        $add('holiday_centre', $base . '/centres/{id}', $controller . '::centre', ['GET', 'POST'], ['id' => $uuid]);
        $add('holiday_centre_remove', $base . '/centres/{id}/remove', $controller . '::removeCentre', ['POST'], ['id' => $uuid]);
        $add('holiday_calendar', $base . '/{id}', $controller . '::calendar', ['GET', 'POST'], ['id' => $uuid]);
        $add('holiday_calendar_remove', $base . '/{id}/remove', $controller . '::removeCalendar', ['POST'], ['id' => $uuid]);
        $add('holiday_day_add', $base . '/{id}/add', $controller . '::addDay', ['POST'], ['id' => $uuid]);
        $add('holiday_day_edit', $base . '/{id}/holiday/{day}/edit', $controller . '::editDay', ['GET', 'POST'], ['id' => $uuid, 'day' => $uuid]);
        $add('holiday_day_remove', $base . '/{id}/holiday/{day}/remove', $controller . '::removeDay', ['POST'], ['id' => $uuid, 'day' => $uuid]);
        $add('holiday_import', $base . '/{id}/import', $controller . '::import', ['POST'], ['id' => $uuid]);
        $add('holiday_import_confirm', $base . '/{id}/import/confirm', $controller . '::importConfirm', ['POST'], ['id' => $uuid]);
        $add('holiday_provider', $base . '/{id}/provider/{key}', $controller . '::provider', ['POST'], ['id' => $uuid, 'key' => '[a-z][a-z0-9_]{0,39}']);
        $add('holiday_provider_confirm', $base . '/{id}/provider/{key}/confirm', $controller . '::providerConfirm', ['POST'], ['id' => $uuid, 'key' => '[a-z][a-z0-9_]{0,39}']);
    }

    /**
     * One route of the bundle.
     *
     * @param array<mixed> $methods
     * @param array<mixed> $requirements
     */
    private static function route(string $path, string $controller, array $methods, array $requirements): Route
    {
        $verbs = [];
        foreach ($methods as $method) {
            $verbs[] = is_string($method) ? $method : 'GET';
        }
        $patterns = [];
        foreach ($requirements as $key => $pattern) {
            $patterns[(string) $key] = is_string($pattern) ? $pattern : '.+';
        }

        return new Route($path, ['_controller' => $controller, self::ROUTE_ATTRIBUTE => true], $patterns, [], '', [], $verbs);
    }

    /**
     * Whether the resource is this loader's.
     */
    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }
}
