<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register;

use DateTimeZone;
use Nowo\WorkingTimeBundle\Application\Register\Port\RegisterPurgeStoreInterface;
use Nowo\WorkingTimeBundle\Application\Register\Port\RegisterStoreInterface;
use Nowo\WorkingTimeBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\WorkingTimeBundle\Domain\Port\IdGeneratorInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeAuditSinkInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Event\RegisterPurged;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RetentionPolicy;
use Nowo\WorkingTimeBundle\Domain\Shared\AuditEntry;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

use function count;
use function sprintf;

/**
 * The retention purge (spec 198 FR-024, contract section 6.5): per tenant, deletes the workdays before the first kept
 * date (4–10 years) with their segments, chain links and correction requests, in batches of 500, one transaction per
 * batch, each writing its chains' anchors in the same transaction. Deletion, not anonymisation. One audit entry per
 * tenant and run (counts only). Also drops delivered notices after 400 days. The only code path that deletes register
 * rows.
 */
final readonly class RegisterPurger
{
    /** Workdays per batch. */
    public const BATCH = 500;

    /** Delivered notices are kept this long (they only stop a notice going out twice). */
    public const NOTICE_DAYS = 400;

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private RegisterStoreInterface $store,
        private RegisterPurgeStoreInterface $purge,
        private TransactionRunnerInterface $runner,
        private IdGeneratorInterface $ids,
        private WorkingTimeAuditSinkInterface $audit,
        private ClockInterface $clock,
        private ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

    /**
     * Runs the purge (or counts what it would delete).
     *
     * @return array{tenants: array<string, array{first_kept: string, workdays: int, segments: int, revisions: int, corrections: int, anchors: int}>, notices: int}
     */
    public function purge(bool $dryRun = false): array
    {
        $now     = $this->clock->now();
        $today   = LocalDate::of($now, new DateTimeZone('UTC'));
        $runId   = $this->ids->generate();
        $tenants = [];
        foreach ($this->runner->read(fn (): array => $this->store->allSettings()) as $settings) {
            $tenant    = new TenantRef($settings->getTenantId());
            $firstKept = RetentionPolicy::firstKept($settings->retentionYears(), $today);
            $totals    = ['first_kept' => $firstKept->value, 'workdays' => 0, 'segments' => 0, 'revisions' => 0, 'corrections' => 0, 'anchors' => 0];
            if ($dryRun) {
                $totals['workdays']      = $this->runner->read(fn (): int => $this->purge->countWorkdaysBefore($tenant, $firstKept));
                $tenants[$tenant->value] = $totals;

                continue;
            }
            do {
                $ids = $this->runner->read(fn (): array => $this->purge->workdaysBefore($tenant, $firstKept, self::BATCH));
                if ($ids === []) {
                    break;
                }
                $counts = $this->runner->write(fn (): array => $this->purge->deleteBatch($ids, $runId, $now));
                foreach ($counts as $key => $count) {
                    $totals[$key] += $count;
                }
            } while (count($ids) === self::BATCH);
            $tenants[$tenant->value] = $totals;
            if ($totals['workdays'] > 0) {
                $this->audit->record(new AuditEntry('working_time.register_purged', $tenant, null, null, $totals + ['run' => $runId]));
                $this->dispatcher?->dispatch(new RegisterPurged($tenant, $firstKept->value, $totals['workdays'], $totals['revisions']));
            }
        }
        $notices = $dryRun ? 0 : $this->runner->write(fn (): int => $this->purge->deleteNoticesSentBefore($now->modify(sprintf('-%d days', self::NOTICE_DAYS))));

        return ['tenants' => $tenants, 'notices' => $notices];
    }
}
