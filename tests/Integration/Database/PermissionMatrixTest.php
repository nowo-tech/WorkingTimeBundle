<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\DataProvider;

use function array_diff;
use function array_values;

/**
 * The permission matrix (contract section 13), every page × profile: deny by default; no tenant, the register off,
 * not a member or a worker of another tenant ⇒ 404; a member without the permission ⇒ 403; the holder ⇒ 200. The
 * settings page opens while the register is off (it turns it on).
 */
final class PermissionMatrixTest extends UiTestCase
{
    /** Page => permission it needs. */
    private const PAGES = [
        '/working-time'                                     => 'clock',
        '/working-time/month'                               => 'clock',
        '/working-time/month/2026-10'                       => 'clock',
        '/working-time/date/2026-11-18'                     => 'clock',
        '/working-time/preferences'                         => 'clock',
        '/working-time/team'                                => 'manage_team',
        '/working-time/team/day?worker=ana&date=2026-11-18' => 'manage_team',
        '/working-time/schedules'                           => 'manage_team',
        '/working-time/settings'                            => 'manage_settings',
    ];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pages(): iterable
    {
        foreach (self::PAGES as $path => $permission) {
            yield $path => [$path, $permission];
        }
    }

    #[DataProvider('pages')]
    public function testEveryProfileGetsTheExpectedAnswer(string $path, string $permission): void
    {
        $this->boot();
        $this->enable('acme', ['ana', 'mia', 'zoe']);
        $this->ctx()->member('other', 'out');

        $this->ctx()->actor = 'zoe';
        self::assertSame(403, $this->statusOf('GET', $path), 'member without the permission');
        $this->ctx()->grant('acme', 'zoe', ...array_values(array_diff(['clock', 'manage_team', 'export', 'manage_settings'], [$permission])));
        self::assertSame(403, $this->statusOf('GET', $path), 'every other permission');

        $this->ctx()->actor = 'mia';
        $this->ctx()->grant('acme', 'mia', $permission);
        self::assertSame(200, $this->statusOf('GET', $path), 'holder');

        $this->ctx()->actor = null;
        self::assertSame(404, $this->statusOf('GET', $path), 'nobody signed in');
        $this->ctx()->actor = 'out';
        self::assertSame(404, $this->statusOf('GET', $path), 'not a member');
        $this->ctx()->actor  = 'mia';
        $this->ctx()->tenant = null;
        self::assertSame(404, $this->statusOf('GET', $path), 'no tenant');
        $this->ctx()->tenant = 'other';
        self::assertSame(404, $this->statusOf('GET', $path), 'register off for the tenant');
    }

    public function testAManagerOfAnotherWorkerAndAnotherTenantsRowsAre404Or403(): void
    {
        $this->boot();
        $this->enable('acme', ['ana', 'mia', 'leo']);
        $this->ctx()->actor = 'mia';
        $this->ctx()->grant('acme', 'mia', 'manage_team:leo', 'clock');
        self::assertSame(403, $this->statusOf('GET', '/working-time/team/day?worker=ana&date=2026-11-18'), 'manages leo, not ana');
        self::assertSame(200, $this->statusOf('GET', '/working-time/team/day?worker=leo&date=2026-11-18'));
        self::assertSame(404, $this->statusOf('GET', '/working-time/team/day?worker=ghost&date=2026-11-18'), 'not a member');
        self::assertSame(403, $this->statusOf('GET', '/working-time/team'), 'the team page needs the tenant-wide grant');
        self::assertSame(200, $this->statusOf('GET', '/working-time/exports'), 'membership: own exports');
    }

    public function testTheSettingsPageOpensWhileTheRegisterIsOffAndTheDefaultCheckerDeniesEverything(): void
    {
        $this->boot();
        $this->ctx()->member('acme', 'adm');
        $this->ctx()->actor = 'adm';
        $this->ctx()->grant('acme', 'adm', 'manage_settings');
        self::assertSame(200, $this->statusOf('GET', '/working-time/settings'));
        self::assertSame(404, $this->statusOf('GET', '/working-time'));

        $this->boot(['permission_checker' => null]);
        $this->enable('acme', ['adm']);
        $this->ctx()->actor = 'adm';
        self::assertSame(404, $this->statusOf('GET', '/working-time'), 'role map: nobody signed in for the security layer');
    }
}
