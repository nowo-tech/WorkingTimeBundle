<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\DependencyInjection;

use Nowo\TimeTrackBundle\Workday\WorkdaySourceInterface;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseFormatterInterface;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;
use Nowo\WorkingTimeBundle\Application\Holiday\HolidayAccess;
use Nowo\WorkingTimeBundle\Application\Holiday\HolidayCalendarReader;
use Nowo\WorkingTimeBundle\Application\Holiday\HolidayCalendarService;
use Nowo\WorkingTimeBundle\Application\Holiday\HolidayProviderRegistry;
use Nowo\WorkingTimeBundle\Application\Holiday\Port\HolidayStoreInterface;
use Nowo\WorkingTimeBundle\Application\Leave\DayCounter;
use Nowo\WorkingTimeBundle\Application\Leave\LeaveAccess;
use Nowo\WorkingTimeBundle\Application\Leave\LeaveAdministration;
use Nowo\WorkingTimeBundle\Application\Leave\LeaveBalanceReader;
use Nowo\WorkingTimeBundle\Application\Leave\LeavePersonalData;
use Nowo\WorkingTimeBundle\Application\Leave\LeaveTypeSeeder;
use Nowo\WorkingTimeBundle\Application\Leave\NoLeaveUsage;
use Nowo\WorkingTimeBundle\Application\Leave\Port\LeaveSeedNamesInterface;
use Nowo\WorkingTimeBundle\Application\Leave\Port\LeaveStoreInterface;
use Nowo\WorkingTimeBundle\Application\Leave\Port\LeaveUsageInterface;
use Nowo\WorkingTimeBundle\Application\Register\ChainVerifier;
use Nowo\WorkingTimeBundle\Application\Register\CorrectionService;
use Nowo\WorkingTimeBundle\Application\Register\HolidayDayAnnotations;
use Nowo\WorkingTimeBundle\Application\Register\Notice\NoticeDispatcher;
use Nowo\WorkingTimeBundle\Application\Register\Notice\NoticeOutbox;
use Nowo\WorkingTimeBundle\Application\Register\Port\ExportDispatcherInterface;
use Nowo\WorkingTimeBundle\Application\Register\Port\ExportGeneratorInterface;
use Nowo\WorkingTimeBundle\Application\Register\Port\RegisterPurgeStoreInterface;
use Nowo\WorkingTimeBundle\Application\Register\Port\RegisterStoreInterface;
use Nowo\WorkingTimeBundle\Application\Register\Port\RegisterTriggersInterface;
use Nowo\WorkingTimeBundle\Application\Register\Port\TriggerInspectorInterface;
use Nowo\WorkingTimeBundle\Application\Register\RegisterAccess;
use Nowo\WorkingTimeBundle\Application\Register\RegisterAnnotations;
use Nowo\WorkingTimeBundle\Application\Register\RegisterExportService;
use Nowo\WorkingTimeBundle\Application\Register\RegisterPersonalData;
use Nowo\WorkingTimeBundle\Application\Register\RegisterPurger;
use Nowo\WorkingTimeBundle\Application\Register\RegisterReader;
use Nowo\WorkingTimeBundle\Application\Register\RegisterSettingsEditor;
use Nowo\WorkingTimeBundle\Application\Register\RevisionChain;
use Nowo\WorkingTimeBundle\Application\Register\ScheduleResolver;
use Nowo\WorkingTimeBundle\Application\Register\ScheduleService;
use Nowo\WorkingTimeBundle\Application\Register\TimeClock;
use Nowo\WorkingTimeBundle\Application\Register\WorkerPreferences;
use Nowo\WorkingTimeBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\WorkingTimeBundle\Bridge\TimeTrack\TimeTrackWorkdaySource;
use Nowo\WorkingTimeBundle\Bridge\UserKit\IdentifierWorkerOfUser;
use Nowo\WorkingTimeBundle\Bridge\UserKit\UserKitLeaveEraser;
use Nowo\WorkingTimeBundle\Bridge\UserKit\UserKitLeaveExportProvider;
use Nowo\WorkingTimeBundle\Bridge\UserKit\UserKitRegisterEraser;
use Nowo\WorkingTimeBundle\Bridge\UserKit\UserKitRegisterExportProvider;
use Nowo\WorkingTimeBundle\Bridge\UserKit\WorkerOfUserInterface;
use Nowo\WorkingTimeBundle\Domain\Holiday\Service\HolidayCalendarReaderInterface;
use Nowo\WorkingTimeBundle\Domain\Holiday\Service\HolidayCalendarServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Holiday\Service\MunicipalityDirectoryInterface;
use Nowo\WorkingTimeBundle\Domain\Leave\Service\LeaveAdministrationInterface;
use Nowo\WorkingTimeBundle\Domain\Leave\Service\LeaveBalanceReaderInterface;
use Nowo\WorkingTimeBundle\Domain\Port\ExportFileStoreInterface;
use Nowo\WorkingTimeBundle\Domain\Port\IdGeneratorInterface;
use Nowo\WorkingTimeBundle\Domain\Port\ScheduleFallbackProviderInterface;
use Nowo\WorkingTimeBundle\Domain\Port\TaskTimerLinkInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkerDirectoryInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeAuditSinkInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeNotifierInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimePermissionCheckerInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeTenantResolverInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Read\RegisterRules;
use Nowo\WorkingTimeBundle\Domain\Register\Service\ChainVerifierInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Service\CorrectionServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Service\RegisterClockInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Service\RegisterExportServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Service\RegisterReaderInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Service\RegisterSettingsEditorInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Service\ScheduleServiceInterface;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Dbal\DbalRegisterPurgeStore;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Dbal\PurgeFlagResetMiddleware;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\DoctrineHolidayStore;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\DoctrineLeaveStore;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\DoctrineRegisterStore;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\DoctrineTransactionRunner;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\RegisterMetadataListener;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Trigger\DoctrineRegisterTriggers;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Trigger\DoctrineTriggerInspector;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\Trigger\RegisterTriggerInstaller;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Command\LeaveDailyCommand;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Command\NoticesCommand;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Command\PurgeRegisterCommand;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Command\TriggersCommand;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Command\VerifyRegisterCommand;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Controller\ExportController;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Controller\HolidayController;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Controller\LeaveController;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Controller\RegisterController;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Controller\ScheduleController;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Controller\SettingsController;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Controller\TeamController;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Controller\UiSupport;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Export\RegisterExportGenerator;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Export\RegisterReportBuilder;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Export\SyncExportDispatcher;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\ActionType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\AllowanceLineType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\AllowancePolicyType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\AnswerType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\ClockType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\CorrectionType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\DecisionType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\ExportType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\HolidayCalendarType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\HolidayDayType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\HolidayImportConfirmType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\HolidayImportType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\HolidayProviderType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\LeaveSettingsType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\LeaveTypeType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\PreferencesType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\ScheduleType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\SettingsType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\WithdrawType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form\WorkCentreType;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Holiday\FestivosIoMunicipalityDirectory;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Holiday\FestivosIoProvider;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Holiday\GuardedJsonClient;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Holiday\OpenHolidaysProvider;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Http\EntityManagerHygieneListener;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Http\WorkingTimeConflictFormatter;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Leave\TranslatedLeaveSeedNames;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Messenger\GenerateRegisterExportHandler;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port\ConfiguredScheduleFallback;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port\CurrentUserWorkerDirectory;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port\DatabaseExportFileStore;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port\NeutralBrandProvider;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port\PsrLogAuditSink;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port\RoleMapPermissionChecker;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port\SingleTenantResolver;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port\SymfonyUuidGenerator;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Routing\UiRouteLoader;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Twig\WorkingTimeTwigExtension;
use Nowo\WorkingTimeBundle\NowoWorkingTimeBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function interface_exists;
use function is_array;
use function is_string;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * The bundle's services (explicit arguments, no autowiring of bundle classes). Port defaults are registered here
 * under the `nowo_working_time.default.*` ids; {@see Compiler\WorkingTimePortsPass} points each port alias at the
 * host's service, the default or an installed bridge, and wires the optional infrastructure (transaction-doctrine's
 * write unit and conflict policy, Messenger, Mailer, Dompdf, TimeTrack). Every service is stateless (worker mode).
 *
 * @internal
 */
