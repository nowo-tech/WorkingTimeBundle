<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Database;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Application\Register\Exception\StaleSettingsException;
use Nowo\WorkingTimeBundle\Application\Register\Notice\NoticeDispatcher;
use Nowo\WorkingTimeBundle\Application\Register\RegisterExportService;
use Nowo\WorkingTimeBundle\Application\Register\RegisterPersonalData;
use Nowo\WorkingTimeBundle\Application\Register\WorkerPreferences;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ClockAction;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\CorrectionOperation;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ExportFormat;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ExportStatus;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ScheduleScope;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentType;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\ExportRefusedException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\RegisterUnavailableException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\ScheduleRefusedException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\SettingsRefusedException;
use Nowo\WorkingTimeBundle\Domain\Register\Model\CorrectionInput;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettingsInput;
use Nowo\WorkingTimeBundle\Domain\Register\Model\ScheduleDefinition;
use Nowo\WorkingTimeBundle\Domain\Register\Service\CorrectionServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Service\RegisterExportServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Service\RegisterSettingsEditorInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Service\ScheduleServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\GroupRef;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\NoticeMessage;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use ZipArchive;

use function array_keys;
use function array_map;
use function explode;
use function file_put_contents;
use function hash;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;

/**
 * Settings (version, IP-evidence notice, triggers), schedules (worker → group → tenant, future-only withdrawal),
 * preferences, exports (own copy, inspection CSV / PDF / team ZIP, download window, content: no health data, IP prefixes
 * only), notices (outbox inside the hours, delivery, reminders, monthly summary) and the register half of GDPR.
 */
final class RegisterServicesTest extends RegisterTestCase
{
    public function testSettingsAreSavedOnTheVersionShown(): void
    {
        $this->boot();
        $this->ctx()->member('acme', 'adm');
        $this->actAs('adm', 'manage_settings');
        $editor               = $this->service(RegisterSettingsEditorInterface::class);
        [$settings, $version] = $editor->get();
        self::assertFalse($settings->isEnabled());
        self::assertSame(0, $version);

        $saved = $editor->update(new RegisterSettingsInput(enabled: true, retentionYears: 5), 0);
        self::assertTrue($saved->isEnabled());
        [, $version] = $editor->get();
        self::assertSame(1, $version);
        $editor->update(new RegisterSettingsInput(enabled: true, retentionYears: 6), $version);
        $this->expectException(StaleSettingsException::class);
        $editor->update(new RegisterSettingsInput(enabled: false), $version);
    }

    public function testIpEvidenceNeedsItsNoticeAndTheConfigurationMayForbidIt(): void
    {
        $this->boot(['register' => ['ip_evidence' => 'off']]);
        $this->ctx()->member('acme', 'adm');
        $this->actAs('adm', 'manage_settings');
        try {
            new RegisterSettingsInput(enabled: true, ipEvidence: true);
            self::fail('The notice is required.');
        } catch (SettingsRefusedException $e) {
            self::assertSame('settings.error.ip_notice_required', $e->key);
        }
        $this->expectException(SettingsRefusedException::class);
        $this->service(RegisterSettingsEditorInterface::class)->update(new RegisterSettingsInput(enabled: true, ipEvidence: true, ipNotices: ['en' => 'Notice'], ipNoticeLocale: 'en'), 0);
    }

    public function testRequiredTriggersBlockTurningTheRegisterOn(): void
    {
        $this->boot(['integrity' => ['triggers' => 'require']], triggers: false);
        $this->ctx()->member('acme', 'adm');
        $this->actAs('adm', 'manage_settings');
        try {
            $this->service(RegisterSettingsEditorInterface::class)->update(new RegisterSettingsInput(enabled: true), 0);
            self::fail('Triggers missing.');
        } catch (SettingsRefusedException $e) {
            self::assertSame('settings.error.triggers_missing', $e->key);
        }
        $this->installer()->install($this->connection());
        self::assertTrue($this->service(RegisterSettingsEditorInterface::class)->update(new RegisterSettingsInput(enabled: true), 0)->isEnabled());
    }

