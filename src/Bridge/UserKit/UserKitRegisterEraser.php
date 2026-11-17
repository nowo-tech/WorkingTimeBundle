<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Bridge\UserKit;

use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;
use Nowo\WorkingTimeBundle\Application\Register\RegisterPersonalData;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

/**
 * UserKit bridge (contract section 14.4): an erasure deletes the worker's register preferences and their export files;
 * the register itself (workdays, segments, chain links, corrections, the worker's name in it) is kept until the end of
 * retention (GDPR art. 17.3.b, art. 34.9 ET).
 */
final readonly class UserKitRegisterEraser implements PersonalDataEraserInterface
{
    /** Eraser key. */
    public const KEY = 'working_time';

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private RegisterPersonalData $data,
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
     * Erases what is not part of the legal record.
     */
    public function erase(ErasureRequest $request): void
    {
        $worker = $this->workers->workerOf($request->user, $request->identifier());
        if ($worker instanceof WorkerRef) {
            $this->data->erase($worker);
        }
    }
}