final class ServiceDefinitions
{
    /** Prefix of the bundle's service ids. */
    public const P = NowoWorkingTimeBundle::ALIAS . '.';

    /** Tag of the holiday providers (built-in and the host's). */
    public const HOLIDAY_PROVIDER_TAG = 'nowo_working_time.holiday_provider';

    /** Tag of the register day annotation providers. */
    public const DAY_ANNOTATION_TAG = 'nowo_working_time.day_annotation_provider';

    /**
     * Registers the services.
     *
     * @param array<string, mixed> $config the validated configuration
     */
    public static function register(ContainerConfigurator $container, array $config): void
    {
        $s                             = $container->services();
        $p                             = self::P;
        $logger                        = service('logger')->nullOnInvalid();
        $events                        = service('event_dispatcher')->nullOnInvalid();
        $clock                         = service($p . 'clock');
        [$holidays, $festivos, $leave] = self::components($config);

        // Port defaults.
        $s->set($p . 'default.tenant_resolver', SingleTenantResolver::class);
        $s->set($p . 'default.permission_checker', RoleMapPermissionChecker::class)
            ->args([param($p . 'permissions.roles.map'), service('security.authorization_checker')->nullOnInvalid(), service('security.token_storage')->nullOnInvalid()]);
        $s->set($p . 'default.worker_directory', CurrentUserWorkerDirectory::class)->args([service('security.token_storage')->nullOnInvalid()]);
        $s->set($p . 'default.brand_provider', NeutralBrandProvider::class);
        $s->set($p . 'default.audit_sink', PsrLogAuditSink::class)->args([$logger]);
        $s->set($p . 'default.schedule_fallback', ConfiguredScheduleFallback::class)
            ->args([param($p . 'register.schedule.default'), param($p . 'register.schedule.default_timezone')]);
        $s->set($p . 'default.export_file_store', DatabaseExportFileStore::class)->args([service(DoctrineTransactionRunner::class)]);
        $s->set($p . 'default.id_generator', SymfonyUuidGenerator::class);
        $s->alias(IdGeneratorInterface::class, $p . 'default.id_generator');
        foreach ([
            WorkingTimeTenantResolverInterface::class    => 'tenant_resolver',
            WorkingTimePermissionCheckerInterface::class => 'permission_checker',
            WorkerDirectoryInterface::class              => 'worker_directory',
            WorkingTimeAuditSinkInterface::class         => 'audit_sink',
            ScheduleFallbackProviderInterface::class     => 'schedule_fallback',
            ExportFileStoreInterface::class              => 'export_file_store',
        ] as $interface => $port) {
            $s->alias($interface, $p . 'default.' . $port);
        }
        $s->alias($p . 'brand_provider', $p . 'default.brand_provider');

        // Persistence.
        $s->set(DoctrineTransactionRunner::class)
            ->args([service('doctrine'), service($p . 'write_unit'), param($p . 'doctrine.entity_manager')])
            ->public();
        $s->alias(TransactionRunnerInterface::class, DoctrineTransactionRunner::class);
        $s->set(DoctrineRegisterStore::class)->args([service(DoctrineTransactionRunner::class)]);
        $s->alias(RegisterStoreInterface::class, DoctrineRegisterStore::class);
        $s->set(RegisterMetadataListener::class)
            ->args([param($p . 'doctrine.table_prefix')])
            ->tag('doctrine.event_listener', ['event' => 'loadClassMetadata']);
        $s->set(RegisterTriggerInstaller::class)->args([param($p . 'doctrine.table_prefix'), param($p . 'integrity.purge_db_user')])->public();
        $s->set(DoctrineTriggerInspector::class)->args([service(DoctrineTransactionRunner::class), service(RegisterTriggerInstaller::class)]);
        $s->alias(TriggerInspectorInterface::class, DoctrineTriggerInspector::class);
        $integrity       = is_array($config['integrity'] ?? null) ? $config['integrity'] : [];
        $purgeConnection = $integrity['purge_connection'] ?? null;
        $s->set(DbalRegisterPurgeStore::class)->args([
            service(DoctrineTransactionRunner::class),
            service(IdGeneratorInterface::class),
            param($p . 'doctrine.table_prefix'),
            is_string($purgeConnection) ? service('doctrine.dbal.' . $purgeConnection . '_connection') : null,
        ]);
        $s->alias(RegisterPurgeStoreInterface::class, DbalRegisterPurgeStore::class);
        $s->set(PurgeFlagResetMiddleware::class)->tag('doctrine.middleware');
        $s->set(EntityManagerHygieneListener::class)
            ->args([service(DoctrineTransactionRunner::class)])
            ->tag('kernel.event_listener', ['event' => 'kernel.terminate', 'method' => 'clear', 'priority' => -2048])
            ->tag('kernel.event_listener', ['event' => 'console.terminate', 'method' => 'clear', 'priority' => -2048]);

        // Application.
        $s->set(RegisterRules::class)->args([
            param($p . 'register.late_client_seconds'),
            param($p . 'register.missing_clock_out_grace_seconds'),
            param($p . 'register.long_day_seconds'),
        ]);
        $s->set(RegisterAccess::class)->args([
            service(WorkingTimeTenantResolverInterface::class),
            service(WorkingTimePermissionCheckerInterface::class),
            service(WorkerDirectoryInterface::class),
            service(RegisterStoreInterface::class),
            $clock,
            param($p . 'register.retention_years'),
            param($p . 'register.correction_window_days'),
        ])->public();
        $s->set(ScheduleResolver::class)->args([service(RegisterStoreInterface::class), service(ScheduleFallbackProviderInterface::class), param($p . 'register.schedule.default_timezone')])->public();
        $s->set(RegisterAnnotations::class)->args([service(ScheduleResolver::class), tagged_iterator(self::DAY_ANNOTATION_TAG)])->public();
        $s->set(RegisterReader::class)->args([service(RegisterStoreInterface::class), service(RegisterRules::class)])->public();
        $s->alias(RegisterReaderInterface::class, RegisterReader::class)->public();
        $s->set(RevisionChain::class)->args([service(RegisterStoreInterface::class), service(TransactionRunnerInterface::class), service(IdGeneratorInterface::class)]);
        $s->set(NoticeOutbox::class)->args([service(RegisterStoreInterface::class), service(WorkerDirectoryInterface::class), service(ScheduleResolver::class), service(IdGeneratorInterface::class), param($p . 'register.reminders.window_minutes'), param($p . 'register.reminders.default_hours')]);
        $s->set(TimeClock::class)->args([
            service(RegisterAccess::class),
            service(RegisterStoreInterface::class),
            service(RegisterReader::class),
            service(ScheduleResolver::class),
            service(RevisionChain::class),
            service(TransactionRunnerInterface::class),
            service(IdGeneratorInterface::class),
            $clock,
            service(TaskTimerLinkInterface::class),
            $events,
            $logger,
            service(RegisterRules::class),
            param($p . 'register.ip_evidence_allowed'),
        ]);
        $s->alias(RegisterClockInterface::class, TimeClock::class)->public();
        $s->set(CorrectionService::class)->args([
            service(RegisterAccess::class),
            service(RegisterStoreInterface::class),
            service(RegisterReader::class),
            service(ScheduleResolver::class),
            service(RevisionChain::class),
            service(NoticeOutbox::class),
            service(TransactionRunnerInterface::class),
            service(IdGeneratorInterface::class),
            service(WorkerDirectoryInterface::class),
            service(WorkingTimeAuditSinkInterface::class),
            $clock,
            $events,
            service(RegisterRules::class),
        ]);
        $s->alias(CorrectionServiceInterface::class, CorrectionService::class)->public();
        $s->set(ScheduleService::class)->args([
            service(RegisterAccess::class),
            service(RegisterStoreInterface::class),
            service(ScheduleResolver::class),
            service(WorkerDirectoryInterface::class),
            service(TransactionRunnerInterface::class),
            service(IdGeneratorInterface::class),
            service(WorkingTimeAuditSinkInterface::class),
            $clock,
            $holidays ? service(HolidayCalendarReaderInterface::class) : null,
        ]);
        $s->alias(ScheduleServiceInterface::class, ScheduleService::class)->public();
        $s->set(RegisterSettingsEditor::class)->args([
            service(RegisterAccess::class),
            service(RegisterStoreInterface::class),
            service(TransactionRunnerInterface::class),
            service(WorkingTimeAuditSinkInterface::class),
            service(TriggerInspectorInterface::class),
            $clock,
            $events,
            param($p . 'integrity.require_triggers'),
            param($p . 'register.ip_evidence_allowed'),
        ]);
        $s->alias(RegisterSettingsEditorInterface::class, RegisterSettingsEditor::class)->public();
        $s->set(WorkerPreferences::class)->args([service(RegisterAccess::class), service(RegisterStoreInterface::class), service(TransactionRunnerInterface::class), $clock])->public();
        $s->set(ChainVerifier::class)->args([service(RegisterStoreInterface::class), service(TransactionRunnerInterface::class), service(WorkingTimeAuditSinkInterface::class), $events, $clock]);
        $s->alias(ChainVerifierInterface::class, ChainVerifier::class)->public();
        $s->set(RegisterPurger::class)->args([
            service(RegisterStoreInterface::class),
            service(RegisterPurgeStoreInterface::class),
            service(TransactionRunnerInterface::class),
            service(IdGeneratorInterface::class),
            service(WorkingTimeAuditSinkInterface::class),
            $clock,
            $events,
        ])->public();
        $s->set(RegisterExportService::class)->args([
            service(RegisterAccess::class),
            service(RegisterStoreInterface::class),
            service(ExportFileStoreInterface::class),
            service(ExportDispatcherInterface::class),
            service(TransactionRunnerInterface::class),
            service(IdGeneratorInterface::class),
            service(WorkingTimeAuditSinkInterface::class),
            $clock,
            param($p . 'register.export.formats'),
            param($p . 'register.export.max_months'),
        ])->public();
        $s->alias(RegisterExportServiceInterface::class, RegisterExportService::class)->public();
        $s->set(RegisterReportBuilder::class)->args([service('translator')]);
        $s->set(RegisterExportGenerator::class)->args([
            service(RegisterStoreInterface::class),
            service(RegisterReader::class),
            service(RegisterAccess::class),
            service(ChainVerifierInterface::class),
            service(RegisterReportBuilder::class),
            service($p . 'pdf_renderer')->nullOnInvalid(),
            service(ExportFileStoreInterface::class),
            service($p . 'brand_provider'),
            service(WorkingTimeTenantResolverInterface::class),
            service(WorkerDirectoryInterface::class),
            service(TransactionRunnerInterface::class),
            service(WorkingTimeAuditSinkInterface::class),
            service('translator'),
            $clock,
            $events,
            $logger,
            param($p . 'integrity.verify_before_export'),
            param($p . 'register.export.download_days'),
        ])->public();
        $s->alias(ExportGeneratorInterface::class, RegisterExportGenerator::class);
        $s->set($p . 'export_dispatcher.sync', SyncExportDispatcher::class)->args([service(ExportGeneratorInterface::class)]);
        $s->alias(ExportDispatcherInterface::class, $p . 'export_dispatcher.sync');
        $s->set(GenerateRegisterExportHandler::class)->args([service(ExportGeneratorInterface::class)]);
        $s->set(NoticeDispatcher::class)->args([
            service(RegisterStoreInterface::class),
            service(RegisterReader::class),
            service(ScheduleResolver::class),
            service(NoticeOutbox::class),
            service(WorkerDirectoryInterface::class),
            service(WorkingTimeNotifierInterface::class),
            service(TransactionRunnerInterface::class),
            $clock,
            $logger,
            param($p . 'register.reminders.window_minutes'),
            param($p . 'register.monthly_summary.enabled'),
            500,
            service(RegisterAnnotations::class),
        ])->public();
        $s->set(RegisterPersonalData::class)->args([
            service(RegisterStoreInterface::class),
            service(ExportFileStoreInterface::class),
            service(RegisterReader::class),
            service(TransactionRunnerInterface::class),
            $clock,
            service(WorkingTimeAuditSinkInterface::class),
        ])->public();

        if ($holidays) {
            self::holidays($container, $config, $festivos);
        }
        if ($leave) {
            self::leave($container);
        }

        // Console.
        $s->set(VerifyRegisterCommand::class)->args([service(ChainVerifierInterface::class)])->tag('console.command');
        $s->set(DoctrineRegisterTriggers::class)->args([service(DoctrineTransactionRunner::class), service(RegisterTriggerInstaller::class)]);
        $s->alias(RegisterTriggersInterface::class, DoctrineRegisterTriggers::class);
        foreach ([TriggersCommand::INSTALL, TriggersCommand::CHECK, TriggersCommand::UNINSTALL] as $name) {
            $s->set($p . 'command.' . $name, TriggersCommand::class)
                ->args([$name, service(RegisterTriggersInterface::class)])
                ->tag('console.command', ['command' => $name]);
        }
        $s->set(PurgeRegisterCommand::class)->args([service(RegisterPurger::class), service(RegisterExportService::class)])->tag('console.command');
        $s->set(NoticesCommand::class)->args([service(NoticeDispatcher::class)])->tag('console.command');

        // HTTP.
        $s->set(WorkingTimeConflictFormatter::class)->tag(ConflictResponseFormatterInterface::TAG, ['priority' => 16]);
        $s->set(UiRouteLoader::class)->args([param($p . 'ui.enabled'), param($p . 'ui.register_path'), param($p . 'ui.settings_path'), param($p . 'register.enabled'), $holidays, $leave, param($p . 'ui.holidays_path'), param($p . 'ui.leave_path')])->tag('routing.loader');
        $s->set(WorkingTimeTwigExtension::class)->args([service(RegisterClockInterface::class), service('router')])->tag('twig.extension');
        $ui = is_array($config['ui'] ?? null) ? $config['ui'] : [];
        if (($ui['enabled'] ?? false) === true) {
            $s->set(UiSupport::class)->args([
                service('twig'),
                service('router'),
                service('form.factory'),
                service(RegisterAccess::class),
                service($p . 'conflict_policy'),
                service('translator'),
                param($p . 'ui.layout_template'),
            ]);
            $controllers = [
                RegisterController::class => [service(UiSupport::class), service(RegisterAccess::class), service(RegisterClockInterface::class), service(CorrectionServiceInterface::class), service(RegisterReader::class), service(RegisterStoreInterface::class), service(ScheduleResolver::class), service(TransactionRunnerInterface::class), $clock, service(RegisterAnnotations::class)],
                TeamController::class     => [service(UiSupport::class), service(RegisterAccess::class), service(CorrectionServiceInterface::class), service(RegisterReader::class), service(RegisterStoreInterface::class), service(ScheduleResolver::class), service(WorkerDirectoryInterface::class), service(TransactionRunnerInterface::class), $clock, param($p . 'ui.page_size')],
                ScheduleController::class => [service(UiSupport::class), service(RegisterAccess::class), service(ScheduleServiceInterface::class), service(WorkerDirectoryInterface::class), $clock, param($p . 'register.schedule.default_timezone'), $holidays ? service(HolidayCalendarReaderInterface::class) : null],
                ExportController::class   => [service(UiSupport::class), service(RegisterAccess::class), service(RegisterExportServiceInterface::class), service(WorkerDirectoryInterface::class), $clock, param($p . 'register.export.formats')],
                SettingsController::class => [service(UiSupport::class), service(RegisterSettingsEditorInterface::class), service(WorkerPreferences::class), service(TriggerInspectorInterface::class), param($p . 'register.ip_evidence_allowed')],
            ];
            if ($holidays) {
                $controllers[HolidayController::class] = [service(UiSupport::class), service(HolidayAccess::class), service(HolidayCalendarServiceInterface::class), $clock, $festivos ? service(MunicipalityDirectoryInterface::class) : null, param($p . 'holidays.providers.file.max_bytes')];
            }
            if ($leave) {
                $controllers[LeaveController::class] = [service(UiSupport::class), service(LeaveAccess::class), service(LeaveAdministrationInterface::class), service(LeaveBalanceReaderInterface::class), service(WorkerDirectoryInterface::class), $clock];
            }
            foreach ([ClockType::class, CorrectionType::class, DecisionType::class, AnswerType::class, WithdrawType::class, PreferencesType::class, ScheduleType::class, ExportType::class, SettingsType::class, ActionType::class, HolidayCalendarType::class, HolidayDayType::class, HolidayImportType::class, HolidayImportConfirmType::class, HolidayProviderType::class, WorkCentreType::class, LeaveTypeType::class, AllowancePolicyType::class, AllowanceLineType::class, LeaveSettingsType::class] as $type) {
                $s->set($type)->tag('form.type');
            }
            foreach ($controllers as $class => $args) {
                $s->set($class)->args($args)->public()->tag('controller.service_arguments');
            }
        }

        // Bridges (the packages are optional).
        if (interface_exists(WorkdaySourceInterface::class)) {
            $s->set(TimeTrackWorkdaySource::class)->args([
                service(RegisterClockInterface::class),
                service(RegisterAccess::class),
                service(RegisterStoreInterface::class),
                service(ScheduleResolver::class),
                service(WorkerDirectoryInterface::class),
                service('request_stack')->nullOnInvalid(),
                param($p . 'register.reminders.window_minutes'),
            ])->public();
            $s->alias($p . 'time_track.workday_source', TimeTrackWorkdaySource::class)->public();
        }
        if (interface_exists(PersonalDataEraserInterface::class)) {
            $s->set($p . 'default.worker_of_user', IdentifierWorkerOfUser::class);
            $s->alias(WorkerOfUserInterface::class, $p . 'default.worker_of_user');
            $s->set(UserKitRegisterExportProvider::class)->args([service(RegisterPersonalData::class), service(WorkerOfUserInterface::class)])->tag('nowo_user_kit.personal_data.export_provider')->public();
            $s->set(UserKitRegisterEraser::class)->args([service(RegisterPersonalData::class), service(WorkerOfUserInterface::class)])->tag('nowo_user_kit.personal_data.eraser')->public();
        }
    }

