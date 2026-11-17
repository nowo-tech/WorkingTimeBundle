<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Model;

use function is_resource;
use function is_string;
use function rewind;
use function stream_get_contents;

/**
 * The bytes of a ready export in the default database store (`export_file`). Deleted at expiry.
 */
class RegisterExportFile
{
    /**
     * @param resource|string $content the bytes (Doctrine hydrates a blob as a stream)
     */
    private function __construct(
        private string $exportId,
        private mixed $content,
    ) {
    }

    /**
     * The file of an export.
     */
    public static function of(string $exportId, string $content): self
    {
        return new self($exportId, $content);
    }

    /** Export id (primary key). */
    public function getExportId(): string
    {
        return $this->exportId;
    }

    /** The bytes. */
    public function getContent(): string
    {
        if (is_resource($this->content)) {
            rewind($this->content);

            return (string) stream_get_contents($this->content);
        }

        return is_string($this->content) ? $this->content : '';
    }
}
