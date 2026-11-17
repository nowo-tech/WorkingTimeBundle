<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Shared;

use InvalidArgumentException;

use function mb_strlen;
use function trim;

/**
 * A worker as the host's directory describes them (contract section 5). `active` false (or a `leftOn` date) still
 * lets the register show their past days; only members answer.
 */
final readonly class WorkerProfile
{
    /** Longest display name kept in the register (`worker_name`). */
    public const NAME_MAX = 180;

    /** @var list<GroupRef> */
    public array $groups;

    /**
     * @param iterable<GroupRef> $groups
     */
    public function __construct(
        public WorkerRef $worker,
        public string $displayName,
        public ?string $email = null,
        public ?string $locale = null,
        public bool $active = true,
        public ?LocalDate $joinedOn = null,
        public ?LocalDate $leftOn = null,
        iterable $groups = [],
        public ?float $contractRatio = null,
    ) {
        if (trim($displayName) === '' || mb_strlen($displayName) > self::NAME_MAX) {
            throw new InvalidArgumentException('A worker display name has 1-180 characters.');
        }
        if ($contractRatio !== null && ($contractRatio <= 0.0 || $contractRatio > 1.0)) {
            throw new InvalidArgumentException('contractRatio is in ]0, 1].');
        }
        $list = [];
        foreach ($groups as $group) {
            $list[] = $group;
        }
        $this->groups = $list;
    }

    /**
     * Whether the worker belongs to the group (exact reference).
     */
    public function inGroup(GroupRef $group): bool
    {
        foreach ($this->groups as $mine) {
            if ($mine->equals($group)) {
                return true;
            }
        }

        return false;
    }
}
