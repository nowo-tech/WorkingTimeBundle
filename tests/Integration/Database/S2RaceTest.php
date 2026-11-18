<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Database;

use Closure;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Nowo\WorkingTimeBundle\Application\Shared\WriteConflictException;
use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\HolidayScope;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayCalendarInput;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayLocation;
use Nowo\WorkingTimeBundle\Domain\Holiday\Model\WorkCentre;
use Nowo\WorkingTimeBundle\Domain\Holiday\Service\HolidayCalendarServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Leave\LeaveSettingsInput;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\LeaveType;
use Nowo\WorkingTimeBundle\Domain\Leave\Service\LeaveAdministrationInterface;
use Nowo\WorkingTimeBundle\Infrastructure\Doctrine\DoctrineTransactionRunner;
use Nowo\WorkingTimeBundle\Tests\Fixtures\RaceOutcome;
use Nowo\WorkingTimeBundle\Tests\Fixtures\RecordedHolidayApi;
use Nowo\WorkingTimeBundle\Tests\Fixtures\TestContext;
use Nowo\WorkingTimeBundle\Tests\Fixtures\TestServices;
use Nowo\WorkingTimeBundle\Tests\Integration\Kernel\Host;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ContainerInterface;

use function array_replace_recursive;
use function getenv;
use function is_bool;
use function is_string;

/**
 * The S2 write paths under contention (audit TD, REQ-RUNTIME-003; docs/CONCURRENCY.md):
 *
 * - M1: two seeds of the leave types store each seed code once (unique (tenant, seed_code); the loser runs once more
 *   and finds them);
 * - M2: two default switches leave exactly one default work centre (one UPDATE over the tenant's centres);
 * - L1: two confirms of the same import store each date once (the loser runs once more and skips them); a second
 *   refusal is a 409 + Retry-After, never a 500.
 *
 * The two-connection tests run a second kernel on its own database connection while the first one's write is under
 * way, before the first takes a row lock the second would wait for in the same process (MySQL, MariaDB, PostgreSQL;
 * the sessions have a 10 s lock wait, so a mistake fails fast); the in-connection tests insert what the other writer commits between our read and our flush,
 * on every database.
 */
final class S2RaceTest extends UiTestCase
{
    public function testTwoSeedsOnTwoConnectionsStoreEachTypeOnce(): void
    {
        if ($this->skipWithoutServer()) {
            return;
        }
        $this->leaveOn(seeded: false);
        $other   = $this->otherKernel(['leave' => ['enabled' => true]]);
        $outcome = new RaceOutcome();
        $this->whileFirstPersists(LeaveType::class, $outcome, static function () use ($other, $outcome): void {
            $admin = $other->get(LeaveAdministrationInterface::class);
            self::assertInstanceOf(LeaveAdministrationInterface::class, $admin);
            $outcome->result = (string) $admin->seedTypes();
        }, function (): void {
            self::assertSame(0, $this->admin()->seedTypes(), 'the second attempt finds the types seeded');
        });

        self::assertSame('13', $outcome->result);
        self::assertSame(13, $this->int('SELECT COUNT(*) FROM nowo_working_time_leave_type'));
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_working_time_leave_policy'), 'one vacation default');
    }

    public function testASeedThatLosesTwiceIsA409AndOnceIsRunAgain(): void
    {
        $this->leaveOn(seeded: false);
        // The racer copies each type we persist (13 per attempt) on our connection, so its rows go with our rollback:
        // 14 races fail both attempts, 13 only the first.
        $this->ctx()->seedRaces = 14;
        try {
            $this->admin()->seedTypes();
            self::fail('409 expected');
        } catch (WriteConflictException $e) {
            self::assertSame('leave_seed_conflict', $e->errorCode);
        }
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_working_time_leave_type'), 'nothing of the losing seed is stored');
        $this->ctx()->seedRaces = 13;
        self::assertSame(13, $this->admin()->seedTypes(), 'run once more');
        self::assertSame(13, $this->int('SELECT COUNT(*) FROM nowo_working_time_leave_type'));
    }

