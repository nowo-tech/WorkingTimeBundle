<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Bridge\UserKit;

use Nowo\UserKitBundle\PersonalData\PersonalDataExportProviderInterface;
use Nowo\WorkingTimeBundle\Application\Leave\LeavePersonalData;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

/**
 * UserKit bridge, leave half (contract section 14.4; audit TD-L7): the `working_time_leave` section of the personal
 * data export (allowance lines and the worker's own policies in every tenant).
 */
final readonly class UserKitLeaveExportProvider implements PersonalDataExportProviderInterface
{
    /** Section key. */
    public const SECTION = 'working_time_leave';

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private LeavePersonalData $data,
        private WorkerOfUserInterface $workers,
    ) {
    }

    /**
     * The section key.
     */
    public function getSectionKey(): string
    {
        return self::SECTION;
    }

    /**
     * Whether the user is a worker.
     */
    public function supports(object $user): bool
    {
        return $this->workers->workerOf($user) instanceof WorkerRef;
    }

    /**
     * The section.
     */
    public function export(object $user): mixed
    {
        $worker = $this->workers->workerOf($user);

        return $worker instanceof WorkerRef ? $this->data->export($worker) : null;
    }
}
