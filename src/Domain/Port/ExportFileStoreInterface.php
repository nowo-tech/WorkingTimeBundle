<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Port;

/**
 * Keeps the bytes of a ready export for its download window (contract section 8.2). Default: DatabaseExportFileStore
 * (the `export_file` blob table).
 */
interface ExportFileStoreInterface
{
    /**
     * Stores the bytes of an export (replaces any earlier ones).
     */
    public function put(string $exportId, string $bytes): void;

    /**
     * The bytes; null when gone.
     */
    public function get(string $exportId): ?string;

    /**
     * Drops the bytes (expiry, erasure).
     */
    public function delete(string $exportId): void;
}
