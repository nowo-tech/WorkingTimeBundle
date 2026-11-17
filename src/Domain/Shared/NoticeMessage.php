<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Shared;

use InvalidArgumentException;

use function count;
use function is_scalar;
use function str_starts_with;

/**
 * One notice the bundle asks the notifier to deliver (contract section 5): the recipient, the kind
 * (`register.reminder_in`, `register.monthly_summary`, …), at most 20 scalar parameters (never free text a person
 * typed, never health data), a local action path and the recipient's locale.
 */
final readonly class NoticeMessage
{
    /**
     * @param array<string, scalar|null> $params
     */
    public function __construct(
        public TenantRef $tenant,
        public WorkerRef $recipient,
        public string $kind,
        public array $params = [],
        public ?string $actionPath = null,
        public ?string $locale = null,
    ) {
        if (count($params) > 20) {
            throw new InvalidArgumentException('A notice has at most 20 parameters.');
        }
        foreach ($params as $value) {
            if ($value !== null && !is_scalar($value)) {
                throw new InvalidArgumentException('Notice parameters are scalars.');
            }
        }
        if ($actionPath !== null && (!str_starts_with($actionPath, '/') || str_starts_with($actionPath, '//'))) {
            throw new InvalidArgumentException('A notice action is a local path.');
        }
    }
}
