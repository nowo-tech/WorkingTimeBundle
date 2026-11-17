<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Integration\Database;

use Nowo\WorkingTimeBundle\Domain\Register\Enum\ClockAction;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ClockStatus;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentSource;
use Nowo\WorkingTimeBundle\Domain\Register\Service\ChainVerifierInterface;
use Nowo\WorkingTimeBundle\Domain\Register\Service\RegisterClockInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\RequestKey;

/**
 * The clock end to end on the configured database: a day clocked in, a break, clocked out; the chain verifies.
 */
final class ClockFlowTest extends RegisterTestCase
{
    public function testADayIsClockedAndTheChainVerifies(): void
    {
        $this->boot();
        $this->enable();
        $this->actAs('ana', 'clock');
        $clock = $this->service(RegisterClockInterface::class);

        self::assertSame(ClockStatus::Idle, $clock->state()->status);
        $clock->act(null, ClockAction::ClockIn, null, RequestKey::web('k1'), SegmentSource::Web, '203.0.113.9');
        $this->clock()->modify('+2 hours');
        $clock->act(null, ClockAction::BreakStart, null, RequestKey::web('k2'), SegmentSource::Web, null);
        $this->clock()->modify('+30 minutes');
        $clock->act(null, ClockAction::BreakEnd, null, RequestKey::web('k3'), SegmentSource::Web, null);
        $this->clock()->modify('+3 hours');
        $state = $clock->act(null, ClockAction::ClockOut, null, RequestKey::web('k4'), SegmentSource::Web, null);

        self::assertSame(ClockStatus::Closed, $state->status);
        self::assertSame(5 * 3600, $state->workedSeconds());
        self::assertSame(1800, $state->breakSeconds());
        $report = $this->service(ChainVerifierInterface::class)->verify();
        self::assertTrue($report->isIntact(), json_encode($report->broken) ?: '');
        self::assertSame(4, $report->links);
    }
}
