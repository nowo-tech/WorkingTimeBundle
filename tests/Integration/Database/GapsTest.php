<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Database;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Nowo\TimeTrackBundle\Workday\WorkdayAction;
use Nowo\TimeTrackBundle\Workday\WorkdayConflictException;
use Nowo\TimeTrackBundle\Workday\WorkdayForbiddenException;
use Nowo\TimeTrackBundle\Workday\WorkdaySourceInterface;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\WorkingTimeBundle\Application\Register\Notice\NoticeDispatcher;
use Nowo\WorkingTimeBundle\Application\Register\Notice\NoticeOutbox;
use Nowo\WorkingTimeBundle\Application\Register\Port\ExportGeneratorInterface;
use Nowo\WorkingTimeBundle\Application\Register\Port\RegisterStoreInterface;
use Nowo\WorkingTimeBundle\Application\Register\RegisterPersonalData;
use Nowo\WorkingTimeBundle\Application\Register\RegisterPurger;
use Nowo\WorkingTimeBundle\Application\Register\WorkerPreferences;
use Nowo\WorkingTimeBundle\Bridge\UserKit\UserKitRegisterEraser;
use Nowo\WorkingTimeBundle\Bridge\UserKit\UserKitRegisterExportProvider;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ClockAction;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ClockStatus;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\CorrectionOperation;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ExportFormat;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ScheduleScope;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentType;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\ClockConflictException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\CorrectionRefusedException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\ExportRefusedException;
use Nowo\WorkingTimeBundle\Domain\Register\Model\CorrectionInput;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterNotice;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettings;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettingsInput;
use Nowo\WorkingTimeBundle\Domain\Register\Model\ScheduleDefinition;
use Nowo\WorkingTimeBundle\Domain\Register\Service\ChainVerifierInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Service\CorrectionServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Service\RegisterExportServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Service\ScheduleServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\GroupRef;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_column;

/**
 * The remaining rules: the clock's race replay (a racing writer's link refuses ours: one more attempt, then 409), the
 * task-timer link (a failure never undoes the register), the TimeTrack workday source, group exports, a second
 * generator, notices to non-members, store edge cases.
 */
final class GapsTest extends UiTestCase
{
    public function testARacingWriterIsReplayedOnceThenAnswered409(): void
    {
        $this->boot();
        $this->enable();
        $this->actAs('ana', 'clock');
        $this->ctx()->races = 1;
        self::assertSame(ClockStatus::Working, $this->act(ClockAction::ClockIn)->status, 'replayed against the new state');
        $this->ctx()->races = 2;
        try {
            $this->act(ClockAction::BreakStart, 30);
            self::fail('Two races in a row.');
        } catch (ClockConflictException $e) {
            self::assertSame(ClockConflictException::CONCURRENT, $e->reason);
        }
    }

    public function testTheTaskTimerLinkIsCalledAndItsFailureNeverUndoesTheRegister(): void
    {
        $this->boot();
        $this->enable(input: new RegisterSettingsInput(enabled: true, taskTimerLink: true));
        $this->actAs('ana', 'clock');
        $this->act(ClockAction::ClockIn);
        $this->act(ClockAction::BreakStart, 60);
        self::assertCount(1, $this->recorder()->timers);
        $this->act(ClockAction::BreakEnd, 30);
        $this->recorder()->failTimer = true;
        self::assertSame(ClockStatus::Closed, $this->act(ClockAction::ClockOut, 60)->status);
    }

