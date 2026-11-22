<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Infrastructure\Symfony;

use LogicException;
use Nowo\Brand\Contract\BrandProviderInterface;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnit;
use Nowo\WorkingTimeBundle\Application\Register\Port\ExportDispatcherInterface;
use Nowo\WorkingTimeBundle\Bridge\Dompdf\DompdfRegisterPdfRenderer;
use Nowo\WorkingTimeBundle\Bridge\TimeTrack\TimeTrackTaskTimerLink;
use Nowo\WorkingTimeBundle\Domain\Port\TaskTimerLinkInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeAuditSinkInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeNotifierInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeTenantResolverInterface;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\DependencyInjection\Compiler\WorkingTimePortsPass;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Http\ConflictFallbackListener;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Messenger\GenerateRegisterExportHandler;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port\MailerNotifier;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port\NullNotifier;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port\NullTaskTimerLink;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * The ports pass (contract sections 8 and 10): service ids replace the defaults; transaction-doctrine, Mailer,
 * Messenger, Dompdf and TimeTrack are used when present; the missing pieces fail the build with the fix.
 */
final class PortsPassTest extends TestCase
{
    public function testDefaultsWithoutOptionalInfrastructure(): void
    {
        $container = $this->container();
        (new WorkingTimePortsPass())->process($container);

        self::assertSame(NativeClock::class, $container->getDefinition('nowo_working_time.clock')->getClass());
        self::assertSame(NullNotifier::class, $container->getDefinition(WorkingTimeNotifierInterface::class)->getClass());
        self::assertSame(WriteUnit::class, $container->getDefinition('nowo_working_time.write_unit')->getClass());
        self::assertSame(ConflictResponsePolicy::class, $container->getDefinition('nowo_working_time.conflict_policy')->getClass());
        self::assertTrue($container->hasDefinition(ConflictFallbackListener::class));
        self::assertSame(DompdfRegisterPdfRenderer::class, $container->getDefinition('nowo_working_time.pdf_renderer')->getClass());
        self::assertSame(NullTaskTimerLink::class, $container->getDefinition(TaskTimerLinkInterface::class)->getClass());
        self::assertSame('nowo_working_time.export_dispatcher.sync', (string) $container->getAlias(ExportDispatcherInterface::class));
    }

    public function testHostServicesAndInstalledPackagesAreUsed(): void
    {
        $container = $this->container([
            'tenant_resolver'              => 'app.tenants',
            'brand_provider'               => 'app.brand',
            'clock'                        => 'app.clock',
            'notifier'                     => 'app.notifier',
            'audit_sink'                   => 'app.audit',
            'register.export.pdf.renderer' => 'app.pdf',
            'register.export.async'        => 'auto',
        ]);
        foreach (['nowo_transaction_doctrine.write_unit', 'nowo_transaction_doctrine.conflict_response_policy', 'nowo_transaction_doctrine.conflict_responder', 'messenger.default_bus', TimerService::class, 'security.token_storage'] as $id) {
            $container->setDefinition($id, new Definition('stdClass'));
        }
        (new WorkingTimePortsPass())->process($container);

        self::assertSame('app.tenants', (string) $container->getAlias(WorkingTimeTenantResolverInterface::class));
        self::assertSame('app.brand', (string) $container->getAlias('nowo_working_time.brand_provider'));
        self::assertSame('app.clock', (string) $container->getAlias('nowo_working_time.clock'));
        self::assertSame('app.notifier', (string) $container->getAlias(WorkingTimeNotifierInterface::class));
        self::assertSame('app.audit', (string) $container->getAlias(WorkingTimeAuditSinkInterface::class));
        self::assertSame('app.pdf', (string) $container->getAlias('nowo_working_time.pdf_renderer'));
        self::assertSame('nowo_transaction_doctrine.write_unit', (string) $container->getAlias('nowo_working_time.write_unit'));
        self::assertSame('nowo_transaction_doctrine.conflict_response_policy', (string) $container->getAlias('nowo_working_time.conflict_policy'));
        self::assertSame([['event' => 'kernel.exception', 'method' => 'onKernelException', 'priority' => 63]], $container->getDefinition(ConflictFallbackListener::class)->getTag('kernel.event_listener'), 'after the responder: what it leaves, on the bundle\'s routes');
        self::assertSame('nowo_working_time.export_dispatcher.messenger', (string) $container->getAlias(ExportDispatcherInterface::class));
        self::assertTrue($container->getDefinition(GenerateRegisterExportHandler::class)->isAutoconfigured(), '#[AsMessageHandler] is picked up by autoconfiguration');
        self::assertFalse($container->getDefinition(GenerateRegisterExportHandler::class)->hasTag('messenger.message_handler'), 'no hand-written tag next to the attribute (a double registration would handle twice)');
        self::assertCount(1, (new \ReflectionClass(GenerateRegisterExportHandler::class))->getAttributes(\Symfony\Component\Messenger\Attribute\AsMessageHandler::class));
        self::assertSame(TimeTrackTaskTimerLink::class, $container->getDefinition(TaskTimerLinkInterface::class)->getClass());
    }

