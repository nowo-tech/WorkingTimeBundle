<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Database;

use DateTimeImmutable;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Nowo\WorkingTimeBundle\Application\Register\Exception\ChainBusyException;
use Nowo\WorkingTimeBundle\Application\Register\Exception\StaleCorrectionDecisionException;
use Nowo\WorkingTimeBundle\Application\Register\Exception\StaleSettingsException;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\CorrectionOperation;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentType;
use Nowo\WorkingTimeBundle\Domain\Register\Model\CorrectionInput;
use Nowo\WorkingTimeBundle\Domain\Register\Model\CorrectionRequest;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettingsInput;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkdayRevision;
use Nowo\WorkingTimeBundle\Domain\Register\Service\ChainVerifierInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Service\CorrectionServiceInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Service\RegisterSettingsEditorInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Tests\Fixtures\RaceOutcome;
use Nowo\WorkingTimeBundle\Tests\Fixtures\TestContext;
use Nowo\WorkingTimeBundle\Tests\Fixtures\TestServices;
use Nowo\WorkingTimeBundle\Tests\Integration\Kernel\Host;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

use function array_replace_recursive;
use function getenv;
use function is_string;

/**
 * Races on a worker's chain and on versioned rows (audit ST-M2, probes P14 / P16): a racing writer's link makes the
 * write run once more against the new head; a second race, or a version that moved under the flush, is a 409 +
 * Retry-After conflict (stale decision, stale settings, busy chain) — never a 500, and nothing of the losing write is
 * stored. The two-connection test runs a second kernel on its own connection inside the first one's write.
 */
final class RaceTest extends UiTestCase
{
    public function testADecisionRacesOnceThenItsVersionMovesAndBothAre409(): void
    {
        $request = $this->pending();
        $this->actAs('mia', 'manage_team');
        $this->ctx()->races = 1;
        self::assertSame('approved', $this->corrections()->decide($request->getId(), true, null, $request->getVersion())->getStatus()->value, 'replayed once');

        $second             = $this->secondRequest();
        $this->ctx()->races = 2;
        $this->assertConflict(fn () => $this->corrections()->decide($second->getId(), true, null, $second->getVersion()), StaleCorrectionDecisionException::class);
        $this->ctx()->races        = 0;
        $this->ctx()->bumpVersions = true;
        $this->assertConflict(fn () => $this->corrections()->decide($second->getId(), true, null, $second->getVersion()), StaleCorrectionDecisionException::class);
        self::assertSame('pending', $this->text('SELECT status FROM nowo_working_time_correction WHERE id = ?', [$second->getId()]));
        self::assertTrue($this->service(ChainVerifierInterface::class)->verify()->isIntact());
    }

    public function testRecordedCorrectionsAndAnswersRaceOnceThenAnswer409(): void
    {
        $this->pending();
        $this->actAs('mia', 'manage_team');
        $input              = new CorrectionInput(CorrectionOperation::Add, null, SegmentType::Work, new DateTimeImmutable('2026-11-18 06:00:00 UTC'), new DateTimeImmutable('2026-11-18 07:00:00 UTC'), 'Early start from the door log');
        $this->ctx()->races = 1;
        $recorded           = $this->corrections()->record(new WorkerRef('ana'), new LocalDate('2026-11-18'), $input);
        $this->ctx()->races = 2;
        $this->assertConflict(fn () => $this->corrections()->record(new WorkerRef('ana'), new LocalDate('2026-11-18'), $input), ChainBusyException::class);

        $this->ctx()->actor = 'ana';
        $this->ctx()->races = 2;
        $this->assertConflict(fn () => $this->corrections()->answer($recorded->getId(), false, null), ChainBusyException::class);
        $this->ctx()->races = 0;

        $this->ctx()->actor = 'mia';
        $this->ctx()->races = 2;
        $page               = $this->client->request('GET', '/working-time/team/day?worker=ana&date=2026-11-18');
        $this->client->submit($page->filter('[data-testid="wt-correction-form"] form')->form(), ['wt_correction[operation]' => 'add', 'wt_correction[segmentType]' => 'work', 'wt_correction[start]' => '05:00', 'wt_correction[end]' => '05:30', 'wt_correction[reason]' => 'Another early start']);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertSame('1', $this->client->getResponse()->headers->get('Retry-After'));
    }

