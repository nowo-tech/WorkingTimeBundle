<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Http;

use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\Symfony\EventListener\ConcurrencyConflictResponder;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Throwable;

/**
 * The bundle's own conflict answer on its routes (REQ-RUNTIME-003 rule 6), at `kernel.exception` priority 63, just
 * after NowoTransactionDoctrineBundle's conflict responder (64):
 *
 * - without the responder (the bundle is not registered) it answers every lock conflict of the bundle's routes;
 * - with it, it answers only what the responder left: a raw deadlock, lock-wait timeout, serialization failure or lost
 *   savepoint that no write unit wrapped (the responder maps those only with `map_transient_failures: true`).
 *
 * Detection is transaction-doctrine's bounded walk (`TransientFailureClassifier::findConflict()`, >= 1.7): the
 * `getPrevious()` chain and Messenger's wrapped exceptions (a deadlock in the second handler of a synchronous
 * `HandlerFailedException`). Unique violations and lost connections are never conflicts and fall through to the
 * host's error handling. The code is `ConflictCode::forRequest()` (the request's commit watermark when the responder's
 * bundle took one): **409 + `Retry-After` + `X-Conflict-Code: CONFLICT_001`** when nothing was committed, **409 +
 * `X-Conflict-Code: CONFLICT_002`** without `Retry-After` when part was (the status follows the policy, 503 when the
 * host configured it). With the responder's bundle the answer is the responder's own `respond()` (the bundle's JSON
 * formatter, the branded HTML page); without it, JSON `{"error": …, "code": "CONFLICT_00n"}` or a short text page.
 * Either way one constant warning is logged (route, method, code; never the URL, SQL or a message). Host routes are
 * never answered. Stateless (FrankenPHP worker, kernel reset off).
 */
final readonly class ConflictFallbackListener
{
    /** Text of a retry-safe conflict. */
    public const RETRY_TEXT = 'Someone changed this at the same moment. Try again.';

    /** Text of a conflict after part of the request was committed. */
    public const RELOAD_TEXT = 'Part of your change may have been saved while someone changed the same data. Reload before trying again.';

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private ConflictResponsePolicy $policy = new ConflictResponsePolicy(),
        private ?ConcurrencyConflictResponder $responder = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * `kernel.exception`.
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!WorkingTimeConflictFormatter::isBundleRoute($request)) {
            return;
        }
        $failure  = $event->getThrowable();
        $conflict = TransientFailureClassifier::findConflict($failure);
        if (!$conflict instanceof Throwable) {
            return;
        }
        $code = ConflictCode::forRequest($request, $failure);
        $this->logger?->warning('nowo_working_time answered a database conflict on its route.', [
            'route'  => $request->attributes->get('_route'),
            'method' => $request->getMethod(),
            'code'   => $code->value,
        ]);
        if ($this->responder instanceof ConcurrencyConflictResponder) {
            $event->setResponse($this->responder->respond($request, $failure));
            $event->allowCustomResponseCode();

            return;
        }
        $headers  = $this->policy->headersFor($code) + ['Cache-Control' => 'no-store'];
        $response = WorkingTimeConflictFormatter::wantsJson($request)
            ? new JsonResponse(['error' => WorkingTimeConflictFormatter::code($failure, WorkingTimeConflictFormatter::defaultCode($code)), 'code' => $code->value], $this->policy->status, $headers)
            : new Response($code->retrySafe() ? self::RETRY_TEXT : self::RELOAD_TEXT, $this->policy->status, $headers + ['Content-Type' => 'text/plain; charset=UTF-8']);
        $event->setResponse($response);
        $event->allowCustomResponseCode();
    }
}
