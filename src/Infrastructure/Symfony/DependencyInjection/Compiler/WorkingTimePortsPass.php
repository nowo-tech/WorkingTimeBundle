<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\DependencyInjection\Compiler;

use Dompdf\Dompdf;
use LogicException;
use Nowo\Brand\Contract\BrandProviderInterface;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnit;
use Nowo\WorkingTimeBundle\Application\Register\Port\ExportDispatcherInterface;
use Nowo\WorkingTimeBundle\Application\Register\Port\ExportGeneratorInterface;
use Nowo\WorkingTimeBundle\Bridge\Dompdf\DompdfRegisterPdfRenderer;
use Nowo\WorkingTimeBundle\Bridge\TimeTrack\TimeTrackTaskTimerLink;
use Nowo\WorkingTimeBundle\Domain\Port\TaskTimerLinkInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkerDirectoryInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeAuditSinkInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeNotifierInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimePermissionCheckerInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeTenantResolverInterface;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\DependencyInjection\ServiceDefinitions;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Http\ConflictFallbackListener;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Messenger\GenerateRegisterExportHandler;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Messenger\GenerateRegisterExportMessage;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Messenger\MessengerExportDispatcher;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port\MailerNotifier;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port\NullNotifier;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port\NullTaskTimerLink;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function class_exists;
use function in_array;
use function is_array;
use function is_string;

/**
 * Points the port aliases at the host's services, the defaults or the installed bridges, and wires the optional
 * infrastructure (contract sections 8 and 10):
 *
 * - `tenant_resolver`, `permission_checker`, `worker_directory`, `brand_provider`, `clock`, `notifier`, `audit_sink`:
 *   a service id replaces the default; `brand_provider: null` uses the container's BrandProviderInterface when there is
 *   one; `notifier: auto` is Symfony Mailer when installed, else nothing;
 * - transaction-doctrine's write unit and conflict policy when NowoTransactionDoctrineBundle is registered, else a
 *   write unit of the bundle's own and the 409 fallback listener;
 * - the PDF renderer (Dompdf, closed) — a `pdf` export format without a renderer fails the build with the fix;
 * - Messenger for exports (`register.export.async`), the TimeTrack task-timer link (`register.task_timer_link`).
 *
 * @internal
 */
final class WorkingTimePortsPass implements CompilerPassInterface
{
    /**
     * Wires the ports.
     */
    public function process(ContainerBuilder $container): void
    {
        $p = ServiceDefinitions::P;
        if ($container->getParameter($p . 'doctrine.mapping') === 'attribute') {
            throw new LogicException('nowo_working_time.doctrine.mapping "attribute" is not available in 0.x: the bundle ships its XML mapping only (use "xml").');
        }
        foreach ([
            'tenant_resolver'    => WorkingTimeTenantResolverInterface::class,
            'permission_checker' => WorkingTimePermissionCheckerInterface::class,
            'worker_directory'   => WorkerDirectoryInterface::class,
        ] as $port => $interface) {
            $id = $container->getParameter($p . $port);
            if (is_string($id) && $id !== '') {
                $container->setAlias($interface, $id);
            }
        }
        $this->brand($container);
        $this->clock($container);
        $this->notifier($container);
        $audit = $container->getParameter($p . 'audit_sink');
        if (is_string($audit) && $audit !== 'auto') {
            $container->setAlias(WorkingTimeAuditSinkInterface::class, $audit);
        }
        $this->transactions($container);
        $this->pdf($container);
        $this->exports($container);
        $this->timerLink($container);
    }

    private function brand(ContainerBuilder $container): void
    {
        $id = $container->getParameter(ServiceDefinitions::P . 'brand_provider');
        if (is_string($id) && $id !== '') {
            $container->setAlias(ServiceDefinitions::P . 'brand_provider', $id);
        } elseif ($container->has(BrandProviderInterface::class)) {
            $container->setAlias(ServiceDefinitions::P . 'brand_provider', BrandProviderInterface::class);
        }
    }

    private function clock(ContainerBuilder $container): void
    {
        $id = $container->getParameter(ServiceDefinitions::P . 'clock');
        if (is_string($id) && $id !== '') {
            $container->setAlias(ServiceDefinitions::P . 'clock', $id);

            return;
        }
        if ($container->has('clock')) {
            $container->setAlias(ServiceDefinitions::P . 'clock', 'clock');

            return;
        }
        $container->setDefinition(ServiceDefinitions::P . 'clock', new Definition(NativeClock::class));
    }

