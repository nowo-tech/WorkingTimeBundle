<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Bridge\UserKit;

use Nowo\UserKitBundle\PersonalData\PersonalDataExportProviderInterface;
use Nowo\WorkingTimeBundle\Application\Register\RegisterPersonalData;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

/**
 * UserKit bridge (contract section 14.4): the `working_time` section of a person's data export — their workdays
 * (segments, sources, IP prefixes, trail), correction requests and decisions, exports metadata and preferences.
 */
final readonly class UserKitRegisterExportProvider implements PersonalDataExportProviderInterface
{
    /** Section key. */
    public const SECTION = 'working_time';

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private RegisterPersonalData $data,
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
