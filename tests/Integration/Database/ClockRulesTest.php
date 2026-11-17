<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Database;

use DateTimeImmutable;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ClockAction;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ClockStatus;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\DayFlag;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ScheduleScope;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentSource;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\ClockConflictException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\PermissionDeniedException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\RegisterUnavailableException;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettingsInput;
use Nowo\WorkingTimeBundle\Domain\Register\Model\ScheduleDefinition;
use Nowo\WorkingTimeBundle\Domain\Register\Service\RegisterClockInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Service\ScheduleServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\RequestKey;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

use const DATE_ATOM;

/**
 * The clock's rules (spec 198 FR-008 / FR-011, contract sections 9.1 and 15.2): the state machine, idempotent request
 * keys, a client's offline time (late, never earlier than the last), IP evidence as a prefix only, no automatic
 * clock-out (missing clock-out, next clock-in opens a new day), split shifts, the gate.
 */
final class ClockRulesTest extends RegisterTestCase
{
    public function testTheStateMachineRefusesWhatDoesNotFit(): void
    {
        $this->start();
        foreach ([ClockAction::BreakStart, ClockAction::BreakEnd, ClockAction::ClockOut] as $action) {
            try {
                $this->act($action);
                self::fail($action->value . ' must be refused while idle.');
            } catch (ClockConflictException $e) {
                self::assertSame(ClockStatus::Idle, $e->state->status);
                self::assertSame(ClockConflictException::NOT_ALLOWED, $e->reason);
            }
        }
        self::assertSame(ClockStatus::Working, $this->act(ClockAction::ClockIn)->status);
        self::assertSame(ClockStatus::OnBreak, $this->act(ClockAction::BreakStart, 60)->status);
        self::assertSame(ClockStatus::Closed, $this->act(ClockAction::ClockOut, 10)->status, 'clock out from a break');
        self::assertSame(ClockStatus::Working, $this->act(ClockAction::ClockIn, 120)->status, 'split shift');
        self::assertSame(ClockAction::ClockOut, $this->clockService()->state()->primaryAction());
    }

    public function testARepeatedRequestKeyActsOnce(): void
    {
        $this->start();
        $key   = RequestKey::web('same-key');
        $first = $this->clockService()->act(null, ClockAction::ClockIn, null, $key, SegmentSource::Web, null);
        $this->clock()->modify('+1 minute');
        $again = $this->clockService()->act(null, ClockAction::ClockIn, null, $key, SegmentSource::Web, null);

        self::assertSame($first->status, $again->status);
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_working_time_revision'));
    }

    public function testAnOfflineClientTimeIsLateAndNeverEarlierThanTheLast(): void
    {
        $this->start();
        $this->act(ClockAction::ClockIn);
        $this->clock()->modify('+3 hours');
        $state = $this->clockService()->act(null, ClockAction::BreakStart, new DateTimeImmutable('2026-11-18 11:00:00 UTC'), RequestKey::api('offline-1'), SegmentSource::Desktop, null);
        self::assertTrue($state->record?->has(DayFlag::LateEntry));

        try {
            $this->clockService()->act(null, ClockAction::BreakEnd, new DateTimeImmutable('2026-11-18 10:30:00 UTC'), RequestKey::api('offline-2'), SegmentSource::Desktop, null);
            self::fail('Earlier than the last recorded time.');
        } catch (ClockConflictException $e) {
            self::assertSame(ClockConflictException::EARLIER_THAN_LAST, $e->reason);
        }
        $future = $this->clockService()->act(null, ClockAction::BreakEnd, new DateTimeImmutable('2030-01-01 00:00:00 UTC'), RequestKey::api('offline-3'), SegmentSource::Extension, null);
        self::assertSame('2026-11-18T13:00:00+00:00', $future->record?->segments[2]->start->format(DATE_ATOM), 'a future time is the server time');
    }