    public function testTheTimeTrackWorkdaySourceClocksThroughTheRegister(): void
    {
        $this->boot();
        $this->enable(input: new RegisterSettingsInput(enabled: true, remindersAllowed: true));
        $this->actAs('ana', 'clock');
        $source = $this->kernel->getContainer()->get('nowo_working_time.time_track.workday_source');
        self::assertInstanceOf(WorkdaySourceInterface::class, $source);
        $user = TimeTrackUserRef::ofId('App\User', 'ana', 'ana');

        self::assertSame('idle', $source->todayFor($user)?->status);
        $state = $source->actFor($user, WorkdayAction::ClockIn, null, 'k1', ClientType::Desktop);
        self::assertSame(['break-start', 'clock-out'], $state->allowedActions);
        self::assertSame('desktop', $state->segments[0]['source']);
        $this->service(WorkerPreferences::class)->update(true, true);
        $this->clock()->modify('+5 minutes');
        $source->actFor($user, WorkdayAction::BreakStart, null, 'k2', ClientType::Extension);
        $source->actFor($user, WorkdayAction::BreakEnd, null, 'k3', ClientType::Web);
        self::assertNotNull($source->todayFor($user));
        try {
            $source->actFor($user, WorkdayAction::ClockIn, null, 'k4', ClientType::Web);
            self::fail('conflict');
        } catch (WorkdayConflictException $e) {
            self::assertSame('working', $e->state->status);
        }
        try {
            $source->actFor($user, WorkdayAction::ClockOut, null, str_repeat('x', 90), ClientType::Web);
            self::fail('bad key');
        } catch (WorkdayForbiddenException) {
        }
        // Audit ST: the user TimeTrack names must be the signed-in worker (by id or by identifier).
        self::assertNotNull($source->todayFor(TimeTrackUserRef::ofId('App\User', '42', 'ana')));
        $bob = TimeTrackUserRef::ofId('App\User', 'bob', 'bob');
        self::assertNull($source->todayFor($bob));
        try {
            $source->actFor($bob, WorkdayAction::ClockOut, null, 'k5', ClientType::Web);
            self::fail('another user');
        } catch (WorkdayForbiddenException) {
        }
        self::assertSame('working', $source->todayFor($user)->status, 'nothing was recorded for the other user');
        $this->ctx()->actor = 'nobody';
        self::assertNull($source->todayFor($user));
        $this->ctx()->actor                 = 'ana';
        $this->ctx()->grants['acme']['ana'] = [];
        self::assertNull($source->todayFor($user), 'the signed-in worker without the clock permission');
    }

    public function testAGroupExportAndASecondGeneratorAndADisabledFormat(): void
    {
        $this->boot(['register' => ['export' => ['formats' => ['csv']]]]);
        $this->enable('acme', ['ana', 'ins']);
        $this->ctx()->member('acme', 'ana', 'Ana', ['g1']);
        $this->actAs('ana', 'clock');
        $this->workDay();
        $this->actAs('ins', 'export');
        $exports = $this->service(RegisterExportServiceInterface::class);
        $zip     = $exports->request(null, new GroupRef('g1'), '2026-11', '2026-11', ExportFormat::Zip);
        self::assertSame('ready', $zip->getStatus()->value);
        self::assertFalse($this->service(ExportGeneratorInterface::class)->generate($zip->getId()), 'already claimed');
        $this->expectException(ExportRefusedException::class);
        $exports->request(new WorkerRef('ana'), null, '2026-11', '2026-11', ExportFormat::Pdf);
    }

    public function testRecordedCorrectionsInTheFutureAndPurgesOfTenantsWithoutOldMonths(): void
    {
        $this->boot();
        $this->enable('acme', ['ana', 'mia']);
        $this->enable('beta', ['bob']);
        $this->actAs('mia', 'manage_team');
        try {
            $this->service(CorrectionServiceInterface::class)->record(new WorkerRef('ana'), new LocalDate('2026-11-30'), new CorrectionInput(CorrectionOperation::Void, 'x', null, null, null, 'A future day'));
            self::fail('future');
        } catch (CorrectionRefusedException $e) {
            self::assertSame('correction.error.future', $e->key);
        }
        $result = $this->service(RegisterPurger::class)->purge();
        self::assertSame(0, $result['tenants']['beta']['workdays']);
    }

    public function testNoticesSkipNonMembersAndInactiveWorkersAndMonthsWithoutWorkingDays(): void
    {
        $this->boot();
        $this->enable('acme', ['ana'], new RegisterSettingsInput(enabled: true, remindersAllowed: true));
        $this->ctx()->members['acme']['old'] = new WorkerProfile(new WorkerRef('old'), 'Old', null, null, false);
        $settings                            = $this->service(RegisterStoreInterface::class)->settings(new TenantRef('acme'));
        self::assertInstanceOf(RegisterSettings::class, $settings);
        self::assertFalse($this->service(NoticeOutbox::class)->queue($settings, new WorkerRef('ghost'), RegisterNotice::REMINDER_IN, [], null, 'k', $this->clock()->now()));
        $this->actAs('ana', 'clock');
        $this->service(WorkerPreferences::class)->update(true, true);
        $this->ctx()->grant('acme', 'ana', 'manage_team');
        $this->service(ScheduleServiceInterface::class)->assign(ScheduleScope::Worker, 'ana', new LocalDate('2026-11-18'), new ScheduleDefinition('UTC', [['day' => 3, 'from' => '10:00', 'to' => '18:00']]));
        $this->act(ClockAction::ClockIn);
        self::assertSame(1, $this->service(NoticeDispatcher::class)->run()['queued'], 'clocked in already: no reminder; the first scheduled day: the summary');
        self::assertSame(RegisterNotice::MONTHLY_SUMMARY, $this->text('SELECT kind FROM nowo_working_time_notice'));
    }