    public function testSettingsSavedAtTheSameMomentAre409(): void
    {
        $this->boot();
        $this->ctx()->member('acme', 'adm');
        $this->actAs('adm', 'manage_settings');
        $editor                    = $this->service(RegisterSettingsEditorInterface::class);
        $this->ctx()->settingsRace = true;
        $this->assertConflict(static fn () => $editor->update(new RegisterSettingsInput(enabled: true), 0), StaleSettingsException::class);
        $editor->update(new RegisterSettingsInput(enabled: true), 0);
        [, $version]               = $editor->get();
        $this->ctx()->bumpVersions = true;
        $this->assertConflict(static fn () => $editor->update(new RegisterSettingsInput(enabled: true, retentionYears: 6), $version), StaleSettingsException::class);
    }

    public function testTwoManagersOnTwoConnectionsGetOneDecisionAndOne409(): void
    {
        if (!is_string(getenv('NOWO_TEST_DATABASE_URL')) || getenv('NOWO_TEST_DATABASE_URL') === '') {
            $this->addToAssertionCount(1); // two connections need a database server

            return;
        }
        $request = $this->pending();
        $this->ctx()->grant('acme', 'leo', 'manage_team');
        $this->ctx()->grant('acme', 'mia', 'manage_team');
        $this->ctx()->member('acme', 'leo', 'Leo');
        $kernel = $this->bootKernel(Host::standard()->bundleConfig(self::config(array_replace_recursive(TestServices::PORTS, ['ui' => ['enabled' => true], 'register' => ['export' => ['async' => 'sync']]]))), Host::standard()->configure());
        $other  = $kernel->getContainer()->get('test.context');
        self::assertInstanceOf(TestContext::class, $other);
        foreach (['tenant', 'members', 'grants', 'groups', 'manages'] as $property) {
            $other->{$property} = $this->ctx()->{$property};
        }
        $other->actor = 'leo';
        $clock        = $kernel->getContainer()->get('test.clock');
        self::assertInstanceOf(MockClock::class, $clock);
        $clock->modify($this->clock()->now()->format('Y-m-d H:i:s'));
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $leo = $container->get(CorrectionServiceInterface::class);
        self::assertInstanceOf(CorrectionServiceInterface::class, $leo);
        $result   = new RaceOutcome();
        $listener = new class($leo, $request, $result) {
            public function __construct(private readonly CorrectionServiceInterface $leo, private readonly CorrectionRequest $request, private readonly RaceOutcome $result)
            {
            }

            public function prePersist(PrePersistEventArgs $args): void
            {
                if ($this->result->fired || !$args->getObject() instanceof WorkdayRevision) {
                    return;
                }
                $this->result->fired  = true;
                $this->result->result = $this->leo->decide($this->request->getId(), true, null, $this->request->getVersion())->getStatus()->value;
            }
        };
        $this->em()->getEventManager()->addEventListener([Events::prePersist], $listener);
        $this->ctx()->actor = 'mia';
        try {
            $this->assertConflict(fn () => $this->corrections()->decide($request->getId(), true, null, $request->getVersion()), StaleCorrectionDecisionException::class);
        } finally {
            $this->em()->getEventManager()->removeEventListener([Events::prePersist], $listener);
        }

        self::assertSame('approved', $result->result);
        self::assertSame(1, $this->int("SELECT COUNT(*) FROM nowo_working_time_revision WHERE kind = 'correction'"));
        self::assertTrue($this->service(ChainVerifierInterface::class)->verify()->isIntact());
    }

    private function pending(): CorrectionRequest
    {
        $this->boot();
        $this->enable('acme', ['ana', 'mia']);
        $this->ctx()->manages['acme']['mia'] = ['ana'];
        $this->actAs('ana', 'clock');
        $this->clock()->modify('2026-11-18 08:00:00');
        $this->workDay();

        return $this->secondRequest('ASC');
    }

    private function secondRequest(string $order = 'DESC'): CorrectionRequest
    {
        $actor              = $this->ctx()->actor;
        $this->ctx()->actor = 'ana';
        $request            = $this->corrections()->request(new LocalDate('2026-11-18'), new CorrectionInput(CorrectionOperation::Void, $this->text('SELECT id FROM nowo_working_time_segment ORDER BY started_at ' . $order), null, null, null, 'The first segment was a mistake'));
        $this->ctx()->actor = $actor;

        return $request;
    }

    private function corrections(): CorrectionServiceInterface
    {
        return $this->service(CorrectionServiceInterface::class);
    }

    /**
     * @param callable(): mixed $call
     * @param class-string<Throwable> $class
     */
    private function assertConflict(callable $call, string $class): void
    {
        try {
            $call();
        } catch (Throwable $e) {
            self::assertInstanceOf($class, $e, $e::class . ': ' . $e->getMessage());

            return;
        }
        self::fail($class . ' expected.');
    }

    /**
     * @param array<mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function config(array $config): array
    {
        $typed = [];
        foreach ($config as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }
}