    public function testTheContainerBrandClockAndMailerAreUsedWhenNotConfigured(): void
    {
        $container = $this->container(['notifier' => 'auto']);
        foreach ([BrandProviderInterface::class, 'clock', 'mailer.mailer'] as $id) {
            $container->setDefinition($id, new Definition('stdClass'));
        }
        (new WorkingTimePortsPass())->process($container);

        self::assertSame(BrandProviderInterface::class, (string) $container->getAlias('nowo_working_time.brand_provider'));
        self::assertSame('clock', (string) $container->getAlias('nowo_working_time.clock'));
        self::assertSame(MailerNotifier::class, $container->getDefinition(WorkingTimeNotifierInterface::class)->getClass());
    }

    public function testTheBuildFailsWithTheFix(): void
    {
        foreach ([
            [['doctrine.mapping' => 'attribute'], 'mapping "attribute"'],
            [['register.export.async' => 'messenger'], 'Messenger is not enabled'],
        ] as [$parameters, $message]) {
            try {
                (new WorkingTimePortsPass())->process($this->container($parameters));
                self::fail($message);
            } catch (LogicException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testPdfWithoutARendererFailsTheBuild(): void
    {
        $container = $this->container();
        $container->removeDefinition('twig');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('composer require dompdf/dompdf');
        (new WorkingTimePortsPass())->process($container);
    }

    public function testCsvOnlyNeedsNoRenderer(): void
    {
        $container = $this->container(['register.export.formats' => ['csv']]);
        $container->removeDefinition('twig');
        (new WorkingTimePortsPass())->process($container);
        self::assertFalse($container->has('nowo_working_time.pdf_renderer'));
    }

    /**
     * @param array<string, array<mixed>|bool|int|string|null> $parameters
     */
    private function container(array $parameters = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        foreach ([
            'doctrine.mapping'             => 'xml',
            'tenant_resolver'              => null,
            'permission_checker'           => null,
            'worker_directory'             => null,
            'brand_provider'               => null,
            'clock'                        => null,
            'notifier'                     => 'auto',
            'notifier_from'                => 'no-reply@example.test',
            'audit_sink'                   => 'auto',
            'register.export.pdf.renderer' => 'dompdf',
            'register.export.formats'      => ['pdf', 'csv'],
            'register.export.async'        => 'sync',
            'register.task_timer_link'     => 'auto',
        ] as $name => $value) {
            $container->setParameter('nowo_working_time.' . $name, $parameters[$name] ?? $value);
        }
        foreach ($parameters as $name => $value) {
            $container->setParameter('nowo_working_time.' . $name, $value);
        }
        $container->setDefinition('twig', new Definition('stdClass'));
        $container->setDefinition(GenerateRegisterExportHandler::class, new Definition(GenerateRegisterExportHandler::class));
        $container->setAlias(ExportDispatcherInterface::class, 'nowo_working_time.export_dispatcher.sync');

        return $container;
    }
}
