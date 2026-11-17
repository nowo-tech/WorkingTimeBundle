<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Infrastructure\Symfony;

use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseContext;
use Nowo\WorkingTimeBundle\Application\Register\Exception\ChainBusyException;
use Nowo\WorkingTimeBundle\Application\Register\Exception\StaleCorrectionDecisionException;
use Nowo\WorkingTimeBundle\Application\Register\Exception\StaleSettingsException;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Http\ConflictFallbackListener;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Http\WorkingTimeConflictFormatter;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Routing\UiRouteLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

/**
 * 409 + Retry-After of the bundle's routes (contract section 11.3, D-20261117-15): transaction-doctrine's formatter in
 * JSON with the bundle's error codes, and the fallback listener when NowoTransactionDoctrineBundle is not registered.
 */
final class HttpConflictTest extends TestCase
{
    public function testTheFormatterAnswersJsonOnTheBundlesRoutesOnly(): void
    {
        $formatter = new WorkingTimeConflictFormatter();
        $json      = $this->request(true, true);
        $context   = new ConflictResponseContext(409, 1, 'concurrency_conflict', 'Try again', 3, new RuntimeException('x', 0, new StaleCorrectionDecisionException()));

        self::assertSame('{"error":"correction_decision_stale"}', (string) $formatter->format($json, $context)?->getContent());
        self::assertNull($formatter->format($this->request(true, false), $context), 'HTML: the responder\'s page');
        self::assertNull($formatter->format($this->request(false, true), $context), 'not a bundle route');
        self::assertSame('settings_stale', WorkingTimeConflictFormatter::code(new StaleSettingsException()));
        self::assertSame(ChainBusyException::ERROR_CODE, WorkingTimeConflictFormatter::code(new RuntimeException('x', 0, new ChainBusyException())));
        self::assertSame('concurrency_conflict', WorkingTimeConflictFormatter::code(new RuntimeException()));
    }

    public function testTheFallbackListenerAnswers409WithRetryAfter(): void
    {
        $listener = new ConflictFallbackListener();
        $event    = $this->event($this->request(true, true), new RuntimeException('wrapped', 0, new StaleSettingsException()));
        $listener->onKernelException($event);
        self::assertNotNull($event->getResponse());
        self::assertSame(409, $event->getResponse()->getStatusCode());
        self::assertSame('1', $event->getResponse()->headers->get('Retry-After'));
        self::assertSame('{"error":"settings_stale"}', $event->getResponse()->getContent());

        $html = $this->event($this->request(true, false), new StaleCorrectionDecisionException());
        $listener->onKernelException($html);
        $response = $html->getResponse();
        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('Try again', (string) $response->getContent());

        foreach ([$this->event($this->request(false, true), new StaleSettingsException()), $this->event($this->request(true, true), new RuntimeException())] as $ignored) {
            $listener->onKernelException($ignored);
            self::assertNull($ignored->getResponse());
        }
    }

    private function request(bool $bundleRoute, bool $json): Request
    {
        $request = Request::create('/working-time/settings', 'POST');
        if ($bundleRoute) {
            $request->attributes->set(UiRouteLoader::ROUTE_ATTRIBUTE, true);
        }
        if ($json) {
            $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        }

        return $request;
    }

    private function event(Request $request, Throwable $failure): ExceptionEvent
    {
        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
    }
}
