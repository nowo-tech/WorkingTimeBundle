<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Bridge\UserKit;

use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;
use Nowo\WorkingTimeBundle\Application\Leave\LeavePersonalData;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

/**
 * UserKit bridge, leave half (contract section 14.4; audit TD-L7): an erasure blanks the reasons of the worker's
 * allowance lines and the notes of their own policies, and forgets them as the creator of other people's rows; the
 * amounts stay until retention.
 */
final readonly class UserKitLeaveEraser implements PersonalDataEraserInterface
{
    /** Eraser key. */
    public const KEY = 'working_time_leave';

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private LeavePersonalData $data,
        private WorkerOfUserInterface $workers,
    ) {
    }

    /**
     * The eraser key.
     */
    public function getKey(): string
    {
        return self::KEY;
    }

    /**
     * Whether the user is a worker.
     */
    public function supports(object $user): bool
    {
        return $this->workers->workerOf($user) instanceof WorkerRef;
    }

    /**
     * Erases the worker's leave free text and actor references.
     */
    public function erase(ErasureRequest $request): void
    {
        $worker = $this->workers->workerOf($request->user, $request->identifier());
        if ($worker instanceof WorkerRef) {
            $this->data->erase($worker);
        }
    }
}