    public function testIpEvidenceStoresThePrefixOnlyAndOnlyWhenEnabled(): void
    {
        $this->boot();
        $this->enable(input: new RegisterSettingsInput(enabled: true, ipEvidence: true, ipNotices: ['en' => 'We record the network prefix.'], ipNoticeLocale: 'en'));
        $this->actAs('ana', 'clock');
        $this->act(ClockAction::ClockIn, 0, '203.0.113.77');
        $this->act(ClockAction::ClockOut, 60, '2001:db8:1:2:3:4:5:6');

        $row = $this->connection()->fetchAssociative('SELECT start_ip_prefix, end_ip_prefix FROM nowo_working_time_segment');
        self::assertSame(['start_ip_prefix' => '203.0.113.0/24', 'end_ip_prefix' => '2001:db8:1:2::/64'], $row);
        self::assertStringNotContainsString('203.0.113.77', $this->text('SELECT payload FROM nowo_working_time_revision WHERE seq = 1'));

        $this->boot();
        $this->enable();
        $this->actAs('ana', 'clock');
        $this->act(ClockAction::ClockIn, 0, '203.0.113.77');
        self::assertNull($this->connection()->fetchOne('SELECT start_ip_prefix FROM nowo_working_time_segment'));
    }

    public function testAForgottenClockOutIsMissingAndTheNextClockInOpensANewDay(): void
    {
        $this->start();
        $this->act(ClockAction::ClockIn);
        $this->clock()->modify('+17 hours');
        $state = $this->clockService()->state();
        self::assertSame(ClockStatus::Idle, $state->status);
        $next = $this->act(ClockAction::ClockIn);

        self::assertSame('2026-11-19', $next->date->value);
        self::assertSame(2, $this->int('SELECT COUNT(*) FROM nowo_working_time_workday'));
    }

    public function testTheScheduleEndPlusGraceMarksAMissingClockOut(): void
    {
        $this->boot();
        $this->enable(input: new RegisterSettingsInput(enabled: true, taskTimerLink: true));
        $this->ctx()->grant('acme', 'mia', 'manage_team');
        $this->ctx()->member('acme', 'mia', 'Mia');
        $this->ctx()->actor = 'mia';
        $this->service(ScheduleServiceInterface::class)->assign(ScheduleScope::Tenant, null, new LocalDate('2026-11-18'), new ScheduleDefinition('Europe/Madrid', [['day' => 3, 'from' => '09:00', 'to' => '14:00']]));
        $this->actAs('ana', 'clock');
        $this->act(ClockAction::ClockIn);
        $this->act(ClockAction::BreakStart, 30);
        $this->act(ClockAction::BreakEnd, 30);
        $this->clock()->modify('2026-11-18 16:30:00');
        $state = $this->clockService()->state();
        self::assertSame(ClockStatus::Closed, $state->status, 'past 14:00 Madrid + 2 h the open segment no longer counts');
        self::assertTrue($state->record?->has(DayFlag::MissingClockOut));
        self::assertSame(ClockStatus::Working, $this->act(ClockAction::ClockIn)->status, 'the next clock-in opens a segment');
    }

    public function testTheGateAnswers404And403(): void
    {
        $this->boot();
        $this->enable();
        $this->ctx()->actor = 'ana';
        $this->expectException(PermissionDeniedException::class);
        try {
            $this->clockService()->state();
        } finally {
            $this->ctx()->actor = 'nobody';
            try {
                $this->clockService()->state();
                self::fail('Not a member.');
            } catch (RegisterUnavailableException) {
            }
            $this->ctx()->tenant = null;
            try {
                $this->clockService()->state();
                self::fail('No tenant.');
            } catch (RegisterUnavailableException) {
            }
            $this->ctx()->tenant = 'acme';
            $this->ctx()->actor  = 'ana';
            $this->ctx()->grant('acme', 'ana', 'clock', 'manage_team');
            $this->ctx()->member('acme', 'leo');
            try {
                $this->clockService()->state(new WorkerRef('leo'));
                self::fail('Clocking for someone else.');
            } catch (PermissionDeniedException) {
            }
            $this->ctx()->grants = [];
        }
    }

    public function testAnUnavailableRegisterIs404(): void
    {
        $this->boot();
        $this->ctx()->member('acme', 'ana');
        $this->actAs('ana', 'clock');
        $this->expectException(RegisterUnavailableException::class);
        $this->clockService()->state();
    }

    private function start(): void
    {
        $this->boot();
        $this->enable();
        $this->actAs('ana', 'clock');
    }

    private function clockService(): RegisterClockInterface
    {
        return $this->service(RegisterClockInterface::class);
    }
}
