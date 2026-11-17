<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Bridge;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;
use Nowo\TimeTrackBundle\Repository\ActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\WorkingTimeBundle\Application\Register\RegisterPersonalData;
use Nowo\WorkingTimeBundle\Bridge\TimeTrack\TimeTrackTaskTimerLink;
use Nowo\WorkingTimeBundle\Bridge\TimeTrack\TimeTrackWorkdaySource;
use Nowo\WorkingTimeBundle\Bridge\UserKit\IdentifierWorkerOfUser;
use Nowo\WorkingTimeBundle\Bridge\UserKit\UserKitRegisterEraser;
use Nowo\WorkingTimeBundle\Bridge\UserKit\UserKitRegisterExportProvider;
use Nowo\WorkingTimeBundle\Domain\Port\WorkerDirectoryInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ClockStatus;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\RevisionKind;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentSource;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentType;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkdayRevision;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkSegment;
use Nowo\WorkingTimeBundle\Domain\Register\Read\ClockState;
use Nowo\WorkingTimeBundle\Domain\Register\Read\DayRecordBuilder;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Tests\Unit\Support\Fx;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The UserKit bridge maps a user to a worker by identifier and delegates to RegisterPersonalData; the TimeTrack task-timer
 * link stops only the signed-in worker's timer.
 */
final class BridgesTest extends TestCase
{
    public function testTheUserKitBridgeMapsUsersToWorkers(): void
    {
        $map = new IdentifierWorkerOfUser();
        self::assertSame('ana', $map->workerOf(new InMemoryUser('ana', null))?->value);
        self::assertSame('old', $map->workerOf(new InMemoryUser('new', null), 'old')?->value);
        self::assertNull($map->workerOf(new stdClass()));
        self::assertNull($map->workerOf(new stdClass(), ''));

        $data     = (new ReflectionClass(RegisterPersonalData::class))->newInstanceWithoutConstructor();
        $provider = new UserKitRegisterExportProvider($data, $map);
        $eraser   = new UserKitRegisterEraser($data, $map);
        self::assertSame('working_time', $provider->getSectionKey());
        self::assertSame('working_time', $eraser->getKey());
        self::assertTrue($provider->supports(new InMemoryUser('ana', null)));
        self::assertFalse($eraser->supports(new stdClass()));
        self::assertNull($provider->export(new stdClass()));
        $eraser->erase(new ErasureRequest(new stdClass()));
        self::assertNull($map->workerOf(new stdClass()), 'no worker: nothing erased');
    }

    public function testVoidedSegmentsAreNotSentToTheClients(): void
    {
        $workday = Fx::workday();
        $segment = WorkSegment::start(Fx::uuid(5), $workday, SegmentType::Work, new DateTimeImmutable('2026-11-10 08:00:00 UTC'), SegmentSource::Web, new DateTimeImmutable('2026-11-10 08:00:00 UTC'), null);
        $segment->close(new DateTimeImmutable('2026-11-10 09:00:00 UTC'), SegmentSource::Web, new DateTimeImmutable('2026-11-10 09:00:00 UTC'), null);
        $void   = WorkdayRevision::append(Fx::uuid(6), 1, str_repeat('0', 64), $workday, RevisionKind::Correction, $segment->getId(), ['op' => 'void', 'segment' => $segment->getId()], 'Mistake made', null, 'Mia', new DateTimeImmutable('2026-11-10 10:00:00 UTC'), null);
        $record = DayRecordBuilder::build($workday, [$segment], [$void], [], new DateTimeImmutable('2026-11-10 11:00:00 UTC'));
        $state  = new ClockState(ClockStatus::Closed, $workday->date(), 'UTC', $record, new DateTimeImmutable('2026-11-10 11:00:00 UTC'));

        self::assertSame([], TimeTrackWorkdaySource::toWorkdayState($state)->segments);
    }

    public function testTheTimerLinkStopsOnlyTheSignedInWorkersTimer(): void
    {
        $timers = $this->createMock(ActiveTimerRepositoryInterface::class);
        $user   = new class implements UserInterface {
            public function getId(): string
            {
                return 'ana';
            }

            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'ana';
            }
        };
        $active = new ActiveTimer($user, 'task-1', 'Task', null, ClientType::Web);
        $timers->expects(self::exactly(3))->method('findByUserId')->willReturnOnConsecutiveCalls(null, $active, null);
        $service   = new TimerService($this->createStub(TaskProviderInterface::class), $timers, $this->createStub(TimeEntryRepositoryInterface::class), $this->createStub(EventDispatcherInterface::class));
        $tokens    = new TokenStorage();
        $directory = $this->createStub(WorkerDirectoryInterface::class);
        $directory->method('current')->willReturn(new WorkerRef('ana'));
        $link = new TimeTrackTaskTimerLink($service, $tokens, $directory);

        $link->stopRunning(new TenantRef('t'), new WorkerRef('ana'), new DateTimeImmutable());
        $tokens->setToken(new UsernamePasswordToken($user, 'main'));
        $link->stopRunning(new TenantRef('t'), new WorkerRef('bob'), new DateTimeImmutable());
        $link->stopRunning(new TenantRef('t'), new WorkerRef('ana'), new DateTimeImmutable());
        $link->stopRunning(new TenantRef('t'), new WorkerRef('ana'), new DateTimeImmutable());
    }
}
