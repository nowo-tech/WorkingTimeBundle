<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Export;

use Nowo\Brand\Contract\BrandProviderInterface;
use Nowo\WorkingTimeBundle\Application\Register\Port\ExportGeneratorInterface;
use Nowo\WorkingTimeBundle\Application\Register\Port\RegisterStoreInterface;
use Nowo\WorkingTimeBundle\Application\Register\RegisterAccess;
use Nowo\WorkingTimeBundle\Application\Register\RegisterReader;
use Nowo\WorkingTimeBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\WorkingTimeBundle\Domain\Port\ExportFileStoreInterface;
use Nowo\WorkingTimeBundle\Domain\Port\RegisterPdfRendererInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkerDirectoryInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeAuditSinkInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeTenantResolverInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ExportFormat;
use Nowo\WorkingTimeBundle\Domain\Register\Event\RegisterExportReady;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterExport;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkdayRevision;
use Nowo\WorkingTimeBundle\Domain\Register\Service\ChainVerifierInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\AuditEntry;
use Nowo\WorkingTimeBundle\Domain\Shared\GroupRef;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Domain\TranslationDomain;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;
use ZipArchive;

use function array_key_first;
use function file_get_contents;
use function hash;
use function iconv;
use function is_file;
use function preg_replace;
use function sprintf;
use function strrchr;
use function strtolower;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;

/**
 * Generates one register export (spec 198 FR-021 / FR-022, contract section 12): claims it (one generator wins),
 * verifies the chains first (a broken chain is printed in the file and audited, never hidden), then builds one
 * worker's PDF or CSV, or the team ZIP (PDF + CSV per worker, the team CSV and `SHA256SUMS.txt`). The file's SHA-256
 * and the chain heads are stored with the export and written to the audit trail; `RegisterExportReady` after the
 * commit. A failure stores only an error code.
 */
