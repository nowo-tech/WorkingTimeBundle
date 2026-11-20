<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Http;

use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseContext;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseFormatterInterface;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
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
 * request gets `{"error": "correction_decision_stale", "code": "CONFLICT_001"}` / `settings_stale` for a stale version,
 * or the responder's `concurrency_conflict` (`concurrency_conflict_partial` after a commit) for a final lock conflict;
 * `code` is the REQ-RUNTIME-003 code (`CONFLICT_001` or `CONFLICT_002`). The responder adds the status, `Retry-After`
 * (CONFLICT_001 only), `X-Conflict-Code` and `Cache-Control: no-store`. HTML falls through to the responder's branded
 * page. Stateless.
 */
final readonly class WorkingTimeConflictFormatter implements ConflictResponseFormatterInterface
{
    /** The generic error code of a lock conflict (the responder's default). */
    public const DEFAULT_CODE = 'concurrency_conflict';

    /**
     * The JSON answer on the bundle's routes; null elsewhere and for HTML.
     */
    public function format(Request $request, ConflictResponseContext $context): ?Response
    {
        if (!self::isBundleRoute($request) || !self::wantsJson($request)) {
            return null;
        }

        return new JsonResponse(['error' => self::code($context->failure, $context->errorCode), 'code' => $context->code->value], $context->status);
    }

    /**
     * The stable error code of a failure: the bundle's own code of the first lock conflict it carries (the bounded
     * walk of transaction-doctrine >= 1.7: `getPrevious()` and Messenger's wrapped exceptions), else $default.
     */
    public static function code(Throwable $failure, string $default = self::DEFAULT_CODE): string
    {
        $conflict = TransientFailureClassifier::findLockConflict($failure);

        return match (true) {
            $conflict instanceof StaleCorrectionDecisionException => StaleCorrectionDecisionException::ERROR_CODE,
            $conflict instanceof StaleSettingsException           => StaleSettingsException::ERROR_CODE,
            $conflict instanceof ChainBusyException               => ChainBusyException::ERROR_CODE,
            $conflict instanceof StaleLeaveSettingsException      => StaleLeaveSettingsException::ERROR_CODE,
            $conflict instanceof WriteConflictException           => $conflict->errorCode,
            default                                               => $default,
        };
    }

    /**
     * The generic error code of a conflict with $code, as the responder names it: `concurrency_conflict`, or
     * `concurrency_conflict_partial` when part of the request was committed.
     */
    public static function defaultCode(ConflictCode $code): string
    {
        return $code->retrySafe() ? self::DEFAULT_CODE : self::DEFAULT_CODE . '_partial';
    }

    /**
     * Whether the request is one of the bundle's routes (host routes are never answered).
     */
    public static function isBundleRoute(Request $request): bool
    {
        return $request->attributes->get(UiRouteLoader::ROUTE_ATTRIBUTE) === true;
    }

    /**
     * Whether the request asks for JSON.
     */
    public static function wantsJson(Request $request): bool
    {
        return $request->isXmlHttpRequest() || $request->getPreferredFormat() === 'json';
    }
}