    public function testTheMostSpecificScheduleWinsAndOnlyFutureRowsAreWithdrawn(): void
    {
        $this->boot();
        $this->enable('acme', ['ana', 'mia']);
        $this->ctx()->member('acme', 'ana', 'Ana', ['g1']);
        $this->ctx()->groups['acme'] = [[new GroupRef('g1'), 'Group one']];
        $this->actAs('mia', 'manage_team');
        $schedules = $this->service(ScheduleServiceInterface::class);
        $tenant    = $schedules->assign(ScheduleScope::Tenant, null, new LocalDate('2026-11-18'), new ScheduleDefinition('Europe/Madrid', [['day' => 3, 'from' => '09:00', 'to' => '17:00']]));
        $schedules->assign(ScheduleScope::Group, 'g1', new LocalDate('2026-11-18'), new ScheduleDefinition('Europe/Madrid', [['day' => 3, 'from' => '08:00', 'to' => '15:00']]));
        $future = $schedules->assign(ScheduleScope::Worker, 'ana', new LocalDate('2026-12-01'), new ScheduleDefinition('Atlantic/Canary', [], 300, null, 30));

        self::assertSame('08:00', $schedules->resolve(new WorkerRef('ana'), new LocalDate('2026-11-18'))->ranges[0]['from']);
        self::assertSame('09:00', $schedules->resolve(new WorkerRef('mia'), new LocalDate('2026-11-18'))->ranges[0]['from']);
        self::assertSame(300 * 60, $schedules->resolve(new WorkerRef('ana'), new LocalDate('2026-12-02'))->expectedSeconds);
        self::assertCount(3, $schedules->list());
        $this->ctx()->actor = 'ana';
        $this->ctx()->grant('acme', 'ana', 'clock');
        self::assertSame('Europe/Madrid', $schedules->resolve(new WorkerRef('ana'), new LocalDate('2026-11-18'))->timezone);
        $this->ctx()->actor = 'mia';

        foreach ([
            static fn () => $schedules->assign(ScheduleScope::Group, 'nope', new LocalDate('2026-11-20'), new ScheduleDefinition('UTC')),
            static fn () => $schedules->assign(ScheduleScope::Tenant, null, new LocalDate('2026-11-01'), new ScheduleDefinition('UTC')),
            static fn () => $schedules->withdrawFuture($tenant->getId()),
        ] as $refused) {
            try {
                $refused();
                self::fail('Refused.');
            } catch (ScheduleRefusedException) {
            }
        }
        $schedules->withdrawFuture($future->getId());
        self::assertCount(2, $schedules->list());
        $this->expectException(RegisterUnavailableException::class);
        $schedules->withdrawFuture('01900000-0000-7000-8000-000000000000');
    }

    public function testPreferencesFollowWhatTheTenantAllows(): void
    {
        $this->boot();
        $this->enable(input: new RegisterSettingsInput(enabled: true, monthlySummaryMandatory: true));
        $this->actAs('ana', 'clock');
        $preferences                     = $this->service(WorkerPreferences::class);
        [$current, $allowed, $mandatory] = $preferences->get();
        self::assertFalse($current->wantsReminders());
        self::assertFalse($allowed);
        self::assertTrue($mandatory);

        $saved = $preferences->update(true, false);
        self::assertFalse($saved->wantsReminders(), 'reminders not allowed');
        self::assertTrue($saved->wantsMonthlySummary(), 'summary mandatory');
        self::assertTrue($preferences->update(false, false)->wantsMonthlySummary());
    }

    public function testTheOwnCopyAndAnInspectionCsvHoldTheRegisterAndNothingElse(): void
    {
        $this->boot();
        $this->enable('acme', ['ana', 'ins'], new RegisterSettingsInput(enabled: true, ipEvidence: true, ipNotices: ['en' => 'Prefix'], ipNoticeLocale: 'en'));
        $this->actAs('ana', 'clock');
        $this->act(ClockAction::ClockIn, 0, '198.51.100.23');
        $this->act(ClockAction::ClockOut, 60, '198.51.100.23');
        $exports = $this->service(RegisterExportServiceInterface::class);
        $own     = $exports->request(new WorkerRef('ana'), null, '2026-11', '2026-11', ExportFormat::Csv);
        self::assertSame(ExportStatus::Ready, $own->getStatus());
        [$file, $bytes] = $exports->open($own->getId());
        self::assertSame(hash('sha256', $bytes), $file->getSha256());
        self::assertStringStartsWith("\u{FEFF}organization,worker_id,worker_name,date", $bytes);
        self::assertStringContainsString('198.51.100.0/24', $bytes);
        self::assertStringNotContainsString('198.51.100.23', $bytes);
        foreach (['medical', 'diagnos', 'sick', 'health'] as $word) {
            self::assertStringNotContainsStringIgnoringCase($word, $bytes);
        }
        self::assertCount(1, $exports->list());

        $this->actAs('ins', 'export');
        try {
            $exports->open($own->getId());
            self::fail('Someone else\'s own copy is not handed out.');
        } catch (RegisterUnavailableException) {
        }
        $pdf = $exports->request(new WorkerRef('ana'), null, '2026-11', '2026-11', ExportFormat::Pdf);
        self::assertSame(ExportStatus::Ready, $pdf->getStatus(), (string) $pdf->getErrorCode());
        [, $pdfBytes] = $exports->open($pdf->getId());
        self::assertStringStartsWith('%PDF', $pdfBytes);
        $zip          = $exports->request(null, null, '2026-10', '2026-11', ExportFormat::Zip);
        [, $zipBytes] = $exports->open($zip->getId());
        $entries      = $this->zipEntries($zipBytes);
        self::assertContains('SHA256SUMS.txt', array_keys($entries));
        foreach (explode("\n", trim($entries['SHA256SUMS.txt'])) as $line) {
            [$sum, $name] = explode('  ', $line);
            self::assertSame($sum, hash('sha256', $entries[$name]));
        }
        self::assertCount(2, $exports->list(), 'inspection exports, not the own copy of someone else');
        // Audit ST-L3: a requester who loses `export` no longer downloads an inspection export.
        $this->ctx()->grants['acme']['ins'] = ['clock'];
        try {
            $exports->open($pdf->getId());
            self::fail('export revoked');
        } catch (RegisterUnavailableException) {
        }
        $this->actAs('ins', 'export');

        $this->clock()->modify('+8 days');
        self::assertSame(3, $this->service(RegisterExportService::class)->expire());
        $this->expectException(RegisterUnavailableException::class);
        $exports->open($pdf->getId());
    }

