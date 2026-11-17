<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Service;

use Nowo\WorkingTimeBundle\Domain\Register\Enum\ExportFormat;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\ExportRefusedException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\PermissionDeniedException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\RegisterUnavailableException;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterExport;
use Nowo\WorkingTimeBundle\Domain\Shared\GroupRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

/**
 * Register exports (contract section 7, spec 198 FR-021 / FR-022): a worker's own copy (`clock`), an inspection export
 * of a worker or the team (`export`), at most the configured months, downloadable for the download window.
 *
 * @throws RegisterUnavailableException
 * @throws PermissionDeniedException
 */
interface RegisterExportServiceInterface
{
    /**
     * Asks for an export (`Y-m` months). `$worker` null = the team (or `$group`), which is a ZIP.
     *
     * @throws ExportRefusedException
     */
    public function request(?WorkerRef $worker, ?GroupRef $group, string $monthFrom, string $monthTo, ExportFormat $format, ?string $locale = null): RegisterExport;

    /**
     * An export the actor may see.
     */
    public function status(string $exportId): RegisterExport;

    /**
     * The export and its bytes, while downloadable (audited).
     *
     * @return array{RegisterExport, string}
     */
    public function open(string $exportId): array;

    /**
     * The actor's exports (and, with `export`, the tenant's inspection exports), newest first.
     *
     * @return list<RegisterExport>
     */
    public function list(int $limit = 50): array;
}