    public function testALateRunDefersANoticeAndRangeLessDaysUseTheDefaultHours(): void
    {
        // Audit ST-L2: no schedule at all → the default 09:00-18:00 on weekdays; a late run re-checks the hours.
        $this->boot();
        $this->enable('acme', ['ana'], new RegisterSettingsInput(enabled: true, remindersAllowed: true));
        $settings = $this->service(RegisterStoreInterface::class)->settings(new TenantRef('acme'));
        self::assertInstanceOf(RegisterSettings::class, $settings);
        $outbox     = $this->service(NoticeOutbox::class);
        $dispatcher = $this->service(NoticeDispatcher::class);
        $this->clock()->modify('2026-11-18 12:00:00');
        self::assertTrue($outbox->queue($settings, new WorkerRef('ana'), RegisterNotice::REMINDER_IN, [], null, 'late', $this->clock()->now()));
        $this->em()->flush();
        $this->clock()->modify('2026-11-18 23:00:00');

        self::assertSame(0, $dispatcher->run()['sent'], 'the run came after the hours');
        self::assertStringStartsWith('2026-11-19', $this->text('SELECT deliverable_from FROM nowo_working_time_notice'));
        $this->clock()->modify('2026-11-19 12:00:00');
        self::assertSame(1, $dispatcher->run()['sent']);

        $this->clock()->modify('2026-11-21 12:00:00');
        self::assertTrue($outbox->queue($settings, new WorkerRef('ana'), RegisterNotice::REMINDER_IN, [], null, 'weekend', $this->clock()->now()));
        $this->em()->flush();
        self::assertSame(1, $this->int("SELECT COUNT(*) FROM nowo_working_time_notice WHERE sent_at IS NULL AND deliverable_from >= '2026-11-23'"), 'a weekend without ranges waits for Monday');
    }

    public function testStoreEdgeCases(): void
    {
        $this->boot();
        $store = $this->service(RegisterStoreInterface::class);
        self::assertSame([], $store->segmentsOf([]));
        self::assertSame([], $store->segmentsById([]));
        self::assertSame([], $store->revisionsOf([]));
        self::assertSame([], $store->correctionsOf([]));
        self::assertSame([], $store->tenantWorkdaysBetween(new TenantRef('acme'), new LocalDate('2026-01-01'), new LocalDate('2026-01-02'), []));
        self::assertSame([], $store->pendingCorrections(new TenantRef('acme'), []));
        $notice = RegisterNotice::queue('01900000-0000-7000-8000-000000000001', 'acme', 'ana', 'k', [], null, 'dedupe', new DateTimeImmutable(), new DateTimeImmutable());
        self::assertTrue($store->addNotice($notice));
        $this->em()->flush();
        self::assertFalse($store->addNotice(RegisterNotice::queue('01900000-0000-7000-8000-000000000002', 'acme', 'ana', 'k', [], null, 'dedupe', new DateTimeImmutable(), new DateTimeImmutable())));
        $store->removePreference(new WorkerRef('nobody'));
        self::assertTrue($this->service(ChainVerifierInterface::class)->verify()->isIntact());
    }

    public function testTheGdprExportListsCorrectionRequests(): void
    {
        $this->boot();
        $this->enable('acme', ['ana', 'mia']);
        $this->actAs('ana', 'clock');
        $this->workDay();
        $segment = $this->text('SELECT id FROM nowo_working_time_segment ORDER BY started_at');
        $this->service(CorrectionServiceInterface::class)->request(new LocalDate('2026-11-18'), new CorrectionInput(CorrectionOperation::Change, $segment, null, new DateTimeImmutable('2026-11-18 09:30:00 UTC'), null, 'Arrived earlier'));
        $export = $this->service(RegisterPersonalData::class)->export(new WorkerRef('ana'));
        self::assertSame('pending', $export['corrections'][0]['status']);
        self::assertSame('Arrived earlier', $export['corrections'][0]['reason']);
        // Audit ST-L4: a correction a manager records keeps its reason in the worker's own export.
        $this->actAs('mia', 'manage_team');
        $this->service(CorrectionServiceInterface::class)->record(new WorkerRef('ana'), new LocalDate('2026-11-18'), new CorrectionInput(CorrectionOperation::Change, $segment, null, new DateTimeImmutable('2026-11-18 09:15:00 UTC'), null, 'Badge reader was down'));
        $this->actAs('ana', 'clock');
        $export = $this->service(RegisterPersonalData::class)->export(new WorkerRef('ana'));
        $trail  = $export['workdays'][0]['trail'] ?? [];
        self::assertIsArray($trail);
        self::assertContains('Badge reader was down', array_column($trail, 'reason'));
        $this->service(UserKitRegisterEraser::class)->erase(new ErasureRequest(new InMemoryUser('ana', null)));
        self::assertContains('working_time.personal_data_erased', $this->recorder()->auditKeys());
        self::assertIsArray($this->service(UserKitRegisterExportProvider::class)->export(new InMemoryUser('ana', null)));
    }

