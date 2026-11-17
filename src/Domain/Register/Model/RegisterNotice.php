<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Model;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Domain\Shared\Uuid;

/**
 * One notice of the outbox (spec 198 FR-025, spec 241 T018): queued with a dedupe key (a notice is never sent twice),
 * delivered once its `deliverable_from` has passed (inside the recipient's schedule), marked sent with a conditional
 * update. Purged 400 days after delivery.
 */
class RegisterNotice
{
    /** Kinds. */
    public const CORRECTION_REQUESTED = 'register.correction_requested';
    public const CORRECTION_DECIDED   = 'register.correction_decided';
    public const CORRECTION_RECORDED  = 'register.correction_recorded';
    public const REMINDER_IN          = 'register.reminder_in';
    public const REMINDER_OUT         = 'register.reminder_out';
    public const MONTHLY_SUMMARY      = 'register.monthly_summary';
    public const EXPORT_READY         = 'register.export_ready';

    private ?DateTimeImmutable $sentAt = null;

    /**
     * @param array<string, scalar|null> $params
     */
    private function __construct(
        private string $id,
        private string $tenantId,
        private string $workerId,
        private string $kind,
        private array $params,
        private ?string $actionPath,
        private string $dedupeKey,
        private DateTimeImmutable $deliverableFrom,
        private DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * A queued notice.
     *
     * @param array<string, scalar|null> $params
     */
    public static function queue(string $id, string $tenantId, string $workerId, string $kind, array $params, ?string $actionPath, string $dedupeKey, DateTimeImmutable $deliverableFrom, DateTimeImmutable $at): self
    {
        return new self(Uuid::assert($id), $tenantId, $workerId, $kind, $params, $actionPath, $dedupeKey, WorkdayRevision::utc($deliverableFrom), WorkdayRevision::utc($at));
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

    /** Recipient. */
    public function getWorkerId(): string
    {
        return $this->workerId;
    }

    /** Kind. */
    public function getKind(): string
    {
        return $this->kind;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /** Local action path. */
    public function getActionPath(): ?string
    {
        return $this->actionPath;
    }

    /** Dedupe key. */
    public function getDedupeKey(): string
    {
        return $this->dedupeKey;
    }

    /** Not before. */
    public function getDeliverableFrom(): DateTimeImmutable
    {
        return $this->deliverableFrom;
    }

    /** When queued. */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** When delivered. */
    public function getSentAt(): ?DateTimeImmutable
    {
        return $this->sentAt;
    }
}
