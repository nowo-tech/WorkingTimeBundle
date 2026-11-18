<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Model;

use DateTimeImmutable;
use LogicException;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ExportFormat;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ExportStatus;
use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\Uuid;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;

use function mb_substr;
use function sprintf;

/**
 * One register export (spec 198 FR-021 / FR-022): who asked, for whom (one worker, or the team / a group), the months
 * (at most 12), the format and, once ready, the file name, type, SHA-256 and the chain heads it was built on.
 * Downloadable for the download window; the row and its hash stay after the file is gone.
 */
class RegisterExport
{
    private ExportStatus $status = ExportStatus::Pending;

    private ?string $fileName = null;

    private ?string $contentType = null;

    private ?string $sha256 = null;

    /** @var array<string, array{hash: string, seq: int, at?: string}|string> worker ref => chain head with its seq and time ('' for an empty chain; a bare hash in 0.1.0 rows) */
    private array $chainHeads = [];

    private ?DateTimeImmutable $readyAt = null;

    private ?DateTimeImmutable $expiresAt = null;

    private ?string $errorCode = null;

    private function __construct(
        private string $id,
        private string $tenantId,
        private string $requestedById,
        private string $requestedByName,
        private ?string $workerId,
        private ?string $groupId,
        private bool $ownCopy,
        private DateTimeImmutable $monthFrom,
        private DateTimeImmutable $monthTo,
        private ExportFormat $format,
        private ?string $locale,
        private DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * A new pending export. `$monthFrom` / `$monthTo` are `Y-m`.
     */
    public static function request(string $id, string $tenantId, ActorRef $by, string $byName, ?string $workerId, ?string $groupId, bool $ownCopy, string $monthFrom, string $monthTo, ExportFormat $format, ?string $locale, DateTimeImmutable $at): self
    {
        return new self(
            Uuid::assert($id),
            $tenantId,
            $by->value,
            mb_substr($byName, 0, WorkerProfile::NAME_MAX),
            $workerId,
            $groupId,
            $ownCopy,
            new DateTimeImmutable($monthFrom . '-01 00:00:00'),
            new DateTimeImmutable($monthTo . '-01 00:00:00'),
            $format,
            $locale,
            WorkdayRevision::utc($at),
        );
    }

    /**
     * Marks the export ready.
     *
     * @param array<string, array{hash: string, seq: int, at?: string}|string> $chainHeads
     */
    public function complete(string $fileName, string $contentType, string $sha256, array $chainHeads, DateTimeImmutable $at, int $downloadDays): void
    {
        if ($this->status !== ExportStatus::Running && $this->status !== ExportStatus::Pending) {
            throw new LogicException('Only a pending or running export completes.');
        }
        $this->status      = ExportStatus::Ready; // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        $this->fileName    = $fileName; // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        $this->contentType = $contentType; // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        $this->sha256      = $sha256; // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        $this->chainHeads  = $chainHeads; // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        $this->readyAt     = WorkdayRevision::utc($at); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        $this->expiresAt   = $this->readyAt->modify(sprintf('+%d days', $downloadDays)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
    }

    /**
     * Marks the export failed (a code, never a message with data).
     */
    public function fail(string $errorCode): void
    {
        $this->status    = ExportStatus::Failed; // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        $this->errorCode = mb_substr($errorCode, 0, 120); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
    }

    /**
     * Marks the export expired (its file was dropped).
     */
    public function expire(): void
    {
        $this->status = ExportStatus::Expired; // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
    }

    /**
     * Whether the file may be downloaded at `$now`.
     */
    public function isDownloadable(DateTimeImmutable $now): bool
    {
        return $this->status === ExportStatus::Ready && $this->expiresAt instanceof DateTimeImmutable && $now < $this->expiresAt;
    }

    /** Row id. */
    public function getId(): string
    {
        return $this->id;
    }

    /** Tenant reference. */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    /** Requester. */
    public function getRequestedById(): string
    {
        return $this->requestedById;
    }

    /** Requester's name. */
    public function getRequestedByName(): string
    {
        return $this->requestedByName;
    }

    /** One worker (null: the team or a group). */
    public function getWorkerId(): ?string
    {
        return $this->workerId;
    }

    /** Group of a team export. */
    public function getGroupId(): ?string
    {
        return $this->groupId;
    }

    /** Whether it is the worker's own copy. */
    public function isOwnCopy(): bool
    {
        return $this->ownCopy;
    }

    /** First month (`Y-m`). */
    public function monthFrom(): string
    {
        return $this->monthFrom->format('Y-m');
    }

    /** Last month (`Y-m`). */
    public function monthTo(): string
    {
        return $this->monthTo->format('Y-m');
    }

    /** Format. */
    public function getFormat(): ExportFormat
    {
        return $this->format;
    }

    /** Locale of the file. */
    public function getLocale(): ?string
    {
        return $this->locale;
    }

    /** Status. */
    public function getStatus(): ExportStatus
    {
        return $this->status;
    }

    /** File name. */
    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    /** MIME type. */
    public function getContentType(): ?string
    {
        return $this->contentType;
    }

    /** SHA-256 of the file. */
    public function getSha256(): ?string
    {
        return $this->sha256;
    }

    /**
     * @return array<string, array{hash: string, seq: int, at?: string}|string>
     */
    public function getChainHeads(): array
    {
        return $this->chainHeads;
    }

    /** When it was requested. */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** When it became ready. */
    public function getReadyAt(): ?DateTimeImmutable
    {
        return $this->readyAt;
    }

    /** End of the download window. */
    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /** Failure code. */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }
}