    public function testTwoDefaultSwitchesOnTwoConnectionsLeaveOneDefault(): void
    {
        if ($this->skipWithoutServer()) {
            return;
        }
        [$a, $b, $c] = $this->centres();
        $other       = $this->otherKernel();
        $outcome     = new RaceOutcome();
        $listener    = new class($b, $outcome, static function () use ($other, $c): void {
            $holidays = $other->get(HolidayCalendarServiceInterface::class);
            self::assertInstanceOf(HolidayCalendarServiceInterface::class, $holidays);
            $holidays->saveCentre($c, 'C office', [], true);
        }) {

            public function __construct(private readonly string $b, private readonly RaceOutcome $outcome, private readonly Closure $race)
            {
            }

            public function preUpdate(PreUpdateEventArgs $args): void
            {
                $object = $args->getObject();
                if ($this->outcome->fired || !$object instanceof WorkCentre || $object->getId() !== $this->b) {
                    return;
                }
                $this->outcome->fired = true;
                ($this->race)();
            }
        };
        $this->em()->getEventManager()->addEventListener([Events::preUpdate], $listener);
        try {
            $this->holidays()->saveCentre($b, 'B office (renamed)', [], true);
        } finally {
            $this->em()->getEventManager()->removeEventListener([Events::preUpdate], $listener);
        }

        self::assertTrue($outcome->fired);
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_holiday_work_centre WHERE is_default = ?', [true]));
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_holiday_work_centre WHERE is_default = ? AND id = ?', [true, $b]), 'the last switch wins');
        self::assertNotSame('', $a);
    }

    public function testADefaultSwitchRacingAnotherOneInTheSameConnectionLeavesOneDefault(): void
    {
        [, $b, $c] = $this->centres();
        $outcome   = new RaceOutcome();
        $listener  = new class($b, $c, $outcome, $this) {
            public function __construct(private readonly string $b, private readonly string $c, private readonly RaceOutcome $outcome, private readonly S2RaceTest $test)
            {
            }

            public function preUpdate(PreUpdateEventArgs $args): void
            {
                $object = $args->getObject();
                if ($this->outcome->fired || !$object instanceof WorkCentre || $object->getId() !== $this->b) {
                    return;
                }
                $this->outcome->fired = true;
                // What a concurrent switch to C commits between our read and our flush.
                $this->test->sql('UPDATE nowo_holiday_work_centre SET is_default = ? WHERE id <> ?', [false, $this->c]);
                $this->test->sql('UPDATE nowo_holiday_work_centre SET is_default = ? WHERE id = ?', [true, $this->c]);
            }
        };
        $this->em()->getEventManager()->addEventListener([Events::preUpdate], $listener);
        try {
            $this->holidays()->saveCentre($b, 'B office (renamed)', [], true);
        } finally {
            $this->em()->getEventManager()->removeEventListener([Events::preUpdate], $listener);
        }
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_holiday_work_centre WHERE is_default = ?', [true]));
    }

    public function testTwoConfirmsOnTwoConnectionsStoreEachDateOnce(): void
    {
        if ($this->skipWithoutServer()) {
            return;
        }
        $calendar = $this->calendar();
        $other    = $this->otherKernel();
        $outcome  = new RaceOutcome();
        $madrid   = new HolidayLocation('ES', 'ES-MD', '28079');
        // The other confirm runs while ours asks the provider (before our transaction): ours then waits for nothing,
        // finds the other's days under the calendar lock and adds none. The losing-insert path is the in-connection
        // test below (a racer inside our transaction).
        $api = $this->kernel->getContainer()->get('test.holiday_api');
        self::assertInstanceOf(RecordedHolidayApi::class, $api);
        $api->beforeNext = static function () use ($other, $outcome, $calendar, $madrid): void {
            $holidays = $other->get(HolidayCalendarServiceInterface::class);
            self::assertInstanceOf(HolidayCalendarServiceInterface::class, $holidays);
            $outcome->fired  = true;
            $outcome->result = (string) $holidays->confirmProvider($calendar, 'festivos_io', $madrid, 'es')->added;
        };
        self::assertSame(0, $this->holidays()->confirmProvider($calendar, 'festivos_io', $madrid, 'es')->added, 'the other confirm stored them');
        self::assertTrue($outcome->fired);

        self::assertSame('14', $outcome->result);
        self::assertSame(14, $this->int('SELECT COUNT(*) FROM nowo_holiday_day'));
    }

    public function testAConfirmThatLosesTwiceIsA409AndOnceIsRunAgain(): void
    {
        $calendar              = $this->calendar();
        $this->ctx()->dayRaces = 2;
        try {
            $this->holidays()->confirmFile($calendar, [['date' => '2026-01-06', 'name' => 'Reyes']]);
            self::fail('409 expected');
        } catch (WriteConflictException $e) {
            self::assertSame('holiday_import_conflict', $e->errorCode);
        }
        $this->ctx()->dayRaces = 1;
        self::assertSame(1, $this->holidays()->confirmFile($calendar, [['date' => '2026-03-19', 'name' => 'San José']])->added);
        $this->ctx()->dayRaces = 1;
        self::assertSame(14, $this->holidays()->confirmProvider($calendar, 'festivos_io', new HolidayLocation('ES', 'ES-MD', '28079'), 'es')->added);
        $this->ctx()->dayRaces = 100;
        $page                  = $this->client->request('GET', '/holidays/' . $calendar);
        $this->client->submit($page->filter('[data-testid="wt-holiday-provider-openholidays"] form')->form());
        $this->client->submit($this->client->getCrawler()->filter('[data-testid="wt-holiday-import-confirm"] form')->form());
        self::assertSame(409, $this->client->getResponse()->getStatusCode(), 'a confirm that loses twice answers 409');
        self::assertTrue($this->client->getResponse()->headers->has('Retry-After'));
    }