    /**
     * Which components are on: holidays, its festivos.io provider, leave.
     *
     * @param array<string, mixed> $config
     *
     * @return array{bool, bool, bool}
     */
    private static function components(array $config): array
    {
        $holidays  = is_array($config['holidays'] ?? null) ? $config['holidays'] : [];
        $leave     = is_array($config['leave'] ?? null) ? $config['leave'] : [];
        $providers = is_array($holidays['providers'] ?? null) ? $holidays['providers'] : [];
        $festivos  = is_array($providers['festivos_io'] ?? null) ? $providers['festivos_io'] : [];
        $on        = ($holidays['enabled'] ?? true) === true;

        return [$on, $on && ($festivos['enabled'] ?? true) === true, ($leave['enabled'] ?? false) === true];
    }

    /**
     * The holiday component (contract sections 7, 8.2 and 9.2): usable without the register or leave.
     *
     * @param array<string, mixed> $config
     */
    private static function holidays(ContainerConfigurator $container, array $config, bool $festivos): void
    {
        $s        = $container->services();
        $p        = self::P;
        $holidays = is_array($config['holidays'] ?? null) ? $config['holidays'] : [];
        $client   = $holidays['http_client'] ?? null;
        $disabled = [];
        foreach (is_array($holidays['providers'] ?? null) ? $holidays['providers'] : [] as $key => $provider) {
            if (is_array($provider) && ($provider['enabled'] ?? true) !== true && $key !== 'file') {
                $disabled[] = (string) $key;
            }
        }
        $s->set(DoctrineHolidayStore::class)->args([service(DoctrineTransactionRunner::class)]);
        $s->alias(HolidayStoreInterface::class, DoctrineHolidayStore::class);
        $s->set(HolidayCalendarReader::class)->args([service(HolidayStoreInterface::class), service(TransactionRunnerInterface::class)]);
        $s->alias(HolidayCalendarReaderInterface::class, HolidayCalendarReader::class)->public();
        $s->set(HolidayAccess::class)->args([service(WorkingTimeTenantResolverInterface::class), service(WorkingTimePermissionCheckerInterface::class), service(WorkerDirectoryInterface::class)])->public();
        $s->set(GuardedJsonClient::class)->args([service($p . 'outbound_guard'), is_string($client) && $client !== '' ? service($client) : service('http_client')->nullOnInvalid()]);
        $s->set($p . 'holiday_provider.festivos_io', FestivosIoProvider::class)
            ->args([service(GuardedJsonClient::class), param($p . 'holidays.providers.festivos_io.base_uri'), param($p . 'holidays.providers.festivos_io.timeout')])
            ->tag(self::HOLIDAY_PROVIDER_TAG, ['priority' => 20]);
        $s->set($p . 'holiday_provider.openholidays', OpenHolidaysProvider::class)
            ->args([service(GuardedJsonClient::class), service($p . 'holiday_cache')->nullOnInvalid(), param($p . 'holidays.providers.openholidays.base_uri'), param($p . 'holidays.providers.openholidays.timeout'), param($p . 'holidays.providers.openholidays.subdivisions_ttl')])
            ->tag(self::HOLIDAY_PROVIDER_TAG, ['priority' => 10])
            ->public();
        $s->set(HolidayProviderRegistry::class)->args([tagged_iterator(self::HOLIDAY_PROVIDER_TAG), $disabled]);
        $s->set(HolidayCalendarService::class)->args([
            service(HolidayAccess::class),
            service(HolidayStoreInterface::class),
            service(HolidayProviderRegistry::class),
            service(TransactionRunnerInterface::class),
            service(IdGeneratorInterface::class),
            service(WorkingTimeAuditSinkInterface::class),
            service($p . 'clock'),
            service('event_dispatcher')->nullOnInvalid(),
            param($p . 'holidays.providers.file.enabled'),
            param($p . 'holidays.providers.file.max_rows'),
            param($p . 'holidays.providers.file.max_bytes'),
        ]);
        $s->alias(HolidayCalendarServiceInterface::class, HolidayCalendarService::class)->public();
        if ($festivos) {
            $s->set(FestivosIoMunicipalityDirectory::class)->args([service(GuardedJsonClient::class), service($p . 'holiday_cache'), param($p . 'holidays.providers.festivos_io.base_uri'), param($p . 'holidays.providers.festivos_io.timeout'), param($p . 'holidays.providers.festivos_io.municipalities_ttl')]);
            $s->alias(MunicipalityDirectoryInterface::class, FestivosIoMunicipalityDirectory::class)->public();
        }
        $s->set(HolidayDayAnnotations::class)->args([service(HolidayCalendarReaderInterface::class)])->tag(self::DAY_ANNOTATION_TAG);
    }

