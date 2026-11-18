<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Database;

use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\WorkingTimeBundle\Application\Leave\LeavePersonalData;
use Nowo\WorkingTimeBundle\Application\Leave\StaleLeaveSettingsException;
use Nowo\WorkingTimeBundle\Application\Shared\WriteConflictException;
use Nowo\WorkingTimeBundle\Bridge\UserKit\UserKitLeaveEraser;
use Nowo\WorkingTimeBundle\Bridge\UserKit\UserKitLeaveExportProvider;
use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\HolidayScope;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayCalendarInput;
use Nowo\WorkingTimeBundle\Domain\Holiday\Service\HolidayCalendarServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\AllowanceLineKind;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\AllowanceSource;
use Nowo\WorkingTimeBundle\Domain\Leave\Enum\LeaveUnit;
use Nowo\WorkingTimeBundle\Domain\Leave\Exception\LeaveRefusedException;
use Nowo\WorkingTimeBundle\Domain\Leave\LeaveSettingsInput;
use Nowo\WorkingTimeBundle\Domain\Leave\LeaveTypeInput;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\AllowanceLine;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\AllowancePolicy;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\LeaveType;
use Nowo\WorkingTimeBundle\Domain\Leave\Service\LeaveAdministrationInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ScheduleScope;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Http\WorkingTimeConflictFormatter;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Throwable;

use function array_keys;
use function file_put_contents;
use function str_repeat;
use function sys_get_temp_dir;
use function tempnam;

/**
 * The Low findings of audit TD (PR #5): nobody adjusts their own allowance (Info), no adjustment below zero (L5), a
 * stale type or calendar edit is a 409 (L6), the leave half of the GDPR export and erasure (L7), the health hint on
 * reasons and notes (L8) and the upload size checked before the file is read (L9).
 */
final class AuditTdTest extends UiTestCase
{
    public function testAllowanceRulesOnThePeopleAndTheAmounts(): void
    {
        $admin    = $this->leaveOn();
        $vacation = $this->vacation();
        $this->refused('leave.allowance.error.self', static fn (): AllowanceLine => $admin->addLine($vacation->getId(), new WorkerRef('hr'), 2026, AllowanceLineKind::Adjustment, 2, 'For myself'));
        $this->refused('leave.allowance.error.self', static fn (): AllowancePolicy => $admin->addPolicy($vacation->getId(), ScheduleScope::Worker, 'hr', 2026, 60));
        $admin->addLine($vacation->getId(), new WorkerRef('ana'), 2026, AllowanceLineKind::Adjustment, -44, 'Taken in another system');
        $this->refused('leave.allowance.error.negative', static fn (): AllowanceLine => $admin->addLine($vacation->getId(), new WorkerRef('ana'), 2026, AllowanceLineKind::Adjustment, -1, 'One too many'));
        $this->refused('leave.allowance.error.too_large', static fn (): AllowanceLine => $admin->addLine($vacation->getId(), new WorkerRef('ana'), 2026, AllowanceLineKind::Adjustment, 100_000, 'Overflow'));
        self::assertCount(1, $admin->lines(new WorkerRef('ana'), 2026));
    }

    public function testAStaleTypeEditIsA409(): void
    {
        $admin = $this->leaveOn();
        $type  = $admin->saveType(null, new LeaveTypeInput('Training', true, AllowanceSource::Allowance, LeaveUnit::Hours));
        self::assertSame(1, $type->getVersion());
        $admin->saveType($type->getId(), new LeaveTypeInput('Training v2', true, AllowanceSource::Allowance, LeaveUnit::Hours), 1);
        $this->conflict('leave_type_stale', static fn (): LeaveType => $admin->saveType($type->getId(), new LeaveTypeInput('Training v3', true, AllowanceSource::Allowance, LeaveUnit::Hours), 1));
        $this->ctx()->bumpVersions = true;
        $this->conflict('leave_type_stale', static fn (): LeaveType => $admin->saveType($type->getId(), new LeaveTypeInput('Training v4', true, AllowanceSource::Allowance, LeaveUnit::Hours), 2));

        $page  = $this->client->request('GET', '/leave/manage/types/' . $type->getId());
        $stale = $page->filter('[data-testid="wt-leave-type-form"] form')->form();
        $this->client->submit($page->filter('[data-testid="wt-leave-type-form"] form')->form(), ['wt_leave_type[name]' => 'Training v5']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $this->client->submit($stale, ['wt_leave_type[name]' => 'Training v6']);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->client->getResponse()->headers->has('Retry-After'));
    }

