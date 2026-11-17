<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port;

use Nowo\WorkingTimeBundle\Domain\Port\ExportFileStoreInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterExportFile;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\DoctrineTransactionRunner;

/**
 * Default export file store: the `export_file` blob table on the bundle's entity manager, inside the caller's write.
 */
final readonly class DatabaseExportFileStore implements ExportFileStoreInterface
{
    public function __construct(private DoctrineTransactionRunner $runner)
    {
    }

    /**
     * Stores the bytes.
     */
    public function put(string $exportId, string $bytes): void
    {
        $this->delete($exportId);
        $this->runner->manager()->persist(RegisterExportFile::of($exportId, $bytes));
    }

    /**
     * The bytes, null when gone.
     */
    public function get(string $exportId): ?string
    {
        $file = $this->runner->manager()->find(RegisterExportFile::class, $exportId);
        if ($file === null) {
            return null;
        }
        return $file->getContent();
    }

    /**
     * Drops the bytes.
     */
    public function delete(string $exportId): void
    {
        $file = $this->runner->manager()->find(RegisterExportFile::class, $exportId);
        if ($file !== null) {
            $this->runner->manager()->remove($file);
            $this->runner->manager()->flush();
        }
    }
}
