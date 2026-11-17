<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Http;

use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\WriteUnit\LockConflictExceptionInterface;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Routing\UiRouteLoader;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * Registered only when NowoTransactionDoctrineBundle's conflict responder is absent: a lock conflict on the bundle's
 * routes still answers **409 + `Retry-After`** (the policy's values; JSON `{"error": …}` or a short text page).
 */
final readonly class ConflictFallbackListener
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private ConflictResponsePolicy $policy = new ConflictResponsePolicy())
    {
    }

    /**
     * `kernel.exception`.
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->attributes->get(UiRouteLoader::ROUTE_ATTRIBUTE) !== true) {
            return;
        }
        $conflict = null;
        for ($e = $event->getThrowable(); $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof LockConflictExceptionInterface) {
                $conflict = $e;
                break;
            }
        }
        if ($conflict === null) {
            return;
        }
        $headers  = $this->policy->headers() + ['Cache-Control' => 'no-store'];
        $response = WorkingTimeConflictFormatter::wantsJson($request)
            ? new JsonResponse(['error' => WorkingTimeConflictFormatter::code($conflict)], $this->policy->status, $headers)
            : new Response('Someone changed this at the same moment. Try again.', $this->policy->status, $headers + ['Content-Type' => 'text/plain; charset=UTF-8']);
        $event->setResponse($response);
        $event->allowCustomResponseCode();
    }
}
