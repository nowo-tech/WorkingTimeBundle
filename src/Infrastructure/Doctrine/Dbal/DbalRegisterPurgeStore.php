<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Dbal;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Nowo\WorkingTimeBundle\Application\Register\Port\PurgeRefusedException;
use Nowo\WorkingTimeBundle\Application\Register\Port\RegisterPurgeStoreInterface;
use Nowo\WorkingTimeBundle\Domain\Port\IdGeneratorInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkdayRevision;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\DoctrineTransactionRunner;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Trigger\RegisterTriggerInstaller;
use Throwable;

use function array_values;
use function count;
use function is_numeric;
use function is_string;
use function max;

/**
 * The retention purge's deletes (contract section 6.5), on the bundle's connection inside the purger's write (or, with
 * `purge_guard: db_user`, in its own transaction on the dedicated purge connection).
 *
 * Per batch and chain: the links below the chain's first link of a workday that is kept are deleted (the batch's
 * workdays' links, and links left by an earlier batch whose workday is gone); a link of a deleted workday that comes
 * after a kept one (a late correction) stays until a later run, so a chain only ever loses a prefix. The chain's anchor
 * (`first_kept_seq`, hash of the last deleted link) is written in the same transaction. Then the batch's segments,
 * correction requests and workdays go. The trigger guard is lifted for these statements only: MySQL / MariaDB session
 * variable set here and cleared in `finally`, PostgreSQL `SET LOCAL` (gone at commit or rollback).
 */
