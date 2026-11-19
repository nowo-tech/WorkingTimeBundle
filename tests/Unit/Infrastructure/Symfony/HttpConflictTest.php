<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Infrastructure\Symfony;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use LogicException;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseContext;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\Symfony\EventListener\ConcurrencyConflictResponder;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Nowo\WorkingTimeBundle\Application\Register\Exception\ChainBusyException;
use Nowo\WorkingTimeBundle\Application\Register\Exception\StaleCorrectionDecisionException;
use Nowo\WorkingTimeBundle\Application\Register\Exception\StaleSettingsException;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Http\ConflictFallbackListener;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Http\WorkingTimeConflictFormatter;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Routing\UiRouteLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

/**
 * 409 of the bundle's routes (contract section 11.3, D-20261117-15, REQ-RUNTIME-003 rule 6): transaction-doctrine's
 * formatter in JSON with the bundle's error codes and the conflict code, and the bundle's listener (every conflict
 * without NowoTransactionDoctrineBundle, the raw transient failures the responder leaves with it), which finds the
 * conflict through transaction-doctrine's bounded walk (getPrevious() and Messenger's wrapped exceptions).
 */
final class HttpConflictTest extends TestCase
{
    public function testTheFormatterAnswersJsonOnTheBundlesRoutesOnly(): void
    {
        $formatter = new WorkingTimeConflictFormatter();
        $json      = $this->request(true, true);
        $context   = new ConflictResponseContext(409, 1, 'concurrency_conflict', 'Try again', 3, new RuntimeException('x', 0, new StaleCorrectionDecisionException()));

        self::assertSame('{"error":"correction_decision_stale","code":"CONFLICT_001"}', (string) $formatter->format($json, $context)?->getContent());
        $partial = new ConflictResponseContext(409, null, 'concurrency_conflict_partial', 'Reload', 3, new RuntimeException(), true, ConflictCode::Committed);
        self::assertSame('{"error":"concurrency_conflict_partial","code":"CONFLICT_002"}', (string) $formatter->format($json, $partial)?->getContent());
        self::assertNull($formatter->format($this->request(true, false), $context), 'HTML: the responder\'s page');
        self::assertNull($formatter->format($this->request(false, true), $context), 'not a bundle route');
        self::assertSame('settings_stale', WorkingTimeConflictFormatter::code(new StaleSettingsException()));
        self::assertSame(ChainBusyException::ERROR_CODE, WorkingTimeConflictFormatter::code(new RuntimeException('x', 0, new ChainBusyException())));
        self::assertSame('concurrency_conflict', WorkingTimeConflictFormatter::code(new RuntimeException()));
        self::assertSame('settings_stale', WorkingTimeConflictFormatter::code($this->handlerFailed([new LogicException('first handler'), new StaleSettingsException()])), 'inside a Messenger wrapper');
        self::assertSame('concurrency_conflict', WorkingTimeConflictFormatter::defaultCode(ConflictCode::RetrySafe));
        self::assertSame('concurrency_conflict_partial', WorkingTimeConflictFormatter::defaultCode(ConflictCode::Committed));
    }

    public function testTheFallbackListenerAnswers409WithRetryAfter(): void
    {
        $listener = new ConflictFallbackListener();
        $event    = $this->event($this->request(true, true), new RuntimeException('wrapped', 0, new StaleSettingsException()));
        $listener->onKernelException($event);
        self::assertNotNull($event->getResponse());
        self::assertSame(409, $event->getResponse()->getStatusCode());
        self::assertSame('1', $event->getResponse()->headers->get('Retry-After'));
        self::assertSame('CONFLICT_001', $event->getResponse()->headers->get(ConflictResponsePolicy::CODE_HEADER));
        self::assertSame('{"error":"settings_stale","code":"CONFLICT_001"}', $event->getResponse()->getContent());

        $html = $this->event($this->request(true, false), new StaleCorrectionDecisionException());
        $listener->onKernelException($html);
        $response = $html->getResponse();
        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame(ConflictFallbackListener::RETRY_TEXT, $response->getContent());

        foreach ([$this->event($this->request(false, true), new StaleSettingsException()), $this->event($this->request(true, true), new RuntimeException())] as $ignored) {
            $listener->onKernelException($ignored);
            self::assertNull($ignored->getResponse());
        }
    }

    /**
     * A deadlock as the second wrapped exception of a synchronous HandlerFailedException (transaction-doctrine 1.7's
     * walk; a getPrevious() walk missed it): 409 + Retry-After + CONFLICT_001 on the bundle's routes.
     */
    public function testAWrappedDeadlockAnswers409(): void
    {
        $response = $this->answer(true, true, $this->handlerFailed([new LogicException('first handler'), $this->deadlock()]));
        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertSame('CONFLICT_001', $response->headers->get(ConflictResponsePolicy::CODE_HEADER));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('{"error":"concurrency_conflict","code":"CONFLICT_001"}', $response->getContent());

        $deep = $this->answer(true, false, new RuntimeException('outer', 0, $this->handlerFailed([new LogicException('first'), new RuntimeException('second', 0, $this->deadlock())])));
        self::assertNotNull($deep);
        self::assertSame(409, $deep->getStatusCode());
        self::assertSame(ConflictFallbackListener::RETRY_TEXT, $deep->getContent());

        self::assertNull($this->answer(false, true, $this->handlerFailed([new LogicException('first'), $this->deadlock()])), 'never on a host route');
    }

