<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Bridge\TimeTrack;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Nowo\TimeTrackBundle\Workday\WorkdayAction;
use Nowo\TimeTrackBundle\Workday\WorkdayConflictException;
use Nowo\TimeTrackBundle\Workday\WorkdayForbiddenException;
use Nowo\TimeTrackBundle\Workday\WorkdaySourceInterface;
use Nowo\TimeTrackBundle\Workday\WorkdayState;
use Nowo\WorkingTimeBundle\Application\Register\Notice\HoursWindow;
use Nowo\WorkingTimeBundle\Application\Register\Port\RegisterStoreInterface;
use Nowo\WorkingTimeBundle\Application\Register\RegisterAccess;
use Nowo\WorkingTimeBundle\Application\Register\ScheduleResolver;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ClockAction;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentSource;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\ClockConflictException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\InvalidRequestKeyException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\PermissionDeniedException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\RegisterUnavailableException;
use Nowo\WorkingTimeBundle\Domain\Register\Read\ClockState;
use Nowo\WorkingTimeBundle\Domain\Register\Service\RegisterClockInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\RequestKey;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkingTimePermission;
use Symfony\Component\HttpFoundation\RequestStack;

use function array_map;

use const DATE_ATOM;

/**
 * TimeTrack bridge (contract F11 / section 8.3, spec 198 FR-013): the desktop app and the browser extension clock in
 * through TimeTrackBundle's workday API (`/api/time-track/v1/workday…`) with the web's rules — the signed-in worker of
 * the current tenant, `clock` granted, the same {@see RegisterClockInterface}. The client's idempotency key is stored
 * as `api:<key>`. `reminder` (`in` / `out`) is set only inside ± the window of the schedule, for workers who opted in,
 * in a tenant that allows reminders.
 */
final readonly class TimeTrackWorkdaySource implements WorkdaySourceInterface
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private RegisterClockInterface $clock,
        private RegisterAccess $access,
        private RegisterStoreInterface $store,
        private ScheduleResolver $schedules,
        private ?RequestStack $requests = null,
        private int $windowMinutes = 15,
    ) {
    }

    /**
     * Today's state of the signed-in worker, null when the register is not there for them.
     */
    public function todayFor(TimeTrackUserRef $user): ?WorkdayState
    {
        try {
            return self::toWorkdayState($this->withReminder($this->clock->state()));
        } catch (RegisterUnavailableException|PermissionDeniedException) {
            return null;
        }
    }

    /**
     * Records a client's clock action.
     */
    public function actFor(TimeTrackUserRef $user, WorkdayAction $action, ?DateTimeImmutable $occurredAt, string $idempotencyKey, ClientType $client): WorkdayState
    {
        try {
            $state = $this->clock->act(null, ClockAction::from($action->value), $occurredAt, RequestKey::api($idempotencyKey), self::source($client), $this->requests?->getMainRequest()?->getClientIp());
        } catch (ClockConflictException $e) {
            throw new WorkdayConflictException(self::toWorkdayState($e->state));
        } catch (RegisterUnavailableException|PermissionDeniedException|InvalidRequestKeyException) {
            throw new WorkdayForbiddenException();
        }

        return self::toWorkdayState($state);
    }

    /**
     * The TimeTrack shape of a clock state.
     */
    public static function toWorkdayState(ClockState $state): WorkdayState
    {
        $segments = [];
        foreach ($state->record->segments ?? [] as $segment) {
            if ($segment->voided) {
                continue;
            }
            $segments[] = [
                'type'   => $segment->type->value,
                'start'  => $segment->start->format(DATE_ATOM),
                'end'    => $segment->end?->format(DATE_ATOM),
                'source' => $segment->startSource->value,
            ];
        }

        return new WorkdayState(
            $state->status->value,
            $state->date->value,
            $state->timezone,
            $segments,
            $state->workedSeconds(),
            $state->breakSeconds(),
            $state->record?->flagValues() ?? [],
            $state->reminder,
            array_map(static fn (ClockAction $action): string => $action->value, $state->allowedActions()),
        );
    }

    private function withReminder(ClockState $state): ClockState
    {
        $context = $this->access->context(WorkingTimePermission::Clock);
        if (!$context->settings->remindersAllowed() || $this->store->preference($context->worker())?->wantsReminders() !== true) {
            return $state;
        }
        [$date, $schedule] = $this->schedules->at($context->settings, $context->subject, $state->now);

        return $state->withReminder(HoursWindow::reminder($date, $schedule, $state->now, $this->windowMinutes));
    }

    private static function source(ClientType $client): SegmentSource
    {
        return match ($client) {
            ClientType::Desktop   => SegmentSource::Desktop,
            ClientType::Extension => SegmentSource::Extension,
            ClientType::Web       => SegmentSource::Web,
        };
    }
}