final readonly class DbalRegisterPurgeStore implements RegisterPurgeStoreInterface
{
    /**
     * @param Connection|null $purgeConnection `integrity.purge_connection` (purge guard `db_user`); null = the bundle's connection
     */
    public function __construct(
        private DoctrineTransactionRunner $runner,
        private IdGeneratorInterface $ids,
        private string $prefix = 'nowo_',
        private ?Connection $purgeConnection = null,
    ) {
    }

    /**
     * Workdays of the tenant before the date, oldest first.
     */
    public function workdaysBefore(TenantRef $tenant, LocalDate $firstKept, int $limit): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT id, tenant_id FROM ' . $this->table('workday') . ' WHERE tenant_id = ? AND local_date < ? ORDER BY local_date, id',
            [$tenant->value, $firstKept->value],
        );
        $ids = [];
        foreach ($rows as $row) {
            if ($row['tenant_id'] === $tenant->value && is_string($row['id'])) {
                $ids[] = $row['id'];
            }
            if (count($ids) === $limit) {
                break;
            }
        }

        return $ids;
    }

    /**
     * Count of the workdays before the date.
     */
    public function countWorkdaysBefore(TenantRef $tenant, LocalDate $firstKept): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM ' . $this->table('workday') . ' WHERE tenant_id = ? AND local_date < ?', [$tenant->value, $firstKept->value]);

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Deletes one batch, writes the anchors.
     */
    public function deleteBatch(array $workdayIds, string $runId, DateTimeImmutable $at): array
    {
        if ($this->purgeConnection instanceof Connection) {
            return $this->purgeConnection->transactional(fn (Connection $connection): array => $this->batch($connection, $workdayIds, $runId, $at));
        }

        return $this->batch($this->connection(), $workdayIds, $runId, $at);
    }

    /**
     * Deletes delivered notices sent before the instant.
     */
    public function deleteNoticesSentBefore(DateTimeImmutable $before): int
    {
        return (int) $this->connection()->executeStatement('DELETE FROM ' . $this->table('notice') . ' WHERE sent_at IS NOT NULL AND sent_at < ?', [WorkdayRevision::utc($before)->format('Y-m-d H:i:s')]);
    }

    /**
     * @param list<string> $ids
     *
     * @return array{workdays: int, segments: int, revisions: int, corrections: int, anchors: int}
     */
    private function batch(Connection $connection, array $ids, string $runId, DateTimeImmutable $at): array
    {
        $platform = $connection->getDatabasePlatform();
        $mysql    = $platform instanceof AbstractMySQLPlatform;
        $guarded  = !$this->purgeConnection instanceof Connection;
        $totals   = ['workdays' => 0, 'segments' => 0, 'revisions' => 0, 'corrections' => 0, 'anchors' => 0];
        if ($ids === []) {
            return $totals;
        }
        $list = [ArrayParameterType::STRING];
        try {
            if ($guarded && $mysql) {
                $connection->executeStatement('SET ' . RegisterTriggerInstaller::PURGE_VARIABLE . ' = 1');
            } elseif ($guarded && $platform instanceof PostgreSQLPlatform) {
                $connection->executeStatement('SET LOCAL ' . RegisterTriggerInstaller::PURGE_SETTING . " = '1'");
            }
            $revision = $this->table('revision');
            $workday  = $this->table('workday');
            $chains   = $connection->fetchAllAssociative('SELECT DISTINCT tenant_id, worker_id FROM ' . $revision . ' WHERE workday_id IN (?)', [$ids], $list);
            foreach ($chains as $chain) {
                $tenant = $this->text($chain['tenant_id']);
                $worker = $this->text($chain['worker_id']);
                // The chain's first link of a workday that stays (neither in this batch nor already gone).
                $keep = $connection->fetchOne(
                    'SELECT MIN(r.seq) FROM ' . $revision . ' r INNER JOIN ' . $workday . ' w ON w.id = r.workday_id WHERE r.tenant_id = ? AND r.worker_id = ? AND r.workday_id NOT IN (?)',
                    [$tenant, $worker, $ids],
                    [2 => ArrayParameterType::STRING],
                );
                $keepFrom = is_numeric($keep) ? (int) $keep : null;
                $last     = $connection->fetchAssociative(
                    'SELECT seq, hash FROM ' . $revision . ' WHERE tenant_id = ? AND worker_id = ?' . ($keepFrom === null ? '' : ' AND seq < ' . $keepFrom) . ' ORDER BY seq DESC',
                    [$tenant, $worker],
                );
                if ($last === false) {
                    continue;
                }
                $deleted = $connection->executeStatement(
                    'DELETE FROM ' . $revision . ' WHERE tenant_id = ? AND worker_id = ? AND seq <= ?',
                    [$tenant, $worker, $this->number($last['seq'])],
                );
                $totals['revisions'] += (int) $deleted;
                $connection->insert($this->table('chain_anchor'), [
                    'id'             => $this->ids->generate(),
                    'tenant_id'      => $tenant,
                    'worker_id'      => $worker,
                    'first_kept_seq' => max(1, $this->number($last['seq']) + 1),
                    'anchor_hash'    => $this->text($last['hash']),
                    'purged_links'   => (int) $deleted,
                    'purged_at'      => WorkdayRevision::utc($at)->format('Y-m-d H:i:s'),
                    'purge_run_id'   => $runId,
                ]);
                ++$totals['anchors'];
            }
            $totals['segments']    = (int) $connection->executeStatement('DELETE FROM ' . $this->table('segment') . ' WHERE workday_id IN (?)', [$ids], $list);
            $totals['corrections'] = (int) $connection->executeStatement('DELETE FROM ' . $this->table('correction') . ' WHERE workday_id IN (?)', [$ids], $list);
            $totals['workdays']    = (int) $connection->executeStatement('DELETE FROM ' . $workday . ' WHERE id IN (?)', [array_values($ids)], $list);
        } catch (Throwable $e) {
            if (RegisterTriggerInstaller::isRefusal($e)) {
                throw new PurgeRefusedException($e);
            }

            throw $e;
        } finally {
            if ($guarded && $mysql) {
                $connection->executeStatement('SET ' . RegisterTriggerInstaller::PURGE_VARIABLE . ' = NULL');
            }
        }

        return $totals;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : (is_numeric($value) ? (string) $value : '');
    }

    private function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function connection(): Connection
    {
        return $this->runner->manager()->getConnection();
    }

    private function table(string $suffix): string
    {
        return $this->prefix . 'working_time_' . $suffix;
    }
}