    public function testExportRequestsAreChecked(): void
    {
        $this->boot();
        $this->enable('acme', ['ana', 'ins']);
        $this->actAs('ins', 'export');
        $exports = $this->service(RegisterExportServiceInterface::class);
        foreach ([
            [new WorkerRef('ana'), '2026-11', '2026-11', ExportFormat::Zip, 'export.error.format'],
            [null, '2026-11', '2026-11', ExportFormat::Csv, 'export.error.format'],
            [new WorkerRef('ana'), '2026-12', '2026-12', ExportFormat::Csv, 'export.error.period'],
            [new WorkerRef('ana'), '2025-01', '2026-11', ExportFormat::Csv, 'export.error.period'],
            [new WorkerRef('ana'), '2026-11', '2026-10', ExportFormat::Csv, 'export.error.period'],
            [new WorkerRef('ana'), '26-11', '2026-11', ExportFormat::Csv, 'export.error.period'],
        ] as [$worker, $from, $to, $format, $key]) {
            try {
                $exports->request($worker, null, $from, $to, $format);
                self::fail($key);
            } catch (ExportRefusedException $e) {
                self::assertSame($key, $e->key);
            }
        }
        $this->ctx()->grant('acme', 'ins', 'clock');
        try {
            $exports->request(new WorkerRef('ins'), new GroupRef('g'), '2026-11', '2026-11', ExportFormat::Csv);
            self::fail('own copy of a group');
        } catch (ExportRefusedException $e) {
            self::assertSame('export.error.format', $e->key);
        }
        $this->expectException(RegisterUnavailableException::class);
        $exports->status('01900000-0000-7000-8000-000000000000');
    }

    public function testABrokenChainIsPrintedInTheExportAndAFailedGenerationKeepsACode(): void
    {
        $this->boot();
        $this->enable('acme', ['ana']);
        $this->actAs('ana', 'clock', 'export');
        $this->workDay();
        $this->installer()->uninstall($this->connection());
        $this->connection()->executeStatement("UPDATE nowo_working_time_revision SET reason = 'x' WHERE seq = 1");
        $exports = $this->service(RegisterExportServiceInterface::class);
        $export  = $exports->request(new WorkerRef('ana'), null, '2026-11', '2026-11', ExportFormat::Csv);
        self::assertSame(ExportStatus::Ready, $export->getStatus());
        self::assertContains('working_time.chain_broken', $this->recorder()->auditKeys());

        $this->connection()->executeStatement('DROP TABLE nowo_working_time_export_file');
        $failed = $exports->request(new WorkerRef('ana'), null, '2026-11', '2026-11', ExportFormat::Pdf);
        self::assertSame(ExportStatus::Failed, $failed->getStatus());
        self::assertNotNull($failed->getErrorCode());
    }

