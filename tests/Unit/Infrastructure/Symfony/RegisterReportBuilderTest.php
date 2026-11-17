<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Infrastructure\Symfony;

use Nowo\WorkingTimeBundle\Domain\Register\Chain\ChainCodecs;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\RevisionKind;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentSource;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentType;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkdayRevision;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkSegment;
use Nowo\WorkingTimeBundle\Domain\Register\Read\DayRecordBuilder;
use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\IpPrefix;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Export\RegisterReportBuilder;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Routing\UiRouteLoader;
use Nowo\WorkingTimeBundle\Tests\Unit\Support\Fx;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * The inspection report's layout: one row per day (IP prefixes only when collected), the corrections (approved,
 * recorded by a manager, contested) with their reasons and authors, the integrity block; and the UI route loader is
 * empty while the UI is off.
 */
final class RegisterReportBuilderTest extends TestCase
{
    public function testTheReportListsDaysCorrectionsAndIntegrity(): void
    {
        $workday = Fx::workday(Fx::schedule());
        $segment = WorkSegment::start(Fx::uuid(10), $workday, SegmentType::Work, Fx::at('2026-11-10 08:00:00'), SegmentSource::Web, Fx::at('2026-11-10 08:00:00'), IpPrefix::fromIp('203.0.113.5'));
        $segment->close(Fx::at('2026-11-10 16:00:00'), SegmentSource::Web, Fx::at('2026-11-10 16:00:00'), null);
        $approved  = WorkdayRevision::append(Fx::uuid(11), 1, ChainCodecs::GENESIS, $workday, RevisionKind::Correction, $segment->getId(), ['op' => 'amend', 'segment' => $segment->getId(), 'start' => '2026-11-10T07:30:00Z', 'approved_by' => 'Mia'], 'Came early', new ActorRef('mia'), 'Mia', Fx::at('2026-11-11 09:00:00'), null);
        $recorded  = WorkdayRevision::append(Fx::uuid(12), 2, $approved->getHash(), $workday, RevisionKind::RecordedCorrection, $segment->getId(), ['op' => 'void', 'segment' => 'x'], 'Door log', new ActorRef('mia'), 'Mia', Fx::at('2026-11-11 10:00:00'), null);
        $contested = WorkdayRevision::append(Fx::uuid(13), 3, $recorded->getHash(), $workday, RevisionKind::Contested, $segment->getId(), ['revision' => $recorded->getId()], 'Not true', new ActorRef('ana'), 'Ana', Fx::at('2026-11-11 11:00:00'), null);
        $record    = DayRecordBuilder::build($workday, [$segment], [$approved, $recorded, $contested], [], Fx::at('2026-11-12 00:00:00'));

        $report = (new RegisterReportBuilder(new IdentityTranslator()))->build(['2026-11-10' => $record], 'Acme', 'Ana', '2026-11', 'Ins', 'abc', 'broken', true, Fx::at('2026-11-12 00:00:00'), 'en');

        self::assertCount(10, $report->dayHeaders, 'with the IP prefix column');
        self::assertSame('203.0.113.0/24', $report->dayRows[0][9]);
        self::assertCount(3, $report->correctionRows);
        self::assertSame('Mia', $report->correctionRows[0][4]);
        self::assertSame('export.recorded_by_manager', $report->correctionRows[1][4]);
        self::assertSame('history.contested', $report->correctionRows[2][1]);
        self::assertSame('broken', $report->integrity[3][1]);
        self::assertSame('abc', $report->chainHead);
        $plain = (new RegisterReportBuilder(new IdentityTranslator()))->build([], 'Acme', 'Ana', '2026-11', 'Ins', null, null, false, Fx::at('2026-11-12 00:00:00'), 'en');
        self::assertCount(9, $plain->dayHeaders);
        self::assertSame('—', $plain->chainHead);
    }

    public function testTheUiRoutesAreOptIn(): void
    {
        self::assertCount(0, (new UiRouteLoader())->load('.'));
        self::assertTrue((new UiRouteLoader())->supports('.', UiRouteLoader::TYPE));
        self::assertCount(18, (new UiRouteLoader(true, '/'))->load('.'));
    }
}