    public function testTeamListsAndTheUiEdgeCases(): void
    {
        $this->boot(['ui' => ['page_size' => 10]]);
        $workers = ['ana', 'mia'];
        for ($i = 0; $i < 42; ++$i) {
            $workers[] = 'w' . $i;
        }
        $this->enable('acme', $workers);
        $this->ctx()->groups['acme'] = [[new GroupRef('g1'), 'Group one']];
        $this->actAs('ana', 'clock');
        $this->act(ClockAction::ClockIn);
        $this->act(ClockAction::BreakStart, 60);
        $this->service(CorrectionServiceInterface::class)->request(new LocalDate('2026-11-18'), new CorrectionInput(CorrectionOperation::Add, null, SegmentType::Work, new DateTimeImmutable('2026-11-18 07:00:00 UTC'), new DateTimeImmutable('2026-11-18 08:00:00 UTC'), 'Early start not clocked'));
        $this->ctx()->actor = 'mia';
        $this->ctx()->grant('acme', 'mia', 'manage_team', 'clock');
        $this->ctx()->grants['acme']['mia'][] = 'manage_team';
        $team                                 = $this->client->request('GET', '/working-time/team');
        self::assertStringContainsString('On a break', $team->text());
        self::assertCount(40, $team->filter('tbody tr'));

        $this->ctx()->members['acme']['gone'] = new WorkerProfile(new WorkerRef('gone'), 'Gone', null, null, false);
        $this->ctx()->actor                   = 'ana';
        $this->ctx()->grant('acme', 'ana', 'manage_team');
        $own                = $this->client->request('GET', '/working-time/team');
        $pending            = $this->text('SELECT id FROM nowo_working_time_correction');
        $this->ctx()->actor = 'mia';
        $form               = $this->client->request('GET', '/working-time/team')->filter('form[data-testid="wt-decide-' . $pending . '"]')->form();
        $this->ctx()->actor = 'ana';
        $this->client->submit($form);
        self::assertSame(303, $this->client->getResponse()->getStatusCode(), 'own request: refused and flashed');
        self::assertSame('pending', $this->text('SELECT status FROM nowo_working_time_correction'));
        self::assertCount(0, $own->filter('[data-testid^="wt-pending-"] form'), 'never one\'s own request');
        $this->client->request('POST', '/working-time/date/2026-11-18/correction', []);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $page = $this->client->request('GET', '/working-time/date/2026-11-17');
        $this->client->submit($page->filter('[data-testid="wt-correction-form"] form')->form(), ['wt_correction[operation]' => 'add', 'wt_correction[segmentType]' => 'work', 'wt_correction[start]' => '22:00', 'wt_correction[end]' => '06:00', 'wt_correction[reason]' => 'Night shift not clocked']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode(), 'an end before the start is the next day');

        $schedules = $this->client->request('GET', '/working-time/schedules');
        $form      = $schedules->filter('[data-testid="wt-schedule-form"] form')->form();
        $this->client->submit($form, ['wt_schedule[scope]' => 'group', 'wt_schedule[group]' => 'g1', 'wt_schedule[validFrom]' => '2026-11-18', 'wt_schedule[timezone]' => 'UTC', 'wt_schedule[dailyTarget]' => '420']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $schedules = $this->client->request('GET', '/working-time/schedules');
        self::assertStringContainsString('420 min/day', $schedules->text());
        $this->service(ScheduleServiceInterface::class)->assign(ScheduleScope::Tenant, null, new LocalDate('2026-11-19'), new ScheduleDefinition('UTC'));
        $schedules = $this->client->request('GET', '/working-time/schedules');
        $withdraw  = $schedules->filter('form[data-testid="wt-withdraw"]')->form();
        $this->clock()->modify('+2 days');
        $this->client->submit($withdraw);
        self::assertSame(303, $this->client->getResponse()->getStatusCode(), 'no longer future: flashed');
        self::assertSame(2, $this->int('SELECT COUNT(*) FROM nowo_working_time_schedule'));

        $this->ctx()->grant('acme', 'ana', 'manage_settings');
        $settings = $this->client->request('GET', '/working-time/settings');
        $form     = $settings->filter('form')->reduce(static fn ($node): bool => $node->filter('[name="wt_settings[version]"]')->count() > 0)->form();
        $this->client->submit($form, ['wt_settings[ipEvidence]' => true, 'wt_settings[ipNotice]' => 'We keep the /24 prefix.']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $settings = $this->client->request('GET', '/working-time/settings');
        self::assertStringContainsString('We keep the /24 prefix.', $settings->html());
    }
}