    /**
     * Part of the work may be committed: another handler already succeeded (HandledStamp), a unit that committed
     * before its conflict, or a commit earlier in the request (the watermark): 409 + CONFLICT_002, no Retry-After.
     */
    public function testACommittedConflictAnswersConflict002WithoutRetryAfter(): void
    {
        $handled = $this->handlerFailed([new LogicException('first'), $this->deadlock()], true);
        $unit    = new ConcurrencyConflict(1, TransientFailureKind::Deadlock, null, $this->deadlock(), true);
        foreach ([$handled, $unit] as $failure) {
            $response = $this->answer(true, true, $failure);
            self::assertNotNull($response);
            self::assertSame(409, $response->getStatusCode());
            self::assertFalse($response->headers->has('Retry-After'));
            self::assertSame('CONFLICT_002', $response->headers->get(ConflictResponsePolicy::CODE_HEADER));
            self::assertSame('{"error":"concurrency_conflict_partial","code":"CONFLICT_002"}', $response->getContent());
        }

        $request = $this->request(true, false);
        $ledger  = new CommitLedger();
        $request->attributes->set(CommitMark::REQUEST_ATTRIBUTE, new CommitMark($ledger));
        $ledger->record(); // an earlier unit of the same request committed
        $event = $this->event($request, $this->deadlock());
        (new ConflictFallbackListener())->onKernelException($event);
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame('CONFLICT_002', $response->headers->get(ConflictResponsePolicy::CODE_HEADER));
        self::assertFalse($response->headers->has('Retry-After'));
        self::assertSame(ConflictFallbackListener::RELOAD_TEXT, $response->getContent());
    }

    /**
     * The host's 503 setting is kept; the header still carries the code.
     */
    public function testTheStatusFollowsThePolicy(): void
    {
        $event = $this->event($this->request(true, true), $this->deadlock());
        (new ConflictFallbackListener(new ConflictResponsePolicy(503, 4)))->onKernelException($event);
        self::assertSame(503, $event->getResponse()?->getStatusCode());
        self::assertSame('4', $event->getResponse()->headers->get('Retry-After'));
        self::assertSame('CONFLICT_001', $event->getResponse()->headers->get(ConflictResponsePolicy::CODE_HEADER));
    }

    /**
     * With transaction-doctrine's responder the listener answers through its respond() (the bundle's JSON formatter,
     * the responder's page for HTML) and logs one constant warning.
     */
    public function testTheResponderAnswersWhenItExists(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('warning')->with('nowo_working_time answered a database conflict on its route.', self::callback(static fn (array $context): bool => $context['code'] === 'CONFLICT_001' && $context['method'] === 'POST'));
        $listener = new ConflictFallbackListener(new ConflictResponsePolicy(), new ConcurrencyConflictResponder(new ConflictResponsePolicy(), [], [new WorkingTimeConflictFormatter()]), $logger);
        $failure  = $this->handlerFailed([new LogicException('first'), $this->deadlock()]);

        $json = $this->event($this->request(true, true), $failure);
        $listener->onKernelException($json);
        self::assertSame(409, $json->getResponse()?->getStatusCode());
        self::assertSame('{"error":"concurrency_conflict","code":"CONFLICT_001"}', $json->getResponse()->getContent());
        self::assertSame('CONFLICT_001', $json->getResponse()->headers->get(ConflictResponsePolicy::CODE_HEADER));
        self::assertSame('1', $json->getResponse()->headers->get('Retry-After'));

        $html = $this->event($this->request(true, false), $failure);
        $listener->onKernelException($html);
        self::assertSame(409, $html->getResponse()?->getStatusCode());
        self::assertStringContainsString('CONFLICT_001', (string) $html->getResponse()->getContent());

        $host = $this->event($this->request(false, false), $failure);
        $listener->onKernelException($host);
        self::assertNull($host->getResponse(), 'never on a host route');
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function notConflicts(): iterable
    {
        $unique = new UniqueConstraintViolationException(self::driverError('Duplicate entry', '23000', 1062), null);
        $lost   = new ConnectionLost(self::driverError('MySQL server has gone away', 'HY000', 2006), null);

        yield 'a unique violation' => [$unique];
        yield 'a wrapped unique violation (second handler)' => [self::wrap([new LogicException('first'), $unique])];
        yield 'a lost connection' => [$lost];
        yield 'a wrapped lost connection' => [self::wrap([new LogicException('first'), $lost])];
        yield 'an ordinary failure' => [self::wrap([new LogicException('first'), new RuntimeException('second')])];
    }

    /**
     * Unique violations and lost connections are not conflicts: no 409, the host's error handling answers.
     */
    #[DataProvider('notConflicts')]
    public function testAWrappedUniqueViolationOrLostConnectionIsNotAConflict(Throwable $failure): void
    {
        self::assertNull($this->answer(true, true, $failure));
        self::assertNull($this->answer(true, false, $failure));
    }

    private function answer(bool $bundleRoute, bool $json, Throwable $failure): ?Response
    {
        $event = $this->event($this->request($bundleRoute, $json), $failure);
        (new ConflictFallbackListener())->onKernelException($event);

        return $event->getResponse();
    }

    private function deadlock(): DeadlockException
    {
        return new DeadlockException(self::driverError('Deadlock found when trying to get lock', '40001', 1213), null);
    }

    /**
     * @param list<Throwable> $exceptions
     */
    private function handlerFailed(array $exceptions, bool $handled = false): HandlerFailedException
    {
        return self::wrap($exceptions, $handled);
    }

    /**
     * @param list<Throwable> $exceptions
     */
    private static function wrap(array $exceptions, bool $handled = false): HandlerFailedException
    {
        $envelope = new Envelope(new stdClass());
        if ($handled) {
            $envelope = $envelope->with(new HandledStamp(null, 'App\\OtherHandler::__invoke'));
        }

        return new HandlerFailedException($envelope, $exceptions);
    }

    private static function driverError(string $message, string $sqlState, int $code): AbstractException
    {
        return new class($message, $sqlState, $code) extends AbstractException {
        };
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