    /**
     * The leave foundations (contract sections 7 and 9.3; S2: types, allowances, balances, the day counter).
     */
    private static function leave(ContainerConfigurator $container): void
    {
        $s = $container->services();
        $p = self::P;
        $s->set(DoctrineLeaveStore::class)->args([service(DoctrineTransactionRunner::class)]);
        $s->alias(LeaveStoreInterface::class, DoctrineLeaveStore::class);
        $s->set($p . 'default.leave_usage', NoLeaveUsage::class);
        $s->alias(LeaveUsageInterface::class, $p . 'default.leave_usage');
        $s->set(TranslatedLeaveSeedNames::class)->args([service('translator')]);
        $s->alias(LeaveSeedNamesInterface::class, TranslatedLeaveSeedNames::class);
        $s->set(LeaveAccess::class)->args([service(WorkingTimeTenantResolverInterface::class), service(WorkingTimePermissionCheckerInterface::class), service(RegisterAccess::class), service(LeaveStoreInterface::class), service($p . 'clock')])->public();
        $s->set(LeaveTypeSeeder::class)->args([service(LeaveStoreInterface::class), service(LeaveSeedNamesInterface::class), service(IdGeneratorInterface::class)]);
        $s->set(LeaveAdministration::class)->args([
            service(LeaveAccess::class),
            service(LeaveStoreInterface::class),
            service(LeaveTypeSeeder::class),
            service(WorkerDirectoryInterface::class),
            service(TransactionRunnerInterface::class),
            service(IdGeneratorInterface::class),
            service(WorkingTimeAuditSinkInterface::class),
            service($p . 'clock'),
            service('event_dispatcher')->nullOnInvalid(),
        ])->public();
        $s->alias(LeaveAdministrationInterface::class, LeaveAdministration::class)->public();
        $s->set(LeaveBalanceReader::class)->args([service(LeaveStoreInterface::class), service(LeaveUsageInterface::class), service(WorkerDirectoryInterface::class), service(TransactionRunnerInterface::class), service($p . 'clock')]);
        $s->alias(LeaveBalanceReaderInterface::class, LeaveBalanceReader::class)->public();
        $s->set(DayCounter::class)->args([service(ScheduleResolver::class), service(HolidayCalendarReaderInterface::class), service(TransactionRunnerInterface::class)])->public();
        $s->set(LeaveDailyCommand::class)->args([service(LeaveAdministration::class)])->tag('console.command');
        $s->set(LeavePersonalData::class)->args([service(LeaveStoreInterface::class), service(TransactionRunnerInterface::class), service(WorkingTimeAuditSinkInterface::class)])->public();
        if (interface_exists(PersonalDataEraserInterface::class)) {
            $s->set(UserKitLeaveExportProvider::class)->args([service(LeavePersonalData::class), service(WorkerOfUserInterface::class)])->tag('nowo_user_kit.personal_data.export_provider')->public();
            $s->set(UserKitLeaveEraser::class)->args([service(LeavePersonalData::class), service(WorkerOfUserInterface::class)])->tag('nowo_user_kit.personal_data.eraser')->public();
        }
    }
}
