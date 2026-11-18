<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Http;

use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseContext;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseFormatterInterface;
use Nowo\WorkingTimeBundle\Application\Leave\StaleLeaveSettingsException;
use Nowo\WorkingTimeBundle\Application\Register\Exception\ChainBusyException;
use Nowo\WorkingTimeBundle\Application\Register\Exception\StaleCorrectionDecisionException;
use Nowo\WorkingTimeBundle\Application\Register\Exception\StaleSettingsException;
use Nowo\WorkingTimeBundle\Application\Shared\WriteConflictException;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Routing\UiRouteLoader;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * transaction-doctrine conflict formatter of the bundle's routes (contract sections 11.3 and 15, D-20261117-15): a JSON
 * request gets `{"error": "correction_decision_stale"}` / `settings_stale` for a stale version, or the responder's
 * `concurrency_conflict` for a final lock conflict; the responder adds the 409 status, `Retry-After` and
 * `Cache-Control: no-store`. HTML falls through to the responder's branded page. Stateless.
 */
final readonly class WorkingTimeConflictFormatter implements ConflictResponseFormatterInterface
{
    /**
     * The JSON answer on the bundle's routes; null elsewhere and for HTML.
     */
    public function format(Request $request, ConflictResponseContext $context): ?Response
    {
        if ($request->attributes->get(UiRouteLoader::ROUTE_ATTRIBUTE) !== true || !self::wantsJson($request)) {
            return null;
        }

        return new JsonResponse(['error' => self::code($context->failure, $context->errorCode)], $context->status);
    }

    /**
     * The stable error code of a failure.
     */
    public static function code(Throwable $failure, string $default = 'concurrency_conflict'): string
    {
        for ($e = $failure; $e instanceof Throwable; $e = $e->getPrevious()) {
            if ($e instanceof StaleCorrectionDecisionException) {
                return StaleCorrectionDecisionException::ERROR_CODE;
            }
            if ($e instanceof StaleSettingsException) {
                return StaleSettingsException::ERROR_CODE;
            }
            if ($e instanceof ChainBusyException) {
                return ChainBusyException::ERROR_CODE;
            }
            if ($e instanceof StaleLeaveSettingsException) {
                return StaleLeaveSettingsException::ERROR_CODE;
            }
            if ($e instanceof WriteConflictException) {
                return $e->errorCode;
            }
        }

        return $default;
    }

    /**
     * Whether the request asks for JSON.
     */
    public static function wantsJson(Request $request): bool
    {
        return $request->isXmlHttpRequest() || $request->getPreferredFormat() === 'json';
    }
}
