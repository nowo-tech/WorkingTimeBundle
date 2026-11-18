<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Leave;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Application\Leave\Port\LeaveStoreInterface;
use Nowo\WorkingTimeBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\AllowanceLine;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\AllowancePolicy;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeAuditSinkInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\AuditEntry;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

use function array_map;
use function array_unique;
use function array_values;

/**
 * The leave half of the GDPR export and erasure (contract section 14.4; audit TD-L7): a worker's allowance lines and
 * worker-scoped policies in every tenant. Erasure keeps the amounts (they hold the balance until retention), blanks the
 * free text (line reasons, policy notes) and forgets the worker as the creator of other people's rows. The requests,
 * decisions and attachments join it in S3.
 */
final readonly class LeavePersonalData
{
    /** Sections the export and the erasure cover (parity test). */
    public const SECTIONS = ['allowance_lines', 'policies'];

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private LeaveStoreInterface $store,
        private TransactionRunnerInterface $runner,
        private ?WorkingTimeAuditSinkInterface $audit = null,
    ) {
    }

    /**
     * The worker's leave data in every tenant.
     *
     * @return array{allowance_lines: list<array<string, mixed>>, policies: list<array<string, mixed>>}
     */
    public function export(WorkerRef $worker): array
    {
        return $this->runner->read(fn (): array => [ // @igor-ignore - the runner calls this closure at once and keeps nothing
            'allowance_lines' => array_map(static fn (AllowanceLine $line): array => [
                'tenant'     => $line->getTenantId(),
                'type'       => $line->getLeaveTypeId(),
                'year'       => $line->getYear(),
                'kind'       => $line->getKind()->value,
                'amount'     => $line->getAmount(),
                'reason'     => $line->getReason(),
                'expires_on' => $line->expiresOn()?->value,
                'expired'    => $line->getExpiredAt() instanceof DateTimeImmutable,
                'created'    => $line->getCreatedAt()->format('Y-m-d\TH:i:s\Z'),
            ], $this->store->linesOfWorker($worker)),
            'policies' => array_map(static fn (AllowancePolicy $policy): array => [
                'tenant' => $policy->getTenantId(),
                'type'   => $policy->getLeaveTypeId(),
                'year'   => $policy->getYear(),
                'amount' => $policy->getAmount(),
                'note'   => $policy->getNote(),
            ], $this->store->policiesOfWorker($worker)),
        ]);
    }

    /**
     * Erases the free text and the actor references; reports the counts and what is kept.
     *
     * @return array{erased: array{reasons: int, notes: int, actor_refs: int}, kept: list<string>}
     */
    public function erase(WorkerRef $worker): array
    {
        $tenants = $this->runner->read(fn (): array => array_values(array_unique(array_map(static fn (AllowanceLine $line): string => $line->getTenantId(), $this->store->linesOfWorker($worker))))); // @igor-ignore - the runner calls this closure at once and keeps nothing
        $erased  = $this->runner->write(fn (): array => $this->store->erasePersonalData($worker)); // @igor-ignore - the runner calls this closure at once and keeps nothing
        foreach ($tenants as $tenant) {
            $this->audit?->record(new AuditEntry('working_time.leave_personal_data_erased', new TenantRef($tenant), null, $worker, $erased));
        }

        return ['erased' => $erased, 'kept' => ['allowance_lines', 'policies']];
    }
}