    public function testNoticesWaitForTheHoursAndAreDeliveredOnce(): void
    {
        $this->boot();
        $this->enable('acme', ['ana', 'mia'], new RegisterSettingsInput(enabled: true, remindersAllowed: true));
        $this->ctx()->member('acme', 'ana', 'Ana', [], 'ana@example.test');
        $this->ctx()->manages['acme']['mia'] = ['ana'];
        $this->ctx()->grant('acme', 'mia', 'manage_team');
        $this->ctx()->actor = 'mia';
        $this->service(ScheduleServiceInterface::class)->assign(ScheduleScope::Tenant, null, new LocalDate('2026-11-18'), new ScheduleDefinition('UTC', [['day' => 3, 'from' => '09:00', 'to' => '17:00'], ['day' => 4, 'from' => '09:00', 'to' => '17:00'], ['day' => 1, 'from' => '09:00', 'to' => '17:00'], ['day' => 2, 'from' => '09:00', 'to' => '17:00'], ['day' => 5, 'from' => '09:00', 'to' => '17:00']]));
        $this->actAs('ana', 'clock');
        $this->service(WorkerPreferences::class)->update(true, true);
        $this->workDay();
        $this->clock()->modify('2026-11-18 20:00:00');
        $this->service(CorrectionServiceInterface::class)->request(new LocalDate('2026-11-18'), new CorrectionInput(CorrectionOperation::Add, null, SegmentType::Work, new DateTimeImmutable('2026-11-18 18:00:00 UTC'), new DateTimeImmutable('2026-11-18 19:00:00 UTC'), 'Stayed for the release'));
        $dispatcher = $this->service(NoticeDispatcher::class);

        self::assertSame(0, $dispatcher->run()['sent'], 'outside the manager\'s hours');
        $this->clock()->modify('2026-11-19 08:50:00');
        $run = $dispatcher->run();
        self::assertGreaterThanOrEqual(2, $run['sent'], 'the correction notice and the clock-in reminder');
        self::assertSame(0, $dispatcher->run()['sent'], 'once');
        $kinds = array_map(static fn (NoticeMessage $m): string => $m->kind, $this->recorder()->sent);
        self::assertContains('register.correction_requested', $kinds);
        self::assertContains('register.reminder_in', $kinds);

        $this->recorder()->failNotices = true;
        $this->clock()->modify('2026-12-01 09:05:00');
        $failed = $dispatcher->run();
        self::assertGreaterThan(0, $failed['failed']);
        $this->recorder()->failNotices = false;
        self::assertGreaterThan(0, $dispatcher->run()['sent']);
        self::assertContains('register.monthly_summary', array_map(static fn (NoticeMessage $m): string => $m->kind, $this->recorder()->sent));
        $this->clock()->modify('2026-12-01 16:55:00');
        $this->act(ClockAction::ClockIn);
        $dispatcher->run();
        self::assertContains('register.reminder_out', array_map(static fn (NoticeMessage $m): string => $m->kind, $this->recorder()->sent));
        $tester = new CommandTester((new Application($this->kernel))->find('nowo:working-time:notices'));
        self::assertSame(0, $tester->execute([]));
    }

    public function testTheGdprExportCoversWhatTheErasureLeavesAndRemoves(): void
    {
        $this->boot();
        $this->enable('acme', ['ana']);
        $this->actAs('ana', 'clock');
        $this->workDay();
        $this->service(WorkerPreferences::class)->update(false, true);
        $export = $this->service(RegisterExportServiceInterface::class)->request(new WorkerRef('ana'), null, '2026-11', '2026-11', ExportFormat::Csv);
        $data   = $this->service(RegisterPersonalData::class);

        $before = $data->export(new WorkerRef('ana'));
        self::assertSame(RegisterPersonalData::SECTIONS, array_keys($before));
        self::assertCount(1, $before['workdays']);
        self::assertCount(1, $before['exports']);
        self::assertNotNull($before['preferences']);
        self::assertSame([], $data->export(new WorkerRef('ANA'))['workdays'], 'exact references');

        $erased = $data->erase(new WorkerRef('ana'));
        self::assertSame(['preferences' => 1, 'export_files' => 1], $erased['deleted']);
        $after = $data->export(new WorkerRef('ana'));
        self::assertNull($after['preferences']);
        self::assertSame($before['workdays'], $after['workdays'], 'the register is kept (art. 17.3.b)');
        self::assertContains('working_time.personal_data_erased', $this->recorder()->auditKeys());
        $this->expectException(RegisterUnavailableException::class);
        $this->service(RegisterExportServiceInterface::class)->open($export->getId());
    }

    /**
     * @return array<string, string>
     */
    private function zipEntries(string $bytes): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'wt-zip-');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive();
        $zip->open($path);
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name           = (string) $zip->getNameIndex($i);
            $entries[$name] = (string) $zip->getFromName($name);
        }
        $zip->close();
        unlink($path);

        return $entries;
    }
}