    /**
     * Runs a statement on the test's connection (for the in-connection racers).
     *
     * @param list<mixed> $params
     */
    public function sql(string $sql, array $params): void
    {
        $types = [];
        foreach ($params as $param) {
            $types[] = is_bool($param) ? ParameterType::BOOLEAN : ParameterType::STRING;
        }
        $this->connection()->executeStatement($sql, $params, $types);
    }

    private function skipWithoutServer(): bool
    {
        if (!is_string(getenv('NOWO_TEST_DATABASE_URL')) || getenv('NOWO_TEST_DATABASE_URL') === '') {
            $this->addToAssertionCount(1); // two connections need a database server

            return true;
        }

        return false;
    }

    private function leaveOn(bool $seeded = true): void
    {
        $this->boot(['leave' => ['enabled' => true]]);
        $this->enable('acme', ['ana', 'hr']);
        $this->actAs('hr', 'leave_manage', 'manage_settings', 'holidays_manage');
        $this->admin()->updateSettings(new LeaveSettingsInput(true), 0);
        if (!$seeded) {
            $this->connection()->executeStatement('DELETE FROM nowo_working_time_leave_policy');
            $this->connection()->executeStatement('DELETE FROM nowo_working_time_leave_type');
        }
    }

    /**
     * @return array{string, string, string}
     */
    private function centres(): array
    {
        $this->boot();
        $this->ctx()->member('acme', 'hr', 'HR');
        $this->actAs('hr', 'holidays_manage');

        return [
            $this->holidays()->saveCentre(null, 'A office', [], true)->getId(),
            $this->holidays()->saveCentre(null, 'B office', [], false)->getId(),
            $this->holidays()->saveCentre(null, 'C office', [], false)->getId(),
        ];
    }

    private function calendar(): string
    {
        $this->boot();
        $this->ctx()->member('acme', 'hr', 'HR');
        $this->actAs('hr', 'holidays_manage');

        return $this->holidays()->saveCalendar(null, new HolidayCalendarInput('Madrid', HolidayScope::Local, 2026))->getId();
    }

    /**
     * A second kernel on its own connection with the same people, acting as `hr`.
     *
     * @param array<string, mixed> $config
     */
    private function otherKernel(array $config = []): ContainerInterface
    {
        /** @var array<string, mixed> $merged */
        $merged = array_replace_recursive(TestServices::PORTS, ['ui' => ['enabled' => true], 'register' => ['export' => ['async' => 'sync']]], $config);
        $kernel = $this->bootKernel(Host::standard()->bundleConfig($merged), Host::standard()->configure());
        $other  = $kernel->getContainer()->get('test.context');
        self::assertInstanceOf(TestContext::class, $other);
        foreach (['tenant', 'members', 'grants', 'groups', 'manages', 'actor'] as $property) {
            $other->{$property} = $this->ctx()->{$property};
        }
        $clock = $kernel->getContainer()->get('test.clock');
        self::assertInstanceOf(MockClock::class, $clock);
        $clock->modify($this->clock()->now()->format('Y-m-d H:i:s'));
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $runner = $container->get(DoctrineTransactionRunner::class);
        self::assertInstanceOf(DoctrineTransactionRunner::class, $runner);
        self::boundLockWaits($runner->manager()->getConnection());

        return $container;
    }

    /**
     * Runs `$work` while the other writer (`$race`) runs inside our first persist of the class.
     *
     * @param class-string $class
     */
    private function whileFirstPersists(string $class, RaceOutcome $outcome, Closure $race, Closure $work): void
    {
        $listener = new class($class, $outcome, $race) {
            public function __construct(private readonly string $class, private readonly RaceOutcome $outcome, private readonly Closure $race)
            {
            }

            public function prePersist(PrePersistEventArgs $args): void
            {
                if ($this->outcome->fired || !$args->getObject() instanceof $this->class) {
                    return;
                }
                $this->outcome->fired = true;
                ($this->race)();
            }
        };
        $this->em()->getEventManager()->addEventListener([Events::prePersist], $listener);
        try {
            $work();
        } finally {
            $this->em()->getEventManager()->removeEventListener([Events::prePersist], $listener);
        }
        self::assertTrue($outcome->fired);
    }

    private function admin(): LeaveAdministrationInterface
    {
        return $this->service(LeaveAdministrationInterface::class);
    }

    private function holidays(): HolidayCalendarServiceInterface
    {
        return $this->service(HolidayCalendarServiceInterface::class);
    }
}
