# Code inventory - 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/working-time-bundle`  
**Last audited**: 2026-11-22 (0.3.0, HostWriteProbe mapped)

Scope: every file under `src/`. Audit command: `find src -type f | wc -l` → **333**.

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Application/Holiday/HolidayAccess.php` | Access | FR-ACCESS-001, FR-HOL-001 |
| `src/Application/Holiday/HolidayCalendarReader.php` | Holidays | FR-HOL-001 |
| `src/Application/Holiday/HolidayCalendarService.php` | Holidays | FR-HOL-001 |
| `src/Application/Holiday/HolidayContext.php` | Holidays | FR-HOL-001 |
| `src/Application/Holiday/HolidayProviderRegistry.php` | Holiday providers and imports | FR-HOL-002 |
| `src/Application/Holiday/Port/HolidayStoreInterface.php` | Holidays | FR-HOL-001 |
| `src/Application/Leave/AllowanceResolver.php` | Leave balances | FR-LEAVE-002 |
| `src/Application/Leave/DayCounter.php` | Leave balances | FR-LEAVE-002 |
| `src/Application/Leave/LeaveAccess.php` | Access | FR-ACCESS-001, FR-LEAVE-001 |
| `src/Application/Leave/LeaveAdministration.php` | Leave types and policies | FR-LEAVE-001 |
| `src/Application/Leave/LeaveBalanceReader.php` | Leave balances | FR-LEAVE-002 |
| `src/Application/Leave/LeaveContext.php` | Leave types and policies | FR-LEAVE-001 |
| `src/Application/Leave/LeavePersonalData.php` | Bridges and personal data | FR-GDPR-001 |
| `src/Application/Leave/LeaveTypeSeeder.php` | Leave types and policies | FR-LEAVE-001 |
| `src/Application/Leave/NoLeaveUsage.php` | Leave balances | FR-LEAVE-002 |
| `src/Application/Leave/Port/LeaveSeedNamesInterface.php` | Leave types and policies | FR-LEAVE-001 |
| `src/Application/Leave/Port/LeaveStoreInterface.php` | Leave types and policies | FR-LEAVE-001 |
| `src/Application/Leave/Port/LeaveUsageInterface.php` | Leave balances | FR-LEAVE-002 |
| `src/Application/Leave/StaleLeaveSettingsException.php` | Leave types and policies | FR-LEAVE-001 |
| `src/Application/Register/ChainVerifier.php` | Hash chain | FR-CHAIN-001 |
| `src/Application/Register/CorrectionService.php` | Corrections | FR-REG-003 |
| `src/Application/Register/Exception/ChainBusyException.php` | Clocking | FR-REG-001 |
| `src/Application/Register/Exception/StaleCorrectionDecisionException.php` | Corrections | FR-REG-003 |
| `src/Application/Register/Exception/StaleSettingsException.php` | Register settings | FR-REG-004 |
| `src/Application/Register/HolidayDayAnnotations.php` | Register reading | FR-REG-005 |
| `src/Application/Register/Notice/HoursWindow.php` | Notices | FR-NOTICE-001 |
| `src/Application/Register/Notice/NoticeDispatcher.php` | Notices | FR-NOTICE-001 |
| `src/Application/Register/Notice/NoticeOutbox.php` | Notices | FR-NOTICE-001 |
| `src/Application/Register/Port/ExportDispatcherInterface.php` | Exports | FR-EXPORT-001 |
| `src/Application/Register/Port/ExportGeneratorInterface.php` | Exports | FR-EXPORT-001 |
| `src/Application/Register/Port/PurgeRefusedException.php` | Retention purge | FR-PURGE-001 |
| `src/Application/Register/Port/RegisterPurgeStoreInterface.php` | Retention purge | FR-PURGE-001 |
| `src/Application/Register/Port/RegisterStoreInterface.php` | Storage and triggers | FR-DB-001, FR-REG-001 |
| `src/Application/Register/Port/TriggerInspectorInterface.php` | Storage and triggers | FR-DB-002 |
| `src/Application/Register/RegisterAccess.php` | Access | FR-ACCESS-001 |
| `src/Application/Register/RegisterAnnotations.php` | Register reading | FR-REG-005 |
| `src/Application/Register/RegisterContext.php` | Register reading | FR-REG-005 |
| `src/Application/Register/RegisterExportService.php` | Exports | FR-EXPORT-001 |
| `src/Application/Register/RegisterPersonalData.php` | Bridges and personal data | FR-GDPR-001 |
| `src/Application/Register/RegisterPurger.php` | Retention purge | FR-PURGE-001 |
| `src/Application/Register/RegisterReader.php` | Register reading | FR-REG-005 |
| `src/Application/Register/RegisterSettingsEditor.php` | Register settings | FR-REG-004 |
| `src/Application/Register/RevisionChain.php` | Hash chain | FR-CHAIN-001 |
| `src/Application/Register/ScheduleResolver.php` | Schedules | FR-REG-002 |
| `src/Application/Register/ScheduleService.php` | Schedules | FR-REG-002 |
| `src/Application/Register/TimeClock.php` | Clocking | FR-REG-001 |
| `src/Application/Register/WorkerPreferences.php` | Register settings | FR-REG-004 |
| `src/Application/Shared/AfterCommitEvents.php` | Write units and concurrency | FR-TXN-001 |
| `src/Application/Shared/DuplicateRowException.php` | Write units and concurrency | FR-TXN-001, FR-TXN-002 |
| `src/Application/Shared/StaleRowException.php` | Write units and concurrency | FR-TXN-001, FR-TXN-002 |
| `src/Application/Shared/TransactionRunnerInterface.php` | Write units and concurrency | FR-TXN-001 |
| `src/Application/Shared/WriteConflictException.php` | Write units and concurrency | FR-TXN-001, FR-TXN-002 |
| `src/Bridge/Dompdf/DompdfRegisterPdfRenderer.php` | Exports | FR-EXPORT-001, FR-PDF-001, FR-BRIDGE-001 |
| `src/Bridge/TimeTrack/TimeTrackTaskTimerLink.php` | Bridges | FR-BRIDGE-001 |
| `src/Bridge/TimeTrack/TimeTrackWorkdaySource.php` | Bridges | FR-BRIDGE-001 |
| `src/Bridge/UserKit/IdentifierWorkerOfUser.php` | Bridges and personal data | FR-BRIDGE-002, FR-GDPR-001 |
| `src/Bridge/UserKit/UserKitLeaveEraser.php` | Bridges and personal data | FR-BRIDGE-002, FR-GDPR-001 |
| `src/Bridge/UserKit/UserKitLeaveExportProvider.php` | Bridges and personal data | FR-BRIDGE-002, FR-GDPR-001 |
| `src/Bridge/UserKit/UserKitRegisterEraser.php` | Bridges and personal data | FR-BRIDGE-002, FR-GDPR-001 |
| `src/Bridge/UserKit/UserKitRegisterExportProvider.php` | Bridges and personal data | FR-BRIDGE-002, FR-GDPR-001 |
| `src/Bridge/UserKit/WorkerOfUserInterface.php` | Bridges and personal data | FR-BRIDGE-002, FR-GDPR-001 |
| `src/Domain/Holiday/Enum/HolidayOrigin.php` | Holidays | FR-HOL-001 |
| `src/Domain/Holiday/Enum/HolidayScope.php` | Holidays | FR-HOL-001 |
| `src/Domain/Holiday/Enum/ImportStatus.php` | Holiday providers and imports | FR-HOL-002 |
| `src/Domain/Holiday/Event/HolidaysImported.php` | Holiday providers and imports | FR-HOL-002 |
| `src/Domain/Holiday/Exception/HolidayRefusedException.php` | Holidays | FR-HOL-001 |
| `src/Domain/Holiday/HolidayCalendarId.php` | Holidays | FR-HOL-001 |
| `src/Domain/Holiday/HolidayCalendarInput.php` | Holidays | FR-HOL-001 |
| `src/Domain/Holiday/HolidayEntry.php` | Holidays | FR-HOL-001 |
| `src/Domain/Holiday/HolidayFetchResult.php` | Holiday providers and imports | FR-HOL-002 |
| `src/Domain/Holiday/HolidayLocation.php` | Holidays | FR-HOL-001 |
| `src/Domain/Holiday/Import/HolidayImportParser.php` | Holiday providers and imports | FR-HOL-002 |
| `src/Domain/Holiday/ImportPreview.php` | Holiday providers and imports | FR-HOL-002 |
| `src/Domain/Holiday/ImportPreviewRow.php` | Holiday providers and imports | FR-HOL-002 |
| `src/Domain/Holiday/ImportResult.php` | Holiday providers and imports | FR-HOL-002 |
| `src/Domain/Holiday/Model/HolidayCalendar.php` | Holidays | FR-HOL-001 |
| `src/Domain/Holiday/Model/HolidayDay.php` | Holidays | FR-HOL-001 |
| `src/Domain/Holiday/Model/WorkCentre.php` | Holidays | FR-HOL-001 |
| `src/Domain/Holiday/Service/HolidayCalendarReaderInterface.php` | Holidays | FR-HOL-001 |
| `src/Domain/Holiday/Service/HolidayCalendarServiceInterface.php` | Holidays | FR-HOL-001 |
| `src/Domain/Holiday/Service/MunicipalityDirectoryInterface.php` | Holidays | FR-HOL-001 |
| `src/Domain/Holiday/WorkCentreId.php` | Holidays | FR-HOL-001 |
| `src/Domain/Leave/Balance.php` | Leave balances | FR-LEAVE-002 |
| `src/Domain/Leave/DayCount.php` | Leave balances | FR-LEAVE-002 |
| `src/Domain/Leave/Entitlement.php` | Leave balances | FR-LEAVE-002 |
| `src/Domain/Leave/Enum/AllowanceLineKind.php` | Leave types and policies | FR-LEAVE-001 |
| `src/Domain/Leave/Enum/AllowanceSource.php` | Leave types and policies | FR-LEAVE-001 |
| `src/Domain/Leave/Enum/DayPart.php` | Leave types and policies | FR-LEAVE-001 |
| `src/Domain/Leave/Enum/LeaveUnit.php` | Leave types and policies | FR-LEAVE-001 |
| `src/Domain/Leave/Enum/RoundingMode.php` | Leave types and policies | FR-LEAVE-001 |
| `src/Domain/Leave/Event/LeaveAllowanceAdjusted.php` | Leave types and policies | FR-LEAVE-001 |
| `src/Domain/Leave/Exception/LeaveRefusedException.php` | Leave types and policies | FR-LEAVE-001 |
| `src/Domain/Leave/LeaveAmount.php` | Leave balances | FR-LEAVE-002 |
| `src/Domain/Leave/LeaveSettingsInput.php` | Leave types and policies | FR-LEAVE-001 |
| `src/Domain/Leave/LeaveTypeId.php` | Leave types and policies | FR-LEAVE-001 |
| `src/Domain/Leave/LeaveTypeInput.php` | Leave types and policies | FR-LEAVE-001 |
| `src/Domain/Leave/Model/AllowanceLine.php` | Leave types and policies | FR-LEAVE-001 |
| `src/Domain/Leave/Model/AllowancePolicy.php` | Leave types and policies | FR-LEAVE-001 |
| `src/Domain/Leave/Model/LeaveSettings.php` | Leave types and policies | FR-LEAVE-001 |
| `src/Domain/Leave/Model/LeaveType.php` | Leave types and policies | FR-LEAVE-001 |
| `src/Domain/Leave/Service/LeaveAdministrationInterface.php` | Leave types and policies | FR-LEAVE-001 |
| `src/Domain/Leave/Service/LeaveBalanceReaderInterface.php` | Leave balances | FR-LEAVE-002 |
| `src/Domain/Port/ExportFileStoreInterface.php` | Ports and defaults | FR-PORT-001 |
| `src/Domain/Port/HolidayProviderInterface.php` | Ports and defaults | FR-PORT-001 |
| `src/Domain/Port/IdGeneratorInterface.php` | Ports and defaults | FR-PORT-001 |
| `src/Domain/Port/RegisterDayAnnotationProviderInterface.php` | Ports and defaults | FR-PORT-001 |
| `src/Domain/Port/RegisterPdfRendererInterface.php` | Ports and defaults | FR-PORT-001 |
| `src/Domain/Port/ScheduleFallbackProviderInterface.php` | Ports and defaults | FR-PORT-001 |
| `src/Domain/Port/TaskTimerLinkInterface.php` | Ports and defaults | FR-PORT-001 |
| `src/Domain/Port/WorkerDirectoryInterface.php` | Ports and defaults | FR-PORT-001 |
| `src/Domain/Port/WorkingTimeAuditSinkInterface.php` | Ports and defaults | FR-PORT-001 |
| `src/Domain/Port/WorkingTimeNotifierInterface.php` | Ports and defaults | FR-PORT-001 |
| `src/Domain/Port/WorkingTimePermissionCheckerInterface.php` | Ports and defaults | FR-PORT-001 |
| `src/Domain/Port/WorkingTimeTenantResolverInterface.php` | Ports and defaults | FR-PORT-001 |
| `src/Domain/Register/Chain/BeaconChainCodec.php` | Hash chain | FR-CHAIN-001 |
| `src/Domain/Register/Chain/BundleChainCodec.php` | Hash chain | FR-CHAIN-001 |
| `src/Domain/Register/Chain/CanonicalJson.php` | Hash chain | FR-CHAIN-001 |
| `src/Domain/Register/Chain/ChainCodecInterface.php` | Hash chain | FR-CHAIN-001 |
| `src/Domain/Register/Chain/ChainCodecs.php` | Hash chain | FR-CHAIN-001 |
| `src/Domain/Register/Enum/ChainFormat.php` | Hash chain | FR-CHAIN-001 |
| `src/Domain/Register/Enum/ClockAction.php` | Clocking | FR-REG-001 |
| `src/Domain/Register/Enum/ClockStatus.php` | Clocking | FR-REG-001 |
| `src/Domain/Register/Enum/CorrectionOperation.php` | Corrections | FR-REG-003 |
| `src/Domain/Register/Enum/CorrectionStatus.php` | Corrections | FR-REG-003 |
| `src/Domain/Register/Enum/DayFlag.php` | Register reading | FR-REG-005 |
| `src/Domain/Register/Enum/ExportFormat.php` | Exports | FR-EXPORT-001 |
| `src/Domain/Register/Enum/ExportStatus.php` | Exports | FR-EXPORT-001 |
| `src/Domain/Register/Enum/RevisionKind.php` | Hash chain | FR-CHAIN-001 |
| `src/Domain/Register/Enum/ScheduleScope.php` | Schedules | FR-REG-002 |
| `src/Domain/Register/Enum/SegmentSource.php` | Clocking | FR-REG-001 |
| `src/Domain/Register/Enum/SegmentType.php` | Clocking | FR-REG-001 |
| `src/Domain/Register/Event/ChainBroken.php` | Hash chain | FR-CHAIN-001 |
| `src/Domain/Register/Event/ClockActionRecorded.php` | Clocking | FR-REG-001 |
| `src/Domain/Register/Event/CorrectionDecided.php` | Corrections | FR-REG-003 |
| `src/Domain/Register/Event/CorrectionRecorded.php` | Corrections | FR-REG-003 |
| `src/Domain/Register/Event/CorrectionRequested.php` | Corrections | FR-REG-003 |
| `src/Domain/Register/Event/RegisterExportReady.php` | Exports | FR-EXPORT-001 |
| `src/Domain/Register/Event/RegisterPurged.php` | Retention purge | FR-PURGE-001 |
| `src/Domain/Register/Event/RegisterSettingsChanged.php` | Register settings | FR-REG-004 |
| `src/Domain/Register/Exception/ClockConflictException.php` | Clocking | FR-REG-001 |
| `src/Domain/Register/Exception/CorrectionRefusedException.php` | Corrections | FR-REG-003 |
| `src/Domain/Register/Exception/ExportRefusedException.php` | Exports | FR-EXPORT-001 |
| `src/Domain/Register/Exception/InvalidRequestKeyException.php` | Clocking | FR-REG-001 |
| `src/Domain/Register/Exception/PermissionDeniedException.php` | Access | FR-ACCESS-001 |
| `src/Domain/Register/Exception/RegisterUnavailableException.php` | Errors | FR-ERR-001 |
| `src/Domain/Register/Exception/ScheduleRefusedException.php` | Schedules | FR-REG-002 |
| `src/Domain/Register/Exception/SettingsRefusedException.php` | Register settings | FR-REG-004 |
| `src/Domain/Register/Exception/WorkingTimeExceptionInterface.php` | Errors | FR-ERR-001 |
| `src/Domain/Register/Model/ChainAnchor.php` | Hash chain | FR-CHAIN-001 |
| `src/Domain/Register/Model/CorrectionInput.php` | Corrections | FR-REG-003 |
| `src/Domain/Register/Model/CorrectionRequest.php` | Corrections | FR-REG-003 |
| `src/Domain/Register/Model/DaySchedule.php` | Schedules | FR-REG-002 |
| `src/Domain/Register/Model/RegisterExport.php` | Exports | FR-EXPORT-001 |
| `src/Domain/Register/Model/RegisterExportFile.php` | Exports | FR-EXPORT-001 |
| `src/Domain/Register/Model/RegisterNotice.php` | Notices | FR-NOTICE-001 |
| `src/Domain/Register/Model/RegisterSettings.php` | Register settings | FR-REG-004 |
| `src/Domain/Register/Model/RegisterSettingsInput.php` | Register settings | FR-REG-004 |
| `src/Domain/Register/Model/RetentionPolicy.php` | Retention purge | FR-PURGE-001 |
| `src/Domain/Register/Model/ScheduleAssignment.php` | Schedules | FR-REG-002 |
| `src/Domain/Register/Model/ScheduleDefinition.php` | Schedules | FR-REG-002 |
| `src/Domain/Register/Model/WorkSegment.php` | Clocking | FR-REG-001 |
| `src/Domain/Register/Model/Workday.php` | Clocking | FR-REG-001 |
| `src/Domain/Register/Model/WorkdayRevision.php` | Hash chain | FR-CHAIN-001 |
| `src/Domain/Register/Model/WorkerPreference.php` | Register settings | FR-REG-004 |
| `src/Domain/Register/Read/ClockState.php` | Register reading | FR-REG-005 |
| `src/Domain/Register/Read/DayRecord.php` | Register reading | FR-REG-005 |
| `src/Domain/Register/Read/DayRecordBuilder.php` | Register reading | FR-REG-005 |
| `src/Domain/Register/Read/EffectiveSegment.php` | Register reading | FR-REG-005 |
| `src/Domain/Register/Read/MonthSummary.php` | Register reading | FR-REG-005 |
| `src/Domain/Register/Read/RegisterReport.php` | Register reading | FR-REG-005 |
| `src/Domain/Register/Read/RegisterRules.php` | Register reading | FR-REG-005 |
| `src/Domain/Register/Read/VerificationReport.php` | Hash chain | FR-CHAIN-001 |
| `src/Domain/Register/Service/ChainVerifierInterface.php` | Hash chain | FR-CHAIN-001 |
| `src/Domain/Register/Service/CorrectionServiceInterface.php` | Corrections | FR-REG-003 |
| `src/Domain/Register/Service/RegisterClockInterface.php` | Clocking | FR-REG-001 |
| `src/Domain/Register/Service/RegisterExportServiceInterface.php` | Exports | FR-EXPORT-001 |
| `src/Domain/Register/Service/RegisterReaderInterface.php` | Register reading | FR-REG-005 |
| `src/Domain/Register/Service/RegisterSettingsEditorInterface.php` | Register settings | FR-REG-004 |
| `src/Domain/Register/Service/ScheduleServiceInterface.php` | Schedules | FR-REG-002 |
| `src/Domain/Shared/ActorRef.php` | Shared value objects | FR-SHARED-001 |
| `src/Domain/Shared/AuditEntry.php` | Shared value objects | FR-SHARED-001 |
| `src/Domain/Shared/DayAnnotation.php` | Shared value objects | FR-SHARED-001 |
| `src/Domain/Shared/Exception/AccessDeniedException.php` | Access | FR-ACCESS-001 |
| `src/Domain/Shared/Exception/NotAvailableException.php` | Errors | FR-ERR-001 |
| `src/Domain/Shared/Exception/RefusedException.php` | Errors | FR-ERR-001 |
| `src/Domain/Shared/Exception/WorkingTimeException.php` | Errors | FR-ERR-001 |
| `src/Domain/Shared/GroupRef.php` | Shared value objects | FR-SHARED-001 |
| `src/Domain/Shared/IpPrefix.php` | Shared value objects | FR-SHARED-001 |
| `src/Domain/Shared/LocalDate.php` | Shared value objects | FR-SHARED-001 |
| `src/Domain/Shared/NoticeMessage.php` | Notices | FR-NOTICE-001 |
| `src/Domain/Shared/OpaqueRefTrait.php` | Shared value objects | FR-SHARED-001 |
| `src/Domain/Shared/RequestKey.php` | Shared value objects | FR-SHARED-001 |
| `src/Domain/Shared/TenantProfile.php` | Shared value objects | FR-SHARED-001 |
| `src/Domain/Shared/TenantRef.php` | Shared value objects | FR-SHARED-001 |
| `src/Domain/Shared/Uuid.php` | Shared value objects | FR-SHARED-001 |
| `src/Domain/Shared/WorkerProfile.php` | Shared value objects | FR-SHARED-001 |
| `src/Domain/Shared/WorkerRef.php` | Shared value objects | FR-SHARED-001 |
| `src/Domain/Shared/WorkingTimePermission.php` | Access | FR-ACCESS-001 |
| `src/Infrastructure/Doctrine/BinaryCollation.php` | Storage and triggers | FR-DB-001 |
| `src/Infrastructure/Doctrine/Dbal/DbalRegisterPurgeStore.php` | Retention purge | FR-PURGE-001, FR-DB-002 |
| `src/Infrastructure/Doctrine/Dbal/PurgeFlagResetDriver.php` | Retention purge | FR-PURGE-001, FR-DB-002 |
| `src/Infrastructure/Doctrine/Dbal/PurgeFlagResetMiddleware.php` | Retention purge | FR-PURGE-001, FR-DB-002 |
| `src/Infrastructure/Doctrine/DoctrineHolidayStore.php` | Storage and triggers | FR-DB-001, FR-HOL-001 |
| `src/Infrastructure/Doctrine/DoctrineLeaveStore.php` | Storage and triggers | FR-DB-001, FR-LEAVE-001 |
| `src/Infrastructure/Doctrine/DoctrineRegisterStore.php` | Storage and triggers | FR-DB-001, FR-REG-001 |
| `src/Infrastructure/Doctrine/DoctrineTransactionRunner.php` | Write units and concurrency | FR-TXN-001, FR-WORKER-001 |
| `src/Infrastructure/Doctrine/HostWriteProbe.php` | Write units and concurrency | FR-TXN-001, FR-WORKER-001 |
| `src/Infrastructure/Doctrine/RegisterMetadataListener.php` | Storage and triggers | FR-DB-001 |
| `src/Infrastructure/Doctrine/Trigger/DoctrineTriggerInspector.php` | Storage and triggers | FR-DB-002 |
| `src/Infrastructure/Doctrine/Trigger/RegisterTriggerInstaller.php` | Storage and triggers | FR-DB-002 |
| `src/Infrastructure/Doctrine/Trigger/TriggerInstallException.php` | Storage and triggers | FR-DB-002 |
| `src/Infrastructure/Doctrine/Type/UtcDateTimeImmutableType.php` | Storage and triggers | FR-DB-001 |
| `src/Infrastructure/Symfony/Command/LeaveDailyCommand.php` | Console | FR-CLI-001, FR-LEAVE-003 |
| `src/Infrastructure/Symfony/Command/NoticesCommand.php` | Console | FR-CLI-001, FR-NOTICE-001 |
| `src/Infrastructure/Symfony/Command/PurgeRegisterCommand.php` | Console | FR-CLI-001, FR-PURGE-001 |
| `src/Infrastructure/Symfony/Command/TriggersCommand.php` | Console | FR-CLI-001, FR-DB-002 |
| `src/Infrastructure/Symfony/Command/VerifyRegisterCommand.php` | Console | FR-CLI-001, FR-CHAIN-001 |
| `src/Infrastructure/Symfony/Controller/DayView.php` | Register UI | FR-UI-001, FR-REG-001, FR-REG-003 |
| `src/Infrastructure/Symfony/Controller/ExportController.php` | Exports | FR-UI-001, FR-EXPORT-001 |
| `src/Infrastructure/Symfony/Controller/HolidayController.php` | Holiday UI | FR-UI-001, FR-HOL-001, FR-HOL-002 |
| `src/Infrastructure/Symfony/Controller/LeaveController.php` | Leave UI | FR-UI-001, FR-LEAVE-001, FR-LEAVE-002 |
| `src/Infrastructure/Symfony/Controller/RegisterController.php` | Register UI | FR-UI-001, FR-REG-001, FR-REG-003 |
| `src/Infrastructure/Symfony/Controller/ScheduleController.php` | Register UI | FR-UI-001, FR-REG-002 |
| `src/Infrastructure/Symfony/Controller/SettingsController.php` | Register UI | FR-UI-001, FR-REG-004 |
| `src/Infrastructure/Symfony/Controller/TeamController.php` | Register UI | FR-UI-001, FR-REG-001, FR-REG-003 |
| `src/Infrastructure/Symfony/Controller/UiSupport.php` | Register UI | FR-UI-001, FR-REG-001, FR-REG-003 |
| `src/Infrastructure/Symfony/DependencyInjection/Compiler/PrivateEntityManagerPass.php` | Bundle and DI | FR-EM-001 |
| `src/Infrastructure/Symfony/DependencyInjection/Compiler/TwigPathsPass.php` | Twig and translations | FR-TWIG-001 |
| `src/Infrastructure/Symfony/DependencyInjection/Compiler/WorkingTimePortsPass.php` | Ports and defaults | FR-PORT-001, FR-BRIDGE-001 |
| `src/Infrastructure/Symfony/DependencyInjection/ConfigurationTree.php` | Bundle and DI | FR-CONFIG-001, FR-PARTS-001, FR-LOCK-001, FR-PDF-001 |
| `src/Infrastructure/Symfony/DependencyInjection/ParameterFlattener.php` | Bundle and DI | FR-CONFIG-001 |
| `src/Infrastructure/Symfony/DependencyInjection/ServiceDefinitions.php` | Bundle and DI | FR-CONFIG-001, FR-PARTS-001, FR-WORKER-001 |
| `src/Infrastructure/Symfony/Export/RegisterCsv.php` | Exports | FR-EXPORT-001, FR-EXPORT-002 |
| `src/Infrastructure/Symfony/Export/RegisterExportGenerator.php` | Exports | FR-EXPORT-001, FR-EXPORT-002 |
| `src/Infrastructure/Symfony/Export/RegisterReportBuilder.php` | Exports | FR-EXPORT-001, FR-EXPORT-002 |
| `src/Infrastructure/Symfony/Export/SyncExportDispatcher.php` | Exports | FR-EXPORT-001, FR-EXPORT-002 |
| `src/Infrastructure/Symfony/Form/ActionType.php` | Register UI | FR-FORM-001, FR-REG-001 |
| `src/Infrastructure/Symfony/Form/AllowanceLineType.php` | Leave UI | FR-FORM-001, FR-LEAVE-001 |
| `src/Infrastructure/Symfony/Form/AllowancePolicyType.php` | Leave UI | FR-FORM-001, FR-LEAVE-001 |
| `src/Infrastructure/Symfony/Form/AnswerType.php` | Register UI | FR-FORM-001, FR-REG-003 |
| `src/Infrastructure/Symfony/Form/ClockType.php` | Register UI | FR-FORM-001, FR-REG-001 |
| `src/Infrastructure/Symfony/Form/CorrectionType.php` | Register UI | FR-FORM-001, FR-REG-003 |
| `src/Infrastructure/Symfony/Form/DecisionType.php` | Register UI | FR-FORM-001, FR-REG-003 |
| `src/Infrastructure/Symfony/Form/ExportType.php` | Exports | FR-FORM-001, FR-EXPORT-001 |
| `src/Infrastructure/Symfony/Form/HolidayCalendarType.php` | Holiday UI | FR-FORM-001, FR-HOL-001, FR-HOL-002 |
| `src/Infrastructure/Symfony/Form/HolidayDayType.php` | Holiday UI | FR-FORM-001, FR-HOL-001, FR-HOL-002 |
| `src/Infrastructure/Symfony/Form/HolidayImportConfirmType.php` | Holiday UI | FR-FORM-001, FR-HOL-001, FR-HOL-002 |
| `src/Infrastructure/Symfony/Form/HolidayImportType.php` | Holiday UI | FR-FORM-001, FR-HOL-001, FR-HOL-002 |
| `src/Infrastructure/Symfony/Form/HolidayProviderType.php` | Holiday UI | FR-FORM-001, FR-HOL-001, FR-HOL-002 |
| `src/Infrastructure/Symfony/Form/LeaveSettingsType.php` | Leave UI | FR-FORM-001, FR-LEAVE-001 |
| `src/Infrastructure/Symfony/Form/LeaveTypeType.php` | Leave UI | FR-FORM-001, FR-LEAVE-001 |
| `src/Infrastructure/Symfony/Form/PreferencesType.php` | Register UI | FR-FORM-001, FR-REG-004 |
| `src/Infrastructure/Symfony/Form/ScheduleType.php` | Register UI | FR-FORM-001, FR-REG-002 |
| `src/Infrastructure/Symfony/Form/SettingsType.php` | Register UI | FR-FORM-001, FR-REG-004 |
| `src/Infrastructure/Symfony/Form/WithdrawType.php` | Register UI | FR-FORM-001, FR-REG-003 |
| `src/Infrastructure/Symfony/Form/WorkCentreType.php` | Holiday UI | FR-FORM-001, FR-HOL-001, FR-HOL-002 |
| `src/Infrastructure/Symfony/Holiday/FestivosIoMunicipalityDirectory.php` | Holiday providers and imports | FR-HOL-002 |
| `src/Infrastructure/Symfony/Holiday/FestivosIoProvider.php` | Holiday providers and imports | FR-HOL-002 |
| `src/Infrastructure/Symfony/Holiday/GuardedJsonClient.php` | Holiday providers and imports | FR-HOL-002 |
| `src/Infrastructure/Symfony/Holiday/OpenHolidaysProvider.php` | Holiday providers and imports | FR-HOL-002 |
| `src/Infrastructure/Symfony/Http/ConflictFallbackListener.php` | Write units and concurrency | FR-TXN-002, FR-WORKER-001 |
| `src/Infrastructure/Symfony/Http/EntityManagerHygieneListener.php` | Write units and concurrency | FR-TXN-002, FR-WORKER-001 |
| `src/Infrastructure/Symfony/Http/WorkingTimeConflictFormatter.php` | Write units and concurrency | FR-TXN-002, FR-WORKER-001 |
| `src/Infrastructure/Symfony/Leave/TranslatedLeaveSeedNames.php` | Leave types and policies | FR-LEAVE-001 |
| `src/Infrastructure/Symfony/Messenger/GenerateRegisterExportHandler.php` | Exports | FR-EXPORT-001, FR-EXPORT-002 |
| `src/Infrastructure/Symfony/Messenger/GenerateRegisterExportMessage.php` | Exports | FR-EXPORT-001, FR-EXPORT-002 |
| `src/Infrastructure/Symfony/Messenger/MessengerExportDispatcher.php` | Exports | FR-EXPORT-001, FR-EXPORT-002 |
| `src/Infrastructure/Symfony/Port/ConfiguredScheduleFallback.php` | Ports and defaults | FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/CurrentUserWorkerDirectory.php` | Ports and defaults | FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/DatabaseExportFileStore.php` | Ports and defaults | FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/MailerNotifier.php` | Ports and defaults | FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/NeutralBrandProvider.php` | Ports and defaults | FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/NullNotifier.php` | Ports and defaults | FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/NullTaskTimerLink.php` | Ports and defaults | FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/PsrLogAuditSink.php` | Ports and defaults | FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/RoleMapPermissionChecker.php` | Ports and defaults | FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/SingleTenantResolver.php` | Ports and defaults | FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/SymfonyUuidGenerator.php` | Ports and defaults | FR-PORT-001 |
| `src/Infrastructure/Symfony/Routing/UiRouteLoader.php` | Register UI | FR-UI-001 |
| `src/Infrastructure/Symfony/Twig/WorkingTimeTwigExtension.php` | Twig and translations | FR-TWIG-001 |
| `src/NowoWorkingTimeBundle.php` | Bundle and DI | FR-CONFIG-001, FR-PARTS-001, FR-EM-001 |
| `src/Resources/config/doctrine-holiday/HolidayCalendar.orm.xml` | Storage and triggers | FR-DB-001, FR-HOL-001 |
| `src/Resources/config/doctrine-holiday/HolidayDay.orm.xml` | Storage and triggers | FR-DB-001, FR-HOL-001 |
| `src/Resources/config/doctrine-holiday/WorkCentre.orm.xml` | Storage and triggers | FR-DB-001, FR-HOL-001 |
| `src/Resources/config/doctrine-leave/AllowanceLine.orm.xml` | Storage and triggers | FR-DB-001, FR-LEAVE-001 |
| `src/Resources/config/doctrine-leave/AllowancePolicy.orm.xml` | Storage and triggers | FR-DB-001, FR-LEAVE-001 |
| `src/Resources/config/doctrine-leave/LeaveSettings.orm.xml` | Storage and triggers | FR-DB-001, FR-LEAVE-001 |
| `src/Resources/config/doctrine-leave/LeaveType.orm.xml` | Storage and triggers | FR-DB-001, FR-LEAVE-001 |
| `src/Resources/config/doctrine/ChainAnchor.orm.xml` | Storage and triggers | FR-DB-001, FR-REG-001 |
| `src/Resources/config/doctrine/CorrectionRequest.orm.xml` | Storage and triggers | FR-DB-001, FR-REG-001 |
| `src/Resources/config/doctrine/RegisterExport.orm.xml` | Storage and triggers | FR-DB-001, FR-REG-001 |
| `src/Resources/config/doctrine/RegisterExportFile.orm.xml` | Storage and triggers | FR-DB-001, FR-REG-001 |
| `src/Resources/config/doctrine/RegisterNotice.orm.xml` | Storage and triggers | FR-DB-001, FR-REG-001 |
| `src/Resources/config/doctrine/RegisterSettings.orm.xml` | Storage and triggers | FR-DB-001, FR-REG-001 |
| `src/Resources/config/doctrine/ScheduleAssignment.orm.xml` | Storage and triggers | FR-DB-001, FR-REG-001 |
| `src/Resources/config/doctrine/WorkSegment.orm.xml` | Storage and triggers | FR-DB-001, FR-REG-001 |
| `src/Resources/config/doctrine/Workday.orm.xml` | Storage and triggers | FR-DB-001, FR-REG-001 |
| `src/Resources/config/doctrine/WorkdayRevision.orm.xml` | Storage and triggers | FR-DB-001, FR-REG-001 |
| `src/Resources/config/doctrine/WorkerPreference.orm.xml` | Storage and triggers | FR-DB-001, FR-REG-001 |
| `src/Resources/translations/NowoWorkingTimeBundle.ca.yaml` | Twig and translations | FR-I18N-001 |
| `src/Resources/translations/NowoWorkingTimeBundle.de.yaml` | Twig and translations | FR-I18N-001 |
| `src/Resources/translations/NowoWorkingTimeBundle.en.yaml` | Twig and translations | FR-I18N-001 |
| `src/Resources/translations/NowoWorkingTimeBundle.es.yaml` | Twig and translations | FR-I18N-001 |
| `src/Resources/translations/NowoWorkingTimeBundle.eu.yaml` | Twig and translations | FR-I18N-001 |
| `src/Resources/translations/NowoWorkingTimeBundle.fr.yaml` | Twig and translations | FR-I18N-001 |
| `src/Resources/translations/NowoWorkingTimeBundle.gl.yaml` | Twig and translations | FR-I18N-001 |
| `src/Resources/translations/NowoWorkingTimeBundle.it.yaml` | Twig and translations | FR-I18N-001 |
| `src/Resources/translations/NowoWorkingTimeBundle.nl.yaml` | Twig and translations | FR-I18N-001 |
| `src/Resources/translations/NowoWorkingTimeBundle.pt.yaml` | Twig and translations | FR-I18N-001 |
| `src/Resources/views/email/notice.html.twig` | Notices | FR-NOTICE-001, FR-TWIG-001 |
| `src/Resources/views/export/register_pdf.html.twig` | Exports | FR-EXPORT-001, FR-TWIG-001 |
| `src/Resources/views/holidays/_form.html.twig` | Holiday UI | FR-UI-001, FR-TWIG-001 |
| `src/Resources/views/holidays/calendar.html.twig` | Holiday UI | FR-UI-001, FR-TWIG-001 |
| `src/Resources/views/holidays/centre.html.twig` | Holiday UI | FR-UI-001, FR-TWIG-001 |
| `src/Resources/views/holidays/day.html.twig` | Holiday UI | FR-UI-001, FR-TWIG-001 |
| `src/Resources/views/holidays/index.html.twig` | Holiday UI | FR-UI-001, FR-TWIG-001 |
| `src/Resources/views/holidays/preview.html.twig` | Holiday UI | FR-UI-001, FR-TWIG-001 |
| `src/Resources/views/leave/allowances.html.twig` | Leave UI | FR-UI-001, FR-TWIG-001 |
| `src/Resources/views/leave/balances.html.twig` | Leave UI | FR-UI-001, FR-TWIG-001 |
| `src/Resources/views/leave/settings.html.twig` | Leave UI | FR-UI-001, FR-TWIG-001 |
| `src/Resources/views/leave/type.html.twig` | Leave UI | FR-UI-001, FR-TWIG-001 |
| `src/Resources/views/leave/types.html.twig` | Leave UI | FR-UI-001, FR-TWIG-001 |
| `src/Resources/views/register/_correction_form.html.twig` | Register UI | FR-UI-001, FR-TWIG-001 |
| `src/Resources/views/register/_header_clock.html.twig` | Register UI | FR-UI-001, FR-TWIG-001 |
| `src/Resources/views/register/day.html.twig` | Register UI | FR-UI-001, FR-TWIG-001 |
| `src/Resources/views/register/exports.html.twig` | Register UI | FR-UI-001, FR-TWIG-001 |
| `src/Resources/views/register/month.html.twig` | Register UI | FR-UI-001, FR-TWIG-001 |
| `src/Resources/views/register/preferences.html.twig` | Register UI | FR-UI-001, FR-TWIG-001 |
| `src/Resources/views/register/schedules.html.twig` | Register UI | FR-UI-001, FR-TWIG-001 |
| `src/Resources/views/register/settings.html.twig` | Register UI | FR-UI-001, FR-TWIG-001 |
| `src/Resources/views/register/team.html.twig` | Register UI | FR-UI-001, FR-TWIG-001 |
| `src/Resources/views/register/team_day.html.twig` | Register UI | FR-UI-001, FR-TWIG-001 |
| `src/Resources/views/register/today.html.twig` | Register UI | FR-UI-001, FR-TWIG-001 |

## Coverage summary

| Spec section | Files |
| --- | --- |
| Access | 6 |
| Bridges | 2 |
| Bridges and personal data | 8 |
| Bundle and DI | 5 |
| Clocking | 12 |
| Console | 5 |
| Corrections | 11 |
| Errors | 5 |
| Exports | 21 |
| Hash chain | 14 |
| Holiday UI | 13 |
| Holiday providers and imports | 12 |
| Holidays | 18 |
| Leave UI | 10 |
| Leave balances | 10 |
| Leave types and policies | 22 |
| Notices | 6 |
| Ports and defaults | 24 |
| Register UI | 27 |
| Register reading | 13 |
| Register settings | 9 |
| Retention purge | 8 |
| Schedules | 8 |
| Shared value objects | 13 |
| Storage and triggers | 29 |
| Twig and translations | 12 |
| Write units and concurrency | 9 |
| **Total** | **332** |
