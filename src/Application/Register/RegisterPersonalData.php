<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Application\Register\Port\RegisterStoreInterface;
use Nowo\WorkingTimeBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\WorkingTimeBundle\Domain\Port\ExportFileStoreInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeAuditSinkInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterExport;
use Nowo\WorkingTimeBundle\Domain\Register\Model\Workday;
use Nowo\WorkingTimeBundle\Domain\Shared\AuditEntry;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Psr\Clock\ClockInterface;

use function array_map;
use function array_unique;
use function array_values;

use const DATE_ATOM;

/**
 * The register half of GDPR (contract section 14.4): what a worker gets in their export (art. 15 / 20) and what an
 * erasure (art. 17) removes. The register itself — workdays, segments, chain links, corrections — is **kept** until
 * the end of retention (art. 17.3.b, legal obligation of art. 34.9 ET), the worker's name with it; their preferences
 * and the files of their exports are deleted (the export rows stay until expiry). Lookups re-check the reference
 * with `===` (rule LQ). Called by the UserKit bridge or directly by a host.
 */
final readonly class RegisterPersonalData
{
    /** Sections the export and the erasure cover (parity test). */
    public const SECTIONS = ['workdays', 'corrections', 'exports', 'preferences'];

    public function __construct(
        private RegisterStoreInterface $store,
        private ExportFileStoreInterface $files,
        private RegisterReader $reader,
        private TransactionRunnerInterface $runner,
        private ClockInterface $clock,
        private ?WorkingTimeAuditSinkInterface $audit = null,
    ) {
    }

    /**
     * The worker's register data in every tenant.
     *
     * @return array{workdays: list<array<string, mixed>>, corrections: list<array<string, mixed>>, exports: list<array<string, mixed>>, preferences: array<string, bool>|null}
     */
    public function export(WorkerRef $worker): array
    {
        return $this->runner->read(function () use ($worker): array {
            $now      = $this->clock->now();
            $workdays = [];
            $requests = [];
            foreach ($this->reader->records($this->workdays($worker), $now) as $record) {
                $segments = [];
                foreach ($record->segments as $segment) {
                    $segments[] = [
                        'type'            => $segment->type->value,
                        'start'           => self::iso($segment->start),
                        'end'             => $segment->end === null ? null : self::iso($segment->end),
                        'start_source'    => $segment->startSource->value,
                        'end_source'      => $segment->endSource?->value,
                        'corrected'       => $segment->corrected,
                        'voided'          => $segment->voided,
                        'start_ip_prefix' => $segment->startIpPrefix,
                        'end_ip_prefix'   => $segment->endIpPrefix,
                    ];
                }
                $workdays[] = [
                    'tenant'         => $record->workday->getTenantId(),
                    'date'           => $record->workday->date()->value,
                    'name'           => $record->workday->getWorkerName(),
                    'worked_minutes' => (int) ($record->workedSeconds / 60),
                    'flags'          => $record->flagValues(),
                    'segments'       => $segments,
                    'trail'          => array_map(static fn ($revision): array => [
                        'seq'    => $revision->getSeq(),
                        'kind'   => $revision->getKind()->value,
                        'at'     => self::iso($revision->getCreatedAt()),
                        'by'     => $revision->getAuthorName(),
                        'reason' => $revision->getAuthorId() === $worker->value ? $revision->getReason() : null,
                    ], $record->revisions),
                ];
                foreach ($record->requests as $request) {
                    $requests[] = [
                        'date'       => $record->workday->date()->value,
                        'operation'  => $request->getOperation()->value,
                        'status'     => $request->getStatus()->value,
                        'reason'     => $request->getReason(),
                        'new_start'  => $request->getNewStart() === null ? null : self::iso($request->getNewStart()),
                        'new_end'    => $request->getNewEnd() === null ? null : self::iso($request->getNewEnd()),
                        'decided_at' => $request->getDecidedAt() === null ? null : self::iso($request->getDecidedAt()),
                        'decided_by' => $request->getDecidedByName(),
                        'comment'    => $request->getDecisionComment(),
                    ];
                }
            }
            $preference = $this->store->preference($worker);

            return [
                'workdays'    => $workdays,
                'corrections' => $requests,
                'exports'     => array_map(static fn (RegisterExport $export): array => [
                    'tenant'     => $export->getTenantId(),
                    'from'       => $export->monthFrom(),
                    'to'         => $export->monthTo(),
                    'format'     => $export->getFormat()->value,
                    'status'     => $export->getStatus()->value,
                    'own_copy'   => $export->isOwnCopy(),
                    'requested'  => self::iso($export->getCreatedAt()),
                    'sha256'     => $export->getSha256(),
                ], $this->exports($worker)),
                'preferences' => $preference !== null && $preference->getWorkerId() === $worker->value
                    ? ['reminders' => $preference->wantsReminders(), 'monthly_summary' => $preference->wantsMonthlySummary()]
                    : null,
            ];
        });
    }

    /**
     * Erases what is not part of the legal record and reports what is kept.
     *
     * @return array{deleted: array{preferences: int, export_files: int}, kept: list<string>}
     */
    public function erase(WorkerRef $worker): array
    {
        $result = $this->runner->write(function () use ($worker): array {
            $preference = $this->store->preference($worker);
            $prefs      = 0;
            if ($preference !== null && $preference->getWorkerId() === $worker->value) {
                $this->store->removePreference($worker);
                $prefs = 1;
            }
            $files = 0;
            foreach ($this->exports($worker) as $export) {
                $this->files->delete($export->getId());
                ++$files;
            }

            return ['preferences' => $prefs, 'export_files' => $files];
        });
        $tenants = array_values(array_unique(array_map(static fn (Workday $workday): string => $workday->getTenantId(), $this->runner->read(fn (): array => $this->workdays($worker)))));
        foreach ($tenants as $tenant) {
            $this->audit?->record(new AuditEntry('working_time.personal_data_erased', new TenantRef($tenant), null, $worker, $result));
        }

        return ['deleted' => $result, 'kept' => ['workdays', 'segments', 'revisions', 'corrections', 'exports']];
    }

    /**
     * @return list<Workday>
     */
    private function workdays(WorkerRef $worker): array
    {
        $kept = [];
        foreach ($this->store->workdaysOfWorker($worker) as $workday) {
            if ($workday->getWorkerId() === $worker->value) {
                $kept[] = $workday;
            }
        }

        return $kept;
    }

    /**
     * @return list<RegisterExport>
     */
    private function exports(WorkerRef $worker): array
    {
        $kept = [];
        foreach ($this->store->exportsAbout($worker->value) as $export) {
            if ($export->getRequestedById() === $worker->value || $export->getWorkerId() === $worker->value) {
                $kept[] = $export;
            }
        }

        return $kept;
    }

    private static function iso(DateTimeImmutable $at): string
    {
        return $at->format(DATE_ATOM);
    }
}
