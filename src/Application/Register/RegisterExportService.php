<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register;

use DateTimeZone;
use Nowo\WorkingTimeBundle\Application\Register\Port\ExportDispatcherInterface;
use Nowo\WorkingTimeBundle\Application\Register\Port\RegisterStoreInterface;
use Nowo\WorkingTimeBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\WorkingTimeBundle\Domain\Port\ExportFileStoreInterface;
use Nowo\WorkingTimeBundle\Domain\Port\IdGeneratorInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeAuditSinkInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ExportFormat;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\ExportRefusedException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\RegisterUnavailableException;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterExport;
use Nowo\WorkingTimeBundle\Domain\Register\Service\RegisterExportServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\AuditEntry;
use Nowo\WorkingTimeBundle\Domain\Shared\GroupRef;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkingTimePermission;
use Psr\Clock\ClockInterface;

use function in_array;
use function preg_match;
use function strcmp;
use function substr;

/**
 * Asks for exports and hands them out (spec 198 FR-021 / FR-022). The own copy is the worker's own register (PDF or
 * CSV) with `clock`; an inspection export (any member, or the team as a ZIP) needs `export`. Who may download: the
 * requester, or a holder of `export` in the same tenant for an inspection export. Every download is audited.
 */
final readonly class RegisterExportService implements RegisterExportServiceInterface
{
    /**
     * @param list<string> $formats `register.export.formats`
     */
    public function __construct(
        private RegisterAccess $access,
        private RegisterStoreInterface $store,
        private ExportFileStoreInterface $files,
        private ExportDispatcherInterface $dispatcher,
        private TransactionRunnerInterface $runner,
        private IdGeneratorInterface $ids,
        private WorkingTimeAuditSinkInterface $audit,
        private ClockInterface $clock,
        private array $formats = ['pdf', 'csv'],
        private int $maxMonths = 12,
    ) {
    }

    /**
     * Asks for an export.
     */
    public function request(?WorkerRef $worker, ?GroupRef $group, string $monthFrom, string $monthTo, ExportFormat $format, ?string $locale = null): RegisterExport
    {
        $member  = $this->access->context(null);
        $own     = $worker !== null && $worker->isActor($member->actor);
        $context = $this->access->context($own ? WorkingTimePermission::Clock : WorkingTimePermission::Export, $worker);
        if (($worker === null) !== ($format === ExportFormat::Zip) || ($own && $group !== null)) {
            throw new ExportRefusedException('export.error.format');
        }
        if (!in_array($format->value, $this->formats, true) && !($format === ExportFormat::Zip && $this->formats !== [])) {
            throw new ExportRefusedException('export.error.format');
        }
        $this->checkPeriod($monthFrom, $monthTo);
        $export = $this->runner->write(function () use ($context, $worker, $group, $own, $monthFrom, $monthTo, $format, $locale): RegisterExport {
            $export = RegisterExport::request($this->ids->generate(), $context->tenant->value, $context->actor, $context->actorProfile->displayName, $worker?->value, $group?->value, $own, $monthFrom, $monthTo, $format, $locale ?? $context->locale, $this->clock->now());
            $this->store->addExport($export);
            $this->audit->record(new AuditEntry('working_time.export_requested', $context->tenant, $context->actor, $worker, ['export' => $export->getId(), 'from' => $monthFrom, 'to' => $monthTo, 'format' => $format->value, 'scope' => $own ? 'own' : ($worker === null ? 'team' : 'worker')]));

            return $export;
        });
        $this->dispatcher->dispatch($export->getId());

        return $this->runner->read(fn (): RegisterExport => $this->store->export($export->getId()) ?? $export);
    }

    /**
     * An export the actor may see.
     */
    public function status(string $exportId): RegisterExport
    {
        $context = $this->access->context(null);
        $export  = $this->runner->read(fn (): ?RegisterExport => $this->store->export($exportId));
        if ($export === null || $export->getTenantId() !== $context->tenant->value || !$this->mayDownload($export, $context)) {
            throw new RegisterUnavailableException();
        }

        return $export;
    }

    /**
     * The export and its bytes.
     *
     * @return array{RegisterExport, string}
     */
    public function open(string $exportId): array
    {
        $export  = $this->status($exportId);
        $context = $this->access->context(null);
        $bytes   = $export->isDownloadable($this->clock->now()) ? $this->files->get($export->getId()) : null;
        if ($bytes === null) {
            throw new RegisterUnavailableException();
        }
        $this->audit->record(new AuditEntry('working_time.export_downloaded', $context->tenant, $context->actor, $export->getWorkerId() === null ? null : new WorkerRef($export->getWorkerId()), ['export' => $export->getId(), 'sha256' => $export->getSha256()]));

        return [$export, $bytes];
    }

    /**
     * The exports the actor may see.
     *
     * @return list<RegisterExport>
     */
    public function list(int $limit = 50): array
    {
        $context = $this->access->context(null);
        $all     = $this->access->can(WorkingTimePermission::Export);

        return $this->runner->read(function () use ($context, $all, $limit): array {
            $visible = [];
            foreach ($this->store->exports($context->tenant, $all ? null : $context->actor->value, $limit) as $export) {
                if ($export->getRequestedById() === $context->actor->value || ($all && !$export->isOwnCopy())) {
                    $visible[] = $export;
                }
            }

            return $visible;
        });
    }

    /**
     * Drops the files of exports whose download window ended (the rows and hashes stay).
     */
    public function expire(): int
    {
        $count = 0;
        foreach ($this->runner->read(fn (): array => $this->store->expiredExports($this->clock->now(), 500)) as $expired) {
            $this->runner->write(function () use ($expired): void {
                $export = $this->store->export($expired->getId());
                if ($export !== null) {
                    $this->files->delete($export->getId());
                    $export->expire();
                }
            });
            ++$count;
        }

        return $count;
    }

    private function mayDownload(RegisterExport $export, RegisterContext $context): bool
    {
        if ($export->getRequestedById() === $context->actor->value) {
            return true;
        }

        return !$export->isOwnCopy() && $this->access->can(WorkingTimePermission::Export);
    }

    private function checkPeriod(string $from, string $to): void
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $from) !== 1 || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $to) !== 1) {
            throw new ExportRefusedException('export.error.period');
        }
        $current = LocalDate::of($this->clock->now(), new DateTimeZone('UTC'))->month();
        $months  = ((int) $to - (int) $from) * 12 + (int) substr($to, 5) - (int) substr($from, 5) + 1;
        if (strcmp($to, $from) < 0 || $months > $this->maxMonths || strcmp($to, $current) > 0) {
            throw new ExportRefusedException('export.error.period');
        }
    }
}