    public function testAStaleCalendarEditIsA409(): void
    {
        $this->leaveOn();
        $holidays = $this->service(HolidayCalendarServiceInterface::class);
        $calendar = $holidays->saveCalendar(null, new HolidayCalendarInput('Madrid', HolidayScope::Local, 2026));
        $holidays->saveCalendar($calendar->getId(), new HolidayCalendarInput('Madrid city', HolidayScope::Local, 2026), 1);
        $this->conflict('holiday_calendar_stale', static fn () => $holidays->saveCalendar($calendar->getId(), new HolidayCalendarInput('Old', HolidayScope::Local, 2026), 1));
        $this->ctx()->bumpVersions = true;
        $this->conflict('holiday_calendar_stale', static fn () => $holidays->saveCalendar($calendar->getId(), new HolidayCalendarInput('Racing', HolidayScope::Local, 2026), 2));

        $page  = $this->client->request('GET', '/holidays/' . $calendar->getId());
        $stale = $page->filter('[data-testid="wt-holiday-calendar-form"] form')->form();
        $this->client->submit($page->filter('[data-testid="wt-holiday-calendar-form"] form')->form(), ['wt_holiday_calendar[name]' => 'Madrid v4']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $this->client->submit($stale, ['wt_holiday_calendar[name]' => 'Madrid v5']);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/holidays/' . $calendar->getId(), [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->client->submit($stale, ['wt_holiday_calendar[name]' => 'Madrid v6'], ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame('{"error":"holiday_calendar_stale"}', $this->client->getResponse()->getContent());
    }

    public function testTheLeaveHalfOfTheGdprExportAndErasure(): void
    {
        $admin    = $this->leaveOn();
        $vacation = $this->vacation();
        $admin->addLine($vacation->getId(), new WorkerRef('ana'), 2026, AllowanceLineKind::Adjustment, 2, 'Moved from 2025');
        $admin->addPolicy($vacation->getId(), ScheduleScope::Worker, 'ana', 2026, 60, false, 'Senior contract');
        $this->actAs('ana', 'leave_manage');
        $admin->addLine($vacation->getId(), new WorkerRef('bea'), 2026, AllowanceLineKind::Adjustment, 2, 'Added by Ana');
        $data   = $this->service(LeavePersonalData::class);
        $export = $data->export(new WorkerRef('ana'));

        self::assertSame(LeavePersonalData::SECTIONS, array_keys($export));
        self::assertSame('Moved from 2025', $export['allowance_lines'][0]['reason']);
        self::assertSame('Senior contract', $export['policies'][0]['note']);
        self::assertSame([], $data->export(new WorkerRef('nobody'))['allowance_lines']);
        $provider = $this->service(UserKitLeaveExportProvider::class);
        $eraser   = $this->service(UserKitLeaveEraser::class);
        self::assertSame('working_time_leave', $provider->getSectionKey());
        self::assertSame('working_time_leave', $eraser->getKey());
        self::assertTrue($provider->supports(new InMemoryUser('ana', null)));
        self::assertTrue($eraser->supports(new InMemoryUser('ana', null)));
        self::assertIsArray($provider->export(new InMemoryUser('ana', null)));
        self::assertSame('leave_settings_stale', WorkingTimeConflictFormatter::code(new StaleLeaveSettingsException()));
        $this->service(UserKitLeaveEraser::class)->erase(new ErasureRequest(new InMemoryUser('ana', null)));

        $after = $data->export(new WorkerRef('ana'));
        self::assertSame(AllowanceLine::ERASED, $after['allowance_lines'][0]['reason']);
        self::assertSame(2, $after['allowance_lines'][0]['amount'], 'the amounts hold the balance until retention');
        self::assertNull($after['policies'][0]['note']);
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_working_time_leave_allowance_line WHERE created_by_id = ?', ['ana']), 'Ana is forgotten as the creator of Bea\'s line');
        self::assertSame(['erased' => ['reasons' => 0, 'notes' => 0, 'actor_refs' => 0], 'kept' => ['allowance_lines', 'policies']], $data->erase(new WorkerRef('ana')), 'erasing twice changes nothing');
        self::assertContains('working_time.leave_personal_data_erased', $this->recorder()->auditKeys());
    }

    public function testTheHealthHintAndTheUploadSize(): void
    {
        $this->leaveOn(['holidays' => ['providers' => ['file' => ['max_bytes' => 64]]]]);
        $crawler = $this->client->request('GET', '/leave/manage/allowances');
        self::assertStringContainsString('Do not include health information', $crawler->filter('[data-testid="wt-leave-line-form"]')->text());
        self::assertStringContainsString('Do not include health information', $crawler->filter('[data-testid="wt-leave-policy-form"]')->text());
        $calendar = $this->service(HolidayCalendarServiceInterface::class)->saveCalendar(null, new HolidayCalendarInput('Spain', HolidayScope::National, 2026));
        $crawler  = $this->client->request('GET', '/holidays/' . $calendar->getId());
        $file     = (string) tempnam(sys_get_temp_dir(), 'wt-csv');
        file_put_contents($file, "date,name\n" . str_repeat("2026-01-01,Año Nuevo\n", 10));
        $form  = $crawler->filter('[data-testid="wt-holiday-import-form"] form')->form();
        $field = $form['wt_holiday_import[file]'];
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload($file);
        $this->client->submit($form);
        self::assertStringContainsString('UTF-8 text file within the size limit', $this->client->followRedirect()->text(), 'refused by its size, before it is read');
    }

    /**
     * Leave on, `hr` manages leave and holidays; Ana and Bea are workers.
     *
     * @param array<string, mixed> $config
     */
    private function leaveOn(array $config = []): LeaveAdministrationInterface
    {
        $this->boot(['leave' => ['enabled' => true]] + $config);
        $this->enable('acme', ['ana', 'bea', 'hr']);
        $this->actAs('hr', 'leave_manage', 'manage_settings', 'holidays_manage');
        $admin = $this->service(LeaveAdministrationInterface::class);
        $admin->updateSettings(new LeaveSettingsInput(true), 0);

        return $admin;
    }

    private function vacation(): LeaveType
    {
        foreach ($this->service(LeaveAdministrationInterface::class)->types() as $type) {
            if ($type->getSeedCode() === 'vacation') {
                return $type;
            }
        }
        self::fail('vacation');
    }

    private function refused(string $key, callable $call): void
    {
        try {
            $call();
            self::fail($key);
        } catch (LeaveRefusedException $e) {
            self::assertSame($key, $e->key);
        }
    }

    private function conflict(string $code, callable $call): void
    {
        try {
            $call();
            self::fail($code);
        } catch (Throwable $e) {
            self::assertInstanceOf(WriteConflictException::class, $e, $e::class . ': ' . $e->getMessage());
            self::assertSame($code, $e->errorCode);
        }
    }
}
