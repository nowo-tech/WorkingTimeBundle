<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Infrastructure\Symfony;

use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Routing\UiRouteLoader;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Routing\Attribute\Route as RouteAttribute;
use Symfony\Component\Routing\RouteCollection;

use function class_exists;
use function explode;
use function implode;
use function is_string;

/**
 * REQ-SF-004 rule 8 (programmatic route loader): the routes `UiRouteLoader` generates with every part on, by name,
 * methods and path, so a renamed route or a moved path fails here (rule 5); and no controller behind them carries
 * `#[Route]`, so the host's `routing.controllers` import cannot load a bundle route without the documented
 * `nowo_working_time_ui` import (rule 7).
 */
final class RouteListTest extends TestCase
{
    public function testTheRouteListWithEveryPartOn(): void
    {
        self::assertSame([
            'nowo_working_time_settings'                       => 'GET|POST /working-time/settings',
            'nowo_working_time_preferences'                    => 'GET|POST /working-time/preferences',
            'nowo_working_time_today'                          => 'GET /working-time',
            'nowo_working_time_clock'                          => 'POST /working-time/clock/{action}',
            'nowo_working_time_month_current'                  => 'GET /working-time/month',
            'nowo_working_time_month'                          => 'GET /working-time/month/{month}',
            'nowo_working_time_day'                            => 'GET /working-time/day/{id}',
            'nowo_working_time_date'                           => 'GET /working-time/date/{date}',
            'nowo_working_time_correction_request'             => 'POST /working-time/date/{date}/correction',
            'nowo_working_time_correction_answer'              => 'POST /working-time/corrections/{id}/answer',
            'nowo_working_time_team'                           => 'GET /working-time/team',
            'nowo_working_time_team_day'                       => 'GET /working-time/team/day',
            'nowo_working_time_correction_record'              => 'POST /working-time/team/record',
            'nowo_working_time_correction_decide'              => 'POST /working-time/requests/{id}/{decision}',
            'nowo_working_time_schedules'                      => 'GET|POST /working-time/schedules',
            'nowo_working_time_schedule_withdraw'              => 'POST /working-time/schedules/{id}/withdraw',
            'nowo_working_time_exports'                        => 'GET|POST /working-time/exports',
            'nowo_working_time_export_download'                => 'GET /working-time/exports/{id}/download',
            'nowo_working_time_holidays'                       => 'GET|POST /holidays',
            'nowo_working_time_holiday_municipalities'         => 'GET /holidays/municipalities',
            'nowo_working_time_holiday_municipalities_refresh' => 'POST /holidays/municipalities/refresh',
            'nowo_working_time_holiday_centre'                 => 'GET|POST /holidays/centres/{id}',
            'nowo_working_time_holiday_centre_remove'          => 'POST /holidays/centres/{id}/remove',
            'nowo_working_time_holiday_calendar'               => 'GET|POST /holidays/{id}',
            'nowo_working_time_holiday_calendar_remove'        => 'POST /holidays/{id}/remove',
            'nowo_working_time_holiday_day_add'                => 'POST /holidays/{id}/add',
            'nowo_working_time_holiday_day_edit'               => 'GET|POST /holidays/{id}/holiday/{day}/edit',
            'nowo_working_time_holiday_day_remove'             => 'POST /holidays/{id}/holiday/{day}/remove',
            'nowo_working_time_holiday_import'                 => 'POST /holidays/{id}/import',
            'nowo_working_time_holiday_import_confirm'         => 'POST /holidays/{id}/import/confirm',
            'nowo_working_time_holiday_provider'               => 'POST /holidays/{id}/provider/{key}',
            'nowo_working_time_holiday_provider_confirm'       => 'POST /holidays/{id}/provider/{key}/confirm',
            'nowo_working_time_leave'                          => 'GET /leave',
            'nowo_working_time_leave_manage'                   => 'GET|POST /leave/manage',
            'nowo_working_time_leave_seed'                     => 'POST /leave/manage/seed',
            'nowo_working_time_leave_type'                     => 'GET|POST /leave/manage/types/{id}',
            'nowo_working_time_leave_type_archive'             => 'POST /leave/manage/types/{id}/archive',
            'nowo_working_time_leave_allowances'               => 'GET|POST /leave/manage/allowances',
            'nowo_working_time_leave_policy_remove'            => 'POST /leave/manage/allowances/{id}/remove',
            'nowo_working_time_leave_settings'                 => 'GET|POST /working-time/settings/leave',
        ], self::describe(self::all()));
    }

    public function testNoControllerCarriesARouteAttribute(): void
    {
        $classes = [];
        foreach (self::all()->all() as $route) {
            $controller = $route->getDefault('_controller');
            self::assertTrue(is_string($controller));
            $classes[explode('::', $controller)[0]] = true;
        }
        self::assertCount(7, $classes);
        foreach ($classes as $class => $unused) {
            self::assertTrue(class_exists($class), $class);
            $reflection = new ReflectionClass($class);
            self::assertSame([], $reflection->getAttributes(RouteAttribute::class), $class);
            foreach ($reflection->getMethods() as $method) {
                self::assertSame([], $method->getAttributes(RouteAttribute::class), $class . '::' . $method->getName());
            }
        }
    }

    private static function all(): RouteCollection
    {
        return (new UiRouteLoader(true, '/working-time', '/working-time/settings', true, true, true, '/holidays', '/leave'))->load('.');
    }

    /**
     * @return array<string, string> route name => "METHODS path"
     */
    private static function describe(RouteCollection $routes): array
    {
        $list = [];
        foreach ($routes->all() as $name => $route) {
            $list[$name] = implode('|', $route->getMethods()) . ' ' . $route->getPath();
        }

        return $list;
    }
}
