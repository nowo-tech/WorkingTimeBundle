<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Service;

use Nowo\WorkingTimeBundle\Domain\Register\Exception\CorrectionRefusedException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\PermissionDeniedException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\RegisterUnavailableException;
use Nowo\WorkingTimeBundle\Domain\Register\Model\CorrectionInput;
use Nowo\WorkingTimeBundle\Domain\Register\Model\CorrectionRequest;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkdayRevision;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

/**
 * Corrections — never a silent edit (spec 198 FR-014 – FR-016, contract section 7). Every method passes the gate.
 * A stale decision throws `StaleCorrectionDecisionException` (409 + Retry-After, nothing stored, no notice).
 *
 * @throws RegisterUnavailableException
 * @throws PermissionDeniedException
 * @throws CorrectionRefusedException
 */
interface CorrectionServiceInterface
{
    /**
     * The signed-in worker asks to correct one of their days inside the correction window.
     */
    public function request(LocalDate $date, CorrectionInput $input): CorrectionRequest;

    /**
     * A manager of the worker (never the worker) approves or rejects a pending request, on the version they saw.
     */
    public function decide(string $correctionId, bool $approve, ?string $comment, int $expectedVersion): CorrectionRequest;

    /**
     * A manager records a correction on a worker's day: applied at once, waiting for the worker's answer.
     */
    public function record(WorkerRef $worker, LocalDate $date, CorrectionInput $input): WorkdayRevision;

    /**
     * The worker acknowledges a recorded correction, or contests it with a comment (10–500 characters).
     */
    public function answer(string $recordedRevisionId, bool $contest, ?string $comment): WorkdayRevision;
}
