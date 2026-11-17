<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Trigger;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use LogicException;
use Throwable;

use function array_diff;
use function array_map;
use function array_values;
use function implode;
use function is_string;
use function preg_match;
use function sprintf;
use function str_contains;
use function str_replace;
use function strtolower;

/**
 * The database side of the append-only register (contract section 6.4, spec 198 FR-017): triggers that refuse.
 *
 * - any UPDATE of the workday, revision and chain-anchor tables;
 * - any UPDATE of the segment table but the one that closes an open segment (only `end_*`, from NULL);
 * - any DELETE of workday, segment and revision rows except inside the retention purge, and any DELETE of anchors;
 * - on PostgreSQL also TRUNCATE (statement triggers; MySQL / MariaDB TRUNCATE fires no trigger: the chain and the
 *   anchors detect it).
 *
 * The purge guard: `session_variable` (default) — MySQL / MariaDB `@nowo_working_time_purge = 1` set by the purger on
 * its own connection and cleared in `finally` (and on every connect), PostgreSQL `SET LOCAL nowo_working_time.purge`
 * inside the purge transaction only; `db_user` — the trigger accepts the deletes of one dedicated database user.
 * SQLite (tests, demos) gets the refusals without a purge exception.
 */
final readonly class RegisterTriggerInstaller
{
    /** MySQL / MariaDB session variable of the purge guard. */
    public const PURGE_VARIABLE = '@nowo_working_time_purge';

    /** PostgreSQL custom setting of the purge guard. */
    public const PURGE_SETTING = 'nowo_working_time.purge';

    /** Protected tables (unprefixed suffix => trigger short name). */
    public const TABLES = ['working_time_workday' => 'workday', 'working_time_segment' => 'segment', 'working_time_revision' => 'revision', 'working_time_chain_anchor' => 'chain_anchor'];

    /** Columns a segment's close may not change. */
    public const SEGMENT_FIXED = ['id', 'workday_id', 'type', 'started_at', 'start_source', 'start_received_at', 'start_ip_prefix'];

    /**
     * @param string $prefix `doctrine.table_prefix`
     * @param string|null $purgeUser `integrity.purge_db_user` (purge guard `db_user`)
     */
    public function __construct(
        private string $prefix = 'nowo_',
        private ?string $purgeUser = null,
    ) {
        if ($purgeUser !== null && preg_match('/^[A-Za-z0-9_$.-]{1,80}$/D', $purgeUser) !== 1) {
            throw new LogicException('integrity.purge_db_user is a plain database user name.');
        }
    }

    /**
     * The trigger names expected on the connection's platform.
     *
     * @return list<string>
     */
    public function names(AbstractPlatform $platform): array
    {
        $names = [];
        foreach (self::TABLES as $short) {
            $names[] = $this->prefix . 'wt_' . $short . '_no_update';
            $names[] = $this->prefix . 'wt_' . $short . '_no_delete';
            if ($platform instanceof PostgreSQLPlatform) {
                $names[] = $this->prefix . 'wt_' . $short . '_no_truncate';
            }
        }

        return $names;
    }

    /**
     * The statements that create the triggers on the connection's platform.
     *
     * @return list<string>
     */
    public function statements(AbstractPlatform $platform): array
    {
        return match (true) {
            $platform instanceof AbstractMySQLPlatform => $this->mysql(),
            $platform instanceof PostgreSQLPlatform    => $this->postgresql(),
            $platform instanceof SQLitePlatform        => $this->sqlite(),
            default                                    => throw new LogicException(sprintf('nowo_working_time has no append-only triggers for %s.', $platform::class)),
        };
    }

    /**
     * Creates the triggers (dropping existing ones first); on any failure removes what it created and throws.
     *
     * @throws TriggerInstallException
     */
    public function install(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        $this->uninstall($connection);
        try {
            foreach ($this->statements($platform) as $sql) {
                $connection->executeStatement($sql);
            }
        } catch (Throwable $e) {
            $this->uninstall($connection);

            throw new TriggerInstallException($platform instanceof AbstractMySQLPlatform, $e);
        }
    }

    /**
     * Drops the triggers (and the PostgreSQL functions).
     */
    public function uninstall(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        foreach (self::TABLES as $table => $short) {
            foreach (['no_update', 'no_delete', 'no_truncate'] as $what) {
                $name = $this->prefix . 'wt_' . $short . '_' . $what;
                $sql  = $platform instanceof PostgreSQLPlatform
                    ? sprintf('DROP TRIGGER IF EXISTS %s ON %s', $name, $this->prefix . $table)
                    : sprintf('DROP TRIGGER IF EXISTS %s', $name);
                try {
                    $connection->executeStatement($sql);
                } catch (Throwable) {
                    // The table may not exist yet.
                }
            }
        }
        if ($platform instanceof PostgreSQLPlatform) {
            foreach (['wt_refuse', 'wt_segment_close_only'] as $function) {
                $connection->executeStatement(sprintf('DROP FUNCTION IF EXISTS %s%s()', $this->prefix, $function));
            }
        }
    }

    /**
     * The expected triggers that are missing.
     *
     * @return list<string>
     */
    public function missing(Connection $connection): array
    {
        $platform = $connection->getDatabasePlatform();
        $sql      = match (true) {
            $platform instanceof AbstractMySQLPlatform => 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()',
            $platform instanceof PostgreSQLPlatform    => 'SELECT tgname FROM pg_trigger WHERE NOT tgisinternal',
            default                                    => "SELECT name FROM sqlite_master WHERE type = 'trigger'",
        };
        $present = array_map(static fn (mixed $name): string => is_string($name) ? strtolower($name) : '', $connection->fetchFirstColumn($sql));

        return array_values(array_diff(array_map(strtolower(...), $this->names($platform)), $present));
    }

    /**
     * Whether the triggers are complete.
     */
    public function installed(Connection $connection): bool
    {
        return $this->missing($connection) === [];
    }

    /**
     * @return list<string>
     */
    private function mysql(): array
    {
        $refuse = fn (string $table): string => sprintf("SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '%s is append-only'", $this->prefix . $table);
        $guard  = $this->purgeUser === null
            ? 'COALESCE(' . self::PURGE_VARIABLE . ', 0) <> 1'
            : sprintf("SUBSTRING_INDEX(USER(), '@', 1) <> '%s'", $this->purgeUser);
        $fixed      = implode(' OR ', array_map(static fn (string $column): string => sprintf('NOT (NEW.`%1$s` <=> OLD.`%1$s`)', $column), self::SEGMENT_FIXED));
        $statements = [];
        foreach (self::TABLES as $table => $short) {
            $name = $this->prefix . 'wt_' . $short;
            $full = $this->prefix . $table;
            if ($short === 'segment') {
                $statements[] = sprintf('CREATE TRIGGER %s_no_update BEFORE UPDATE ON %s FOR EACH ROW BEGIN IF OLD.ended_at IS NOT NULL OR NEW.ended_at IS NULL OR %s THEN %s; END IF; END', $name, $full, $fixed, $refuse($table));
            } else {
                $statements[] = sprintf('CREATE TRIGGER %s_no_update BEFORE UPDATE ON %s FOR EACH ROW BEGIN %s; END', $name, $full, $refuse($table));
            }
            $statements[] = $short === 'chain_anchor'
                ? sprintf('CREATE TRIGGER %s_no_delete BEFORE DELETE ON %s FOR EACH ROW BEGIN %s; END', $name, $full, $refuse($table))
                : sprintf('CREATE TRIGGER %s_no_delete BEFORE DELETE ON %s FOR EACH ROW BEGIN IF %s THEN %s; END IF; END', $name, $full, $guard, $refuse($table));
        }

        return $statements;
    }

    /**
     * @return list<string>
     */
    private function postgresql(): array
    {
        $guard = $this->purgeUser === null
            ? sprintf("current_setting('%s', true) = '1'", self::PURGE_SETTING)
            : sprintf("current_user = '%s'", $this->purgeUser);
        $fixed      = implode(', ', array_map(static fn (string $column): string => 'NEW.' . $column, self::SEGMENT_FIXED));
        $old        = str_replace('NEW.', 'OLD.', $fixed);
        $statements = [
            sprintf("CREATE OR REPLACE FUNCTION %swt_refuse() RETURNS trigger LANGUAGE plpgsql AS \$\$\nBEGIN\n  IF TG_OP = 'DELETE' AND TG_TABLE_NAME <> '%s' AND %s THEN RETURN OLD; END IF;\n  RAISE EXCEPTION '%% is append-only', TG_TABLE_NAME USING ERRCODE = 'P0001';\nEND \$\$", $this->prefix, $this->prefix . 'working_time_chain_anchor', $guard),
            sprintf("CREATE OR REPLACE FUNCTION %swt_segment_close_only() RETURNS trigger LANGUAGE plpgsql AS \$\$\nBEGIN\n  IF OLD.ended_at IS NOT NULL OR NEW.ended_at IS NULL OR (%s) IS DISTINCT FROM (%s) THEN\n    RAISE EXCEPTION '%s is append-only' USING ERRCODE = 'P0001';\n  END IF;\n  RETURN NEW;\nEND \$\$", $this->prefix, $fixed, $old, $this->prefix . 'working_time_segment'),
        ];
        foreach (self::TABLES as $table => $short) {
            $name         = $this->prefix . 'wt_' . $short;
            $full         = $this->prefix . $table;
            $statements[] = sprintf('CREATE TRIGGER %s_no_update BEFORE UPDATE ON %s FOR EACH ROW EXECUTE FUNCTION %s()', $name, $full, $this->prefix . ($short === 'segment' ? 'wt_segment_close_only' : 'wt_refuse'));
            $statements[] = sprintf('CREATE TRIGGER %s_no_delete BEFORE DELETE ON %s FOR EACH ROW EXECUTE FUNCTION %swt_refuse()', $name, $full, $this->prefix);
            $statements[] = sprintf('CREATE TRIGGER %s_no_truncate BEFORE TRUNCATE ON %s FOR EACH STATEMENT EXECUTE FUNCTION %swt_refuse()', $name, $full, $this->prefix);
        }

        return $statements;
    }

    /**
     * @return list<string>
     */
    private function sqlite(): array
    {
        $refuse     = fn (string $table): string => sprintf("SELECT RAISE(ABORT, '%s is append-only');", $this->prefix . $table);
        $changed    = implode(' OR ', array_map(static fn (string $column): string => sprintf('NEW."%1$s" IS NOT OLD."%1$s"', $column), self::SEGMENT_FIXED));
        $statements = [];
        foreach (self::TABLES as $table => $short) {
            $name         = $this->prefix . 'wt_' . $short;
            $full         = $this->prefix . $table;
            $statements[] = $short === 'segment'
                ? sprintf('CREATE TRIGGER %s_no_update BEFORE UPDATE ON %s WHEN OLD.ended_at IS NOT NULL OR NEW.ended_at IS NULL OR %s BEGIN %s END', $name, $full, $changed, $refuse($table))
                : sprintf('CREATE TRIGGER %s_no_update BEFORE UPDATE ON %s BEGIN %s END', $name, $full, $refuse($table));
            $statements[] = sprintf('CREATE TRIGGER %s_no_delete BEFORE DELETE ON %s BEGIN %s END', $name, $full, $refuse($table));
        }

        return $statements;
    }

    /**
     * Whether a database failure is one of the triggers' refusals.
     */
    public static function isRefusal(Throwable $failure): bool
    {
        for ($e = $failure; $e instanceof Throwable; $e = $e->getPrevious()) {
            if (str_contains($e->getMessage(), 'is append-only')) {
                return true;
            }
        }

        return false;
    }
}
