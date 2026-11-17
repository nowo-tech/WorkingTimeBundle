<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Database;

use Nowo\WorkingTimeBundle\Application\Register\RegisterPurger;
use Nowo\WorkingTimeBundle\Domain\Register\Service\ChainVerifierInterface;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Trigger\RegisterTriggerInstaller;
use Nowo\WorkingTimeBundle\Tests\Fixtures\TestServices;
use Nowo\WorkingTimeBundle\Tests\Integration\Kernel\Host;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Throwable;

use function array_replace_recursive;
use function getenv;
use function is_array;
use function is_string;
use function parse_url;
use function str_replace;

/**
 * `purge_guard: db_user` with a real second database user (audit ST-M1, probe P9) on MySQL, MariaDB and PostgreSQL:
 * the triggers (installed by the application user) accept the deletes of the purge user's session only — MySQL /
 * MariaDB compare the session user (`USER()`, not the trigger's definer), PostgreSQL `current_user` — so the
 * application user's DELETE is refused and the purge, run on the purge connection, deletes.
 */
final class PurgeDbUserTest extends RegisterTestCase
{
    private const PURGE_USER = 'wtpurger';

    protected function host(): Host
    {
        return Host::standard();
    }

    public function testOnlyThePurgeUsersSessionMayDelete(): void
    {
        $url = getenv('NOWO_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            $this->addToAssertionCount(1); // needs a database server with users (MySQL, MariaDB, PostgreSQL)

            return;
        }
        $this->bootWithPurgeConnection($url);
        $this->enable();
        $this->actAs('ana', 'clock');
        $this->clock()->modify('2021-03-01 08:00:00');
        $this->workDay();
        $this->clock()->modify('2026-11-18 08:00:00');
        $this->workDay();
        $this->installer()->install($this->connection());

        try {
            $this->connection()->executeStatement('DELETE FROM nowo_working_time_revision WHERE seq = 8');
            self::fail('The application user may not delete.');
        } catch (Throwable $e) {
            self::assertTrue(RegisterTriggerInstaller::isRefusal($e), $e->getMessage());
        }
        $result = $this->service(RegisterPurger::class)->purge();

        self::assertSame(1, $result['tenants']['acme']['workdays']);
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_working_time_workday'));
        self::assertTrue($this->service(ChainVerifierInterface::class)->verify()->isIntact());
    }

    private function bootWithPurgeConnection(string $url): void
    {
        $this->boot(['integrity' => ['purge_guard' => 'db_user', 'purge_db_user' => self::PURGE_USER, 'purge_connection' => 'purge']], triggers: false);
        $mysql = $this->platform() === 'mysql';
        $c     = $this->connection();
        if ($mysql) {
            $c->executeStatement("DROP USER IF EXISTS '" . self::PURGE_USER . "'@'%'");
            $c->executeStatement("CREATE USER '" . self::PURGE_USER . "'@'%' IDENTIFIED BY 'throwaway'");
            $c->executeStatement("GRANT SELECT, INSERT, UPDATE, DELETE ON *.* TO '" . self::PURGE_USER . "'@'%'");
        } else {
            $c->executeStatement("DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '" . self::PURGE_USER . "') THEN CREATE ROLE " . self::PURGE_USER . " LOGIN PASSWORD 'throwaway'; END IF; END $$");
            $c->executeStatement('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO ' . self::PURGE_USER);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function boot(array $config = [], bool $triggers = true): void
    {
        $url          = (string) getenv('NOWO_TEST_DATABASE_URL');
        $purge        = self::purgeUrl($url);
        $this->kernel = $this->bootKernel(
            Host::standard()->bundleConfig(self::config(array_replace_recursive(TestServices::PORTS, ['ui' => ['enabled' => true], 'register' => ['export' => ['async' => 'sync']]], $config))),
            static function (ContainerConfigurator $container, string $default) use ($purge): void {
                $container->extension('doctrine', [
                    'dbal' => ['default_connection' => 'default', 'connections' => ['default' => ['url' => $default], 'purge' => ['url' => $purge ?? $default]]],
                    'orm'  => ['default_entity_manager' => 'default', 'entity_managers' => ['default' => ['connection' => 'default'], 'nowo_working_time' => ['connection' => 'default']]],
                ]);
            },
        );
        $this->schema($triggers);
    }

    private static function purgeUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['user'])) {
            return null;
        }

        return str_replace($parts['user'] . '@', self::PURGE_USER . ':throwaway@', $url);
    }

    /**
     * @param array<mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function config(array $config): array
    {
        $typed = [];
        foreach ($config as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }
}