final readonly class RegisterExportGenerator implements ExportGeneratorInterface
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private RegisterStoreInterface $store,
        private RegisterReader $reader,
        private RegisterAccess $access,
        private ChainVerifierInterface $verifier,
        private RegisterReportBuilder $reports,
        private ?RegisterPdfRendererInterface $pdf,
        private ExportFileStoreInterface $files,
        private BrandProviderInterface $brand,
        private WorkingTimeTenantResolverInterface $tenants,
        private WorkerDirectoryInterface $directory,
        private TransactionRunnerInterface $runner,
        private WorkingTimeAuditSinkInterface $audit,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
        private ?EventDispatcherInterface $dispatcher = null,
        private ?LoggerInterface $logger = null,
        private bool $verifyFirst = true,
        private int $downloadDays = 7,
    ) {
    }

    /**
     * Generates the export.
     */
    public function generate(string $exportId): bool
    {
        if (!$this->runner->write(fn (): bool => $this->store->claimExport($exportId))) {
            return false;
        }
        try {
            $this->build($exportId);
        } catch (Throwable $e) {
            $this->logger?->error('Working-time register export failed.', ['export' => $exportId, 'exception' => $e::class]);
            $this->runner->write(function () use ($exportId, $e): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
                $this->store->export($exportId)?->fail(substr(strrchr('\\' . $e::class, '\\') ?: 'error', 1));
            });

            return false;
        }

        return true;
    }

    private function build(string $exportId): void
    {
        $export   = $this->runner->read(fn (): ?RegisterExport => $this->store->export($exportId)) ?? throw new RuntimeException('Export gone.');
        $tenant   = new TenantRef($export->getTenantId());
        $settings = $this->access->settings($tenant);
        $locale   = $export->getLocale() ?? 'en';
        $from     = new LocalDate($export->monthFrom() . '-01');
        $to       = (new LocalDate($export->monthTo() . '-01'))->toDateTime()->modify('last day of this month');
        $to       = LocalDate::fromDateTime($to);
        $now      = $this->clock->now();
        $org      = $this->tenants->profile($tenant)->name ?? $tenant->value;
        $workers  = $this->workers($export, $tenant, $from, $to);
        $period   = $export->monthFrom() === $export->monthTo() ? $export->monthFrom() : $export->monthFrom() . ' – ' . $export->monthTo();
        $base     = sprintf('working-time-%s-%s', $export->monthFrom(), $export->monthTo());
        $heads    = [];
        $files    = [];
        $team     = [];
        foreach ($workers as $worker => $name) {
            $ref       = new WorkerRef($worker);
            $integrity = null;
            if ($this->verifyFirst) {
                $report = $this->verifier->verify($tenant, $ref);
                if (!$report->isIntact()) {
                    $integrity = $this->translator->trans('export.integrity_broken', ['%seq%' => $report->broken[0]['seq'], '%reason%' => $report->broken[0]['reason']], TranslationDomain::NAME, $locale);
                }
            }
            $tip            = $this->runner->read(fn (): ?array => ($link = $this->store->chainHead($tenant, $ref)) instanceof WorkdayRevision ? ['hash' => $link->getHash(), 'seq' => $link->getSeq(), 'at' => WorkdayRevision::iso($link->getCreatedAt()), 'day' => $this->store->workday($link->getWorkdayId())?->date()->value] : null); // @igor-ignore - the runner calls this closure at once and keeps nothing
            $head           = $tip['hash'] ?? null;
            $heads[$worker] = $tip ?? ''; // the head with its `seq`, time and workday date (audit ST-D1b, ST-H2)
            $days           = $this->runner->read(fn (): array => $this->reader->worker($tenant, $ref, $from, $to, $now));
            $slug           = $base . '-' . $this->slug($name) . '-' . substr(hash('sha256', $worker), 0, 8);
            $rows           = RegisterCsv::rows($org, $days, $settings->ipEvidence());
            foreach ($rows as $row) {
                $team[] = $row;
            }
            if ($export->getFormat() !== ExportFormat::Csv) {
                $pdf                   = $this->pdf ?? throw new RuntimeException('No PDF renderer.');
                $files[$slug . '.pdf'] = $pdf->render($this->reports->build($days, $org, $name, $period, $export->getRequestedByName(), $head, $integrity, $settings->ipEvidence(), $now, $locale), $this->brand->getBrand());
            }
            if ($export->getFormat() !== ExportFormat::Pdf) {
                $files[$slug . '.csv'] = RegisterCsv::encode($rows);
            }
        }
        if ($export->getFormat() === ExportFormat::Zip) {
            $files[$base . '-team.csv'] = RegisterCsv::encode($team);
            [$name, $type, $bytes]      = [$base . '.zip', 'application/zip', $this->zip($files)];
        } else {
            $name  = array_key_first($files) ?? $base . '.csv';
            $bytes = $files[$name] ?? RegisterCsv::encode([]);
            $type  = $export->getFormat() === ExportFormat::Pdf ? 'application/pdf' : 'text/csv; charset=UTF-8';
        }
        $sha256 = hash('sha256', $bytes);
        $this->runner->write(function () use ($exportId, $name, $type, $sha256, $bytes, $heads, $now, $tenant): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $export = $this->store->export($exportId) ?? throw new RuntimeException('Export gone.');
            $export->complete($name, $type, $sha256, $heads, $now, $this->downloadDays);
            $this->files->put($exportId, $bytes);
            $this->audit->record(new AuditEntry('working_time.exported', $tenant, null, $export->getWorkerId() === null ? null : new WorkerRef($export->getWorkerId()), [
                'export' => $exportId,
                'from'   => $export->monthFrom(),
                'to'     => $export->monthTo(),
                'format' => $export->getFormat()->value,
                'scope'  => $export->getWorkerId() === null ? 'team' : ($export->isOwnCopy() ? 'own' : 'worker'),
                'sha256' => $sha256,
            ]));
        });
        $this->dispatcher?->dispatch(new RegisterExportReady($tenant, $exportId));
    }

    /**
     * The workers of the export with the name the register keeps (else the directory's).
     *
     * @return array<string, string> worker ref => name
     */
    private function workers(RegisterExport $export, TenantRef $tenant, LocalDate $from, LocalDate $to): array
    {
        $names = [];
        if ($export->getWorkerId() !== null) {
            $ref = new WorkerRef($export->getWorkerId());
            foreach ($this->runner->read(fn (): array => $this->store->workdaysBetween($tenant, $ref, $from, $to)) as $workday) {
                $names[$workday->getWorkerId()] = $workday->getWorkerName();
            }

            return $names !== [] ? $names : [$ref->value => $this->directory->worker($tenant, $ref)->displayName ?? $ref->value];
        }
        $members = null;
        if ($export->getGroupId() !== null) {
            $members = [];
            foreach ($this->directory->workers($tenant, new GroupRef($export->getGroupId())) as $member) {
                $members[] = $member->worker;
            }
        }
        foreach ($this->runner->read(fn (): array => $this->store->tenantWorkdaysBetween($tenant, $from, $to, $members)) as $workday) {
            $names[$workday->getWorkerId()] = $workday->getWorkerName();
        }

        return $names;
    }

    /**
     * @param array<string, string> $files name => bytes
     */
    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nowo-wt-export-') ?: throw new RuntimeException('No temp file.');
        try {
            $zip = new ZipArchive();
            $zip->open($path, ZipArchive::OVERWRITE);
            $manifest = '';
            foreach ($files as $name => $bytes) {
                $zip->addFromString($name, $bytes);
                $manifest .= hash('sha256', $bytes) . '  ' . $name . "\n";
            }
            $zip->addFromString('SHA256SUMS.txt', $manifest);
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function slug(string $name): string
    {
        $ascii = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: ''));

        return trim($ascii, '-') ?: 'worker';
    }
}
