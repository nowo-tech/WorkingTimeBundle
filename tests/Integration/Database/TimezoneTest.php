<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Database;

use Nowo\WorkingTimeBundle\Domain\Register\Service\ChainVerifierInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Service\RegisterReaderInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;

use function date_default_timezone_get;
use function date_default_timezone_set;

use const DATE_ATOM;

/**
 * A host whose PHP runs in `Europe/Madrid` (audit ST-H1, probe P13): instants are stored and read as UTC, so the chain
 * verifies and the day reads the times that were clocked. CI also runs the whole Database suite with
 * `-d date.timezone=Europe/Madrid`.
 */
final class TimezoneTest extends RegisterTestCase
{
    private string $zone = 'UTC';

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Madrid');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
        parent::tearDown();
    }

    public function testAMadridHostReadsUtcInstants(): void
    {
        $this->boot();
        $this->enable();
        $this->actAs('ana', 'clock');
        $this->clock()->modify('2026-11-18 08:00:00 UTC');
        $this->workDay();
        $this->em()->clear();

        self::assertTrue($this->service(ChainVerifierInterface::class)->verify()->isIntact());
        $days = $this->service(RegisterReaderInterface::class)->worker(new TenantRef('acme'), new WorkerRef('ana'), new LocalDate('2026-11-18'), new LocalDate('2026-11-18'), $this->clock()->now());
        self::assertSame('2026-11-18T08:00:00+00:00', $days['2026-11-18']->firstStart()?->format(DATE_ATOM));
        self::assertSame('2026-11-18 08:00:00', $this->text('SELECT started_at FROM nowo_working_time_segment ORDER BY started_at'));
    }
}
