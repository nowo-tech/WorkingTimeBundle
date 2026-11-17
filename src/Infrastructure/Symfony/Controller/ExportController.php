<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Controller;

use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\WorkingTimeBundle\Application\Register\RegisterAccess;
use Nowo\WorkingTimeBundle\Domain\Port\WorkerDirectoryInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ExportFormat;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\ExportRefusedException;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterExport;
use Nowo\WorkingTimeBundle\Domain\Register\Service\RegisterExportServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkingTimePermission;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\ExportType;
use Psr\Clock\ClockInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function in_array;
use function is_array;
use function is_string;
use function reset;
use function strlen;

/**
 * Exports (contract section 11.1): the worker's own copy (`clock`), inspection exports of a worker or the team
 * (`export`), their status, and the download while the window lasts (`X-Content-SHA256`, audited; after the window,
 * or for someone else's own copy: 404).
 *
 * @internal
 */
final readonly class ExportController
{
    /**
     * @param list<string> $formats `register.export.formats`
     */
    public function __construct(
        private UiSupport $ui,
        private RegisterAccess $access,
        private RegisterExportServiceInterface $exports,
        private WorkerDirectoryInterface $directory,
        private ClockInterface $clock,
        private array $formats = ['pdf', 'csv'],
    ) {
    }

    /**
     * `GET|POST {register}/exports`.
     */
    public function exports(Request $request): Response
    {
        return $this->ui->guard(function () use ($request): Response {
            $member     = $this->access->context(null);
            $inspection = $this->access->can(WorkingTimePermission::Export);
            $workers    = [];
            if ($inspection) {
                foreach ($this->directory->workers($member->tenant) as $worker) {
                    $workers[$worker->displayName] = $worker->worker->value;
                }
            }
            $month   = $this->clock->now()->format('Y-m');
            $formats = [];
            foreach (['pdf', 'csv', 'zip'] as $format) {
                if (in_array($format, $this->formats, true) || $format === 'zip') {
                    $formats['export.format.' . $format] = $format;
                }
            }
            $form = $this->ui->form(ExportType::class, ['scope' => 'own', 'from' => $month, 'to' => $month, 'format' => reset($formats)], ['inspection' => $inspection, 'workers' => $workers, 'formats' => $formats]);
            $form->handleRequest($request);
            if (!$form->isSubmitted()) {
                return $this->page($form, Response::HTTP_OK);
            }
            if ($form->isValid()) {
                /** @var array<string, mixed> $data */
                $data   = $form->getData();
                $scope  = $data['scope'] ?? 'own';
                $worker = match ($scope) {
                    'own'    => new WorkerRef($member->actor->value),
                    'worker' => is_string($data['worker'] ?? null) ? new WorkerRef($data['worker']) : null,
                    default  => null,
                };
                try {
                    if ($scope === 'worker' && $worker === null) {
                        throw new ExportRefusedException('export.error.worker');
                    }
                    $this->exports->request($worker, null, is_string($data['from'] ?? null) ? $data['from'] : '', is_string($data['to'] ?? null) ? $data['to'] : '', ExportFormat::from(is_string($data['format'] ?? null) ? $data['format'] : 'csv'), $request->getLocale());
                    $this->ui->flash($request, 'success', 'export.requested');

                    return $this->ui->redirect('nowo_working_time_exports');
                } catch (ExportRefusedException $e) {
                    $form->addError(new FormError($this->ui->trans($e->key)));
                }
            }

            return $this->page($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        });
    }

    /**
     * `GET {register}/exports/{id}/download`.
     */
    public function download(Request $request, string $id): Response
    {
        return $this->ui->guard(function () use ($id): Response {
            [$export, $bytes] = $this->exports->open($id);

            return new Response($bytes, Response::HTTP_OK, [
                'Content-Type'           => $export->getContentType() ?? 'application/octet-stream',
                'Content-Length'         => (string) strlen($bytes),
                'Content-Disposition'    => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $export->getFileName() ?? 'export', 'export'),
                'X-Content-SHA256'       => (string) $export->getSha256(),
                'Cache-Control'          => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function page(FormInterface $form, int $status): Response
    {
        $now  = $this->clock->now();
        $rows = [];
        foreach ($this->exports->list() as $export) {
            $rows[] = self::row($export, $export->isDownloadable($now) ? $this->ui->url('nowo_working_time_export_download', ['id' => $export->getId()]) : null);
        }

        return $this->ui->render('@NowoWorkingTime/register/exports.html.twig', [
            'page' => new ListPage(
                new PageHeader(UiSupport::label('export.title'), UiSupport::label('export.intro'), [], tabs: $this->ui->tabs('exports')),
                [
                    new Column('created', UiSupport::label('export.col.created'), required: true),
                    new Column('period', UiSupport::label('export.col.period')),
                    new Column('scope', UiSupport::label('export.col.scope')),
                    new Column('format', UiSupport::label('export.col.format'), priority: 2),
                    new Column('status', UiSupport::label('export.col.status')),
                    new Column('sha256', UiSupport::label('export.col.sha256'), priority: 3),
                ],
                $rows,
                rowActions: static fn (mixed $row): array => is_array($row) && is_string($row['href'] ?? null) ? [new Action('download', UiSupport::label('export.download'), $row['href'], attributes: ['data-testid' => 'wt-export-download'])] : [],
                empty: new EmptyState(UiSupport::label('export.empty')),
                bundle: 'NowoWorkingTimeBundle',
                layout: $this->ui->layout(),
            ),
            'form' => $form->createView(),
        ], $status);
    }

    /**
     * @return array{created: string, period: string, scope: string, format: string, status: string, sha256: string, href: ?string}
     */
    private static function row(RegisterExport $export, ?string $href): array
    {
        return [
            'created' => $export->getCreatedAt()->format('Y-m-d H:i'),
            'period'  => $export->monthFrom() === $export->monthTo() ? $export->monthFrom() : $export->monthFrom() . ' – ' . $export->monthTo(),
            'scope'   => $export->isOwnCopy() ? 'own' : ($export->getWorkerId() === null ? 'team' : 'worker'),
            'format'  => $export->getFormat()->value,
            'status'  => $export->getStatus()->value,
            'sha256'  => (string) $export->getSha256(),
            'href'    => $href,
        ];
    }
}