    private function notifier(ContainerBuilder $container): void
    {
        $setting = $container->getParameter(ServiceDefinitions::P . 'notifier');
        if (is_string($setting) && $setting !== 'auto' && $setting !== 'null') {
            $container->setAlias(WorkingTimeNotifierInterface::class, $setting);

            return;
        }
        if ($setting === 'auto' && $container->has('mailer.mailer') && $container->has('twig')) {
            $container->setDefinition(WorkingTimeNotifierInterface::class, (new Definition(MailerNotifier::class))->setArguments([
                new Reference('mailer.mailer'),
                new Reference(WorkerDirectoryInterface::class),
                new Reference(ServiceDefinitions::P . 'brand_provider'),
                new Reference('translator'),
                new Reference('twig'),
                $container->getParameter(ServiceDefinitions::P . 'notifier_from'),
            ]));

            return;
        }
        $container->setDefinition(WorkingTimeNotifierInterface::class, new Definition(NullNotifier::class));
    }

    private function transactions(ContainerBuilder $container): void
    {
        $p = ServiceDefinitions::P;
        if ($container->has('nowo_transaction_doctrine.write_unit')) {
            $container->setAlias($p . 'write_unit', 'nowo_transaction_doctrine.write_unit');
        } else {
            $container->setDefinition($p . 'write_unit', (new Definition(WriteUnit::class))->setArguments([new Reference('doctrine')]));
        }
        if ($container->has('nowo_transaction_doctrine.conflict_response_policy')) {
            $container->setAlias($p . 'conflict_policy', 'nowo_transaction_doctrine.conflict_response_policy');
        } else {
            $container->setDefinition($p . 'conflict_policy', new Definition(ConflictResponsePolicy::class));
        }
        if (!$container->has('nowo_transaction_doctrine.conflict_responder')) {
            $container->setDefinition(ConflictFallbackListener::class, (new Definition(ConflictFallbackListener::class))
                ->setArguments([new Reference($p . 'conflict_policy')])
                ->addTag('kernel.event_listener', ['event' => 'kernel.exception', 'method' => 'onKernelException', 'priority' => 64]));
        }
    }

    private function pdf(ContainerBuilder $container): void
    {
        $p        = ServiceDefinitions::P;
        $renderer = $container->getParameter($p . 'register.export.pdf.renderer');
        $formats  = $container->getParameter($p . 'register.export.formats');
        if (is_string($renderer) && $renderer !== 'dompdf') {
            $container->setAlias($p . 'pdf_renderer', $renderer);

            return;
        }
        if (class_exists(Dompdf::class) && $container->has('twig')) {
            $container->setDefinition($p . 'pdf_renderer', (new Definition(DompdfRegisterPdfRenderer::class))->setArguments([new Reference('twig')]));

            return;
        }
        if (is_array($formats) && in_array('pdf', $formats, true)) {
            throw new LogicException('nowo_working_time.register.export.formats contains "pdf" but no PDF renderer is available: install dompdf/dompdf (composer require dompdf/dompdf), set register.export.pdf.renderer to the service id of your RegisterPdfRendererInterface, or remove "pdf" from the formats.');
        }
    }

    private function exports(ContainerBuilder $container): void
    {
        $p     = ServiceDefinitions::P;
        $async = $container->getParameter($p . 'register.export.async');
        $bus   = $container->has('messenger.default_bus');
        if ($async === 'messenger' && !$bus) {
            throw new LogicException('nowo_working_time.register.export.async is "messenger" but Symfony Messenger is not enabled.');
        }
        if ($async === 'sync' || !$bus) {
            return;
        }
        $container->setDefinition($p . 'export_dispatcher.messenger', (new Definition(MessengerExportDispatcher::class))->setArguments([new Reference('messenger.default_bus')]));
        $container->setAlias(ExportDispatcherInterface::class, $p . 'export_dispatcher.messenger');
        $container->getDefinition(GenerateRegisterExportHandler::class)->addTag('messenger.message_handler', ['handles' => GenerateRegisterExportMessage::class]);
        $container->getDefinition(GenerateRegisterExportHandler::class)->setArgument(0, new Reference(ExportGeneratorInterface::class));
    }

    private function timerLink(ContainerBuilder $container): void
    {
        $p       = ServiceDefinitions::P;
        $setting = $container->getParameter($p . 'register.task_timer_link');
        if (is_string($setting) && $setting !== 'auto' && $setting !== 'off') {
            $container->setAlias(TaskTimerLinkInterface::class, $setting);

            return;
        }
        if ($setting === 'auto' && class_exists(TimerService::class) && $container->has(TimerService::class) && $container->has('security.token_storage')) {
            $container->setDefinition(TaskTimerLinkInterface::class, (new Definition(TimeTrackTaskTimerLink::class))->setArguments([
                new Reference(TimerService::class),
                new Reference('security.token_storage'),
                new Reference(WorkerDirectoryInterface::class),
            ]));

            return;
        }
        $container->setDefinition(TaskTimerLinkInterface::class, new Definition(NullTaskTimerLink::class));
    }
}
