<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Domain\Register\Model;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ChainFormat;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\CorrectionOperation;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\CorrectionStatus;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ExportFormat;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ExportStatus;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\RevisionKind;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\ScheduleScope;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentSource;
use Nowo\WorkingTimeBundle\Domain\Register\Enum\SegmentType;
use Nowo\WorkingTimeBundle\Domain\Register\Model\ChainAnchor;
use Nowo\WorkingTimeBundle\Domain\Register\Model\CorrectionInput;
use Nowo\WorkingTimeBundle\Domain\Register\Model\CorrectionRequest;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterExport;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterExportFile;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterNotice;
use Nowo\WorkingTimeBundle\Domain\Register\Model\ScheduleAssignment;
use Nowo\WorkingTimeBundle\Domain\Register\Model\ScheduleDefinition;
use Nowo\WorkingTimeBundle\Domain\Register\Model\Workday;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkdayRevision;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkSegment;
use Nowo\WorkingTimeBundle\Domain\Shared\ActorRef;
use Nowo\WorkingTimeBundle\Domain\Shared\IpPrefix;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\RequestKey;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Tests\Unit\Support\Fx;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function fopen;
use function fwrite;
use function str_repeat;
use function strlen;

/**
 * Aggregates and records of the register: workday, segment, revision, correction, export, notice.
 */
final class RegisterModelsTest extends TestCase
{
    public function testWorkdayOpenAndMigrated(): void
    {
        $workday = Workday::open(Fx::uuid(1), new TenantRef('t'), new WorkerRef('w'), str_repeat('n', 200), new LocalDate('2026-11-10'), Fx::schedule(), Fx::at('2026-11-10 07:00:00'));

        self::assertSame(Fx::uuid(1), $workday->getId());
        self::assertSame('t', $workday->getTenantId());
        self::assertSame('w', $workday->getWorkerId());
        self::assertSame(180, mb_strlen($workday->getWorkerName()));
        self::assertSame('2026-11-10', $workday->date()->value);
        self::assertSame(28_800, $workday->schedule()->expectedSeconds);
        self::assertSame('2026-11-10 07:00:00', $workday->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertNull($workday->getLegacyId());

        $migrated = Workday::migrated(Fx::uuid(2), new TenantRef('t'), new WorkerRef('w'), 'Ana', new LocalDate('2026-11-10'), Fx::schedule(), Fx::at('2026-11-10'), 42);
        self::assertSame(42, $migrated->getLegacyId());
    }

    public function testWorkdayRefusesANonUuid(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Workday::open('x', new TenantRef('t'), new WorkerRef('w'), 'Ana', new LocalDate('2026-11-10'), Fx::schedule(), Fx::at('2026-11-10'));
    }

    public function testSegmentLifecycle(): void
    {
        $workday = Fx::workday();
        $segment = WorkSegment::start(Fx::uuid(5), $workday, SegmentType::Work, Fx::at('2026-11-10 09:00:00'), SegmentSource::Web, Fx::at('2026-11-10 09:00:05'), IpPrefix::fromIp('10.1.2.3'));

        self::assertTrue($segment->isOpen());
        self::assertSame(Fx::uuid(5), $segment->getId());
        self::assertSame($workday->getId(), $segment->getWorkdayId());
        self::assertSame(SegmentType::Work, $segment->getType());
        self::assertSame(SegmentSource::Web, $segment->getStartSource());
        self::assertSame('10.1.2.0/24', $segment->getStartIpPrefix());
        self::assertSame('09:00:05', $segment->getStartReceivedAt()->format('H:i:s'));
        self::assertNull($segment->getEndedAt());
        self::assertNull($segment->getEndSource());
        self::assertNull($segment->getEndReceivedAt());
        self::assertNull($segment->getEndIpPrefix());
        self::assertFalse($segment->isLate(600));

        $segment->close(Fx::at('2026-11-10 17:00:00'), SegmentSource::Desktop, Fx::at('2026-11-10 17:00:01'), null);

        self::assertFalse($segment->isOpen());
        self::assertSame('17:00:00', $segment->getEndedAt()?->format('H:i:s'));
        self::assertSame(SegmentSource::Desktop, $segment->getEndSource());
        self::assertSame('17:00:01', $segment->getEndReceivedAt()?->format('H:i:s'));
        self::assertNull($segment->getEndIpPrefix());
        self::assertFalse($segment->isLate(600));
    }

    public function testSegmentClosedTwiceIsALogicError(): void
    {
        $segment = WorkSegment::start(Fx::uuid(5), Fx::workday(), SegmentType::Work, Fx::at('2026-11-10 09:00:00'), SegmentSource::Web, Fx::at('2026-11-10 09:00:00'), null);
        $segment->close(Fx::at('2026-11-10 10:00:00'), SegmentSource::Web, Fx::at('2026-11-10 10:00:00'), null);

        $this->expectException(LogicException::class);

        $segment->close(Fx::at('2026-11-10 11:00:00'), SegmentSource::Web, Fx::at('2026-11-10 11:00:00'), null);
    }

    public function testSegmentLateness(): void
    {
        $lateStart = WorkSegment::start(Fx::uuid(5), Fx::workday(), SegmentType::Work, Fx::at('2026-11-10 09:00:00'), SegmentSource::Web, Fx::at('2026-11-10 09:20:00'), null);
        self::assertTrue($lateStart->isLate(600));
        self::assertFalse($lateStart->isLate(3600));

        $lateEnd = WorkSegment::start(Fx::uuid(6), Fx::workday(), SegmentType::Work, Fx::at('2026-11-10 09:00:00'), SegmentSource::Web, Fx::at('2026-11-10 09:00:00'), null);
        $lateEnd->close(Fx::at('2026-11-10 10:00:00'), SegmentSource::Web, Fx::at('2026-11-10 10:30:00'), null);
        self::assertTrue($lateEnd->isLate(600));
    }

    public function testRevisionAppend(): void
    {
        $workday  = Fx::workday();
        $revision = WorkdayRevision::append(Fx::uuid(9), 3, str_repeat('a', 64), $workday, RevisionKind::ClockIn, Fx::uuid(5), ['k' => 'v'], 'why', new ActorRef('u1'), str_repeat('n', 200), Fx::at('2026-11-10 09:00:00'), RequestKey::web('k1'));

        self::assertSame(Fx::uuid(9), $revision->getId());
        self::assertSame('t1', $revision->getTenantId());
        self::assertSame('w1', $revision->getWorkerId());
        self::assertSame($workday->getId(), $revision->getWorkdayId());
        self::assertSame(3, $revision->getSeq());
        self::assertSame(RevisionKind::ClockIn, $revision->getKind());
        self::assertSame(Fx::uuid(5), $revision->getSegmentId());
        self::assertSame(['k' => 'v'], $revision->getPayload());
        self::assertSame('why', $revision->getReason());
        self::assertSame('u1', $revision->getAuthorId());
        self::assertSame(180, mb_strlen($revision->getAuthorName()));
        self::assertSame('web:k1', $revision->getRequestKey());
        self::assertSame(str_repeat('a', 64), $revision->getPrevHash());
        self::assertSame(ChainFormat::Bundle, $revision->getChainFormat());
        self::assertNull($revision->getLegacyRefs());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $revision->getHash());
        self::assertSame('2026-11-10 09:00:00', $revision->getCreatedAt()->format('Y-m-d H:i:s'));

        $anonymous = WorkdayRevision::append(Fx::uuid(10), 4, $revision->getHash(), $workday, RevisionKind::Acknowledged, null, [], null, null, 'System', Fx::at('2026-11-10 09:00:00'), null);
        self::assertNull($anonymous->getAuthorId());
        self::assertNull($anonymous->getRequestKey());
    }

    public function testRevisionMigratedKeepsLegacyRefs(): void
    {
        $revision = WorkdayRevision::migrated(Fx::uuid(9), Fx::workday(), 1, RevisionKind::ClockOut, null, [], null, null, 'Ana', Fx::at('2026-11-10 17:00:00'), null, str_repeat('0', 64), ['organization' => 3, 'user' => 5, 'workday' => 11, 'author' => null]);

        self::assertSame(ChainFormat::Beacon, $revision->getChainFormat());
        self::assertSame(['organization' => 3, 'user' => 5, 'workday' => 11, 'author' => null], $revision->getLegacyRefs());
    }

    public function testUtcAndIso(): void
    {
        $madrid = new DateTimeImmutable('2026-11-10 10:00:00', new DateTimeZone('Europe/Madrid'));

        self::assertSame('UTC', WorkdayRevision::utc($madrid)->getTimezone()->getName());
        self::assertSame('2026-11-10T09:00:00Z', WorkdayRevision::iso($madrid));
    }

    public function testChainAnchor(): void
    {
        $anchor = ChainAnchor::record(Fx::uuid(1), 't', 'w', 12, str_repeat('b', 64), 11, Fx::at('2026-11-10'), 'run-1');

        self::assertSame(Fx::uuid(1), $anchor->getId());
        self::assertSame('t', $anchor->getTenantId());
        self::assertSame('w', $anchor->getWorkerId());
        self::assertSame(12, $anchor->getFirstKeptSeq());
        self::assertSame(str_repeat('b', 64), $anchor->getAnchorHash());
        self::assertSame(11, $anchor->getPurgedLinks());
        self::assertSame('2026-11-10', $anchor->getPurgedAt()->format('Y-m-d'));
        self::assertSame('run-1', $anchor->getPurgeRunId());
    }

    public function testScheduleAssignment(): void
    {
        $definition = new ScheduleDefinition('UTC', [['day' => 1, 'from' => '09:00', 'to' => '17:00']]);
        $group      = ScheduleAssignment::create(Fx::uuid(1), 't', ScheduleScope::Group, 'g1', new LocalDate('2026-01-01'), $definition, 'centre', Fx::at('2026-11-10'), new ActorRef('admin'));

        self::assertSame(Fx::uuid(1), $group->getId());
        self::assertSame('t', $group->getTenantId());
        self::assertSame(ScheduleScope::Group, $group->getScope());
        self::assertSame('g1', $group->getTargetId());
        self::assertSame('2026-01-01', $group->validFrom()->value);
        self::assertEquals($definition, $group->definition());
        self::assertSame('centre', $group->getWorkCentreId());
        self::assertSame('2026-11-10', $group->getCreatedAt()->format('Y-m-d'));
        self::assertSame('admin', $group->getCreatedById());

        $tenant = ScheduleAssignment::create(Fx::uuid(2), 't', ScheduleScope::Tenant, 'ignored', new LocalDate('2026-01-01'), $definition, null, Fx::at('2026-11-10'), null);
        self::assertNull($tenant->getTargetId());
        self::assertNull($tenant->getCreatedById());
    }

    public function testCorrectionRequestLifecycle(): void
    {
        $workday = Fx::workday();
        $request = CorrectionRequest::file(Fx::uuid(20), $workday, CorrectionOperation::Add, Fx::uuid(5), SegmentType::Break, Fx::at('2026-11-10 12:00:00'), Fx::at('2026-11-10 12:30:00'), 'forgot the lunch break', Fx::at('2026-11-11 08:00:00'));

        self::assertSame(Fx::uuid(20), $request->getId());
        self::assertSame('t1', $request->getTenantId());
        self::assertSame('w1', $request->getWorkerId());
        self::assertSame($workday->getId(), $request->getWorkdayId());
        self::assertSame(CorrectionOperation::Add, $request->getOperation());
        self::assertSame(Fx::uuid(5), $request->getSegmentId());
        self::assertSame(SegmentType::Break, $request->getSegmentType());
        self::assertSame('12:00', $request->getNewStart()?->format('H:i'));
        self::assertSame('12:30', $request->getNewEnd()?->format('H:i'));
        self::assertSame('forgot the lunch break', $request->getReason());
        self::assertSame('2026-11-11', $request->getCreatedAt()->format('Y-m-d'));
        self::assertTrue($request->isPending());
        self::assertSame(CorrectionStatus::Pending, $request->getStatus());
        self::assertNull($request->getDecidedById());
        self::assertNull($request->getDecidedByName());
        self::assertNull($request->getDecidedAt());
        self::assertNull($request->getDecisionComment());
        self::assertNull($request->getRevisionId());
        self::assertSame(1, $request->getVersion());

        $request->approve(new ActorRef('boss'), str_repeat('B', 200), 'ok', Fx::uuid(30), Fx::at('2026-11-12 09:00:00'));

        self::assertFalse($request->isPending());
        self::assertSame(CorrectionStatus::Approved, $request->getStatus());
        self::assertSame('boss', $request->getDecidedById());
        self::assertSame(180, mb_strlen((string) $request->getDecidedByName()));
        self::assertSame('2026-11-12', $request->getDecidedAt()?->format('Y-m-d'));
        self::assertSame('ok', $request->getDecisionComment());
        self::assertSame(Fx::uuid(30), $request->getRevisionId());
    }

    public function testCorrectionRequestWithoutTimes(): void
    {
        $request = CorrectionRequest::file(Fx::uuid(20), Fx::workday(), CorrectionOperation::Void, Fx::uuid(5), null, null, null, 'entered by mistake', Fx::at('2026-11-11'));
        $request->reject(new ActorRef('boss'), 'Boss', null, Fx::at('2026-11-12'));

        self::assertNull($request->getNewStart());
        self::assertNull($request->getNewEnd());
        self::assertSame(CorrectionStatus::Rejected, $request->getStatus());
        self::assertNull($request->getRevisionId());
    }

    public function testCorrectionRequestIsDecidedOnce(): void
    {
        $request = CorrectionRequest::file(Fx::uuid(20), Fx::workday(), CorrectionOperation::Void, null, null, null, null, 'entered by mistake', Fx::at('2026-11-11'));
        $request->reject(new ActorRef('boss'), 'Boss', null, Fx::at('2026-11-12'));

        $this->expectException(LogicException::class);

        $request->approve(new ActorRef('boss'), 'Boss', null, Fx::uuid(31), Fx::at('2026-11-12'));
    }

    public function testCorrectionInputDefaults(): void
    {
        $input = new CorrectionInput(CorrectionOperation::Change);

        self::assertNull($input->segment);
        self::assertNull($input->reason);
    }

    private function export(): RegisterExport
    {
        return RegisterExport::request(Fx::uuid(40), 't', new ActorRef('u'), str_repeat('N', 200), 'w', 'g', true, '2026-01', '2026-03', ExportFormat::Csv, 'es', Fx::at('2026-11-10 08:00:00'));
    }

    public function testExportLifecycle(): void
    {
        $export = $this->export();

        self::assertSame(Fx::uuid(40), $export->getId());
        self::assertSame('t', $export->getTenantId());
        self::assertSame('u', $export->getRequestedById());
        self::assertSame(180, mb_strlen($export->getRequestedByName()));
        self::assertSame('w', $export->getWorkerId());
        self::assertSame('g', $export->getGroupId());
        self::assertTrue($export->isOwnCopy());
        self::assertSame('2026-01', $export->monthFrom());
        self::assertSame('2026-03', $export->monthTo());
        self::assertSame(ExportFormat::Csv, $export->getFormat());
        self::assertSame('es', $export->getLocale());
        self::assertSame(ExportStatus::Pending, $export->getStatus());
        self::assertSame('2026-11-10', $export->getCreatedAt()->format('Y-m-d'));
        self::assertNull($export->getFileName());
        self::assertNull($export->getContentType());
        self::assertNull($export->getSha256());
        self::assertSame([], $export->getChainHeads());
        self::assertNull($export->getReadyAt());
        self::assertNull($export->getExpiresAt());
        self::assertNull($export->getErrorCode());
        self::assertFalse($export->isDownloadable(Fx::at('2026-11-10')));

        $export->complete('f.csv', 'text/csv', str_repeat('c', 64), ['w' => 'h'], Fx::at('2026-11-10 09:00:00'), 7);

        self::assertSame(ExportStatus::Ready, $export->getStatus());
        self::assertSame('f.csv', $export->getFileName());
        self::assertSame('text/csv', $export->getContentType());
        self::assertSame(str_repeat('c', 64), $export->getSha256());
        self::assertSame(['w' => 'h'], $export->getChainHeads());
        self::assertSame('2026-11-17 09:00:00', $export->getExpiresAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-10 09:00:00', $export->getReadyAt()?->format('Y-m-d H:i:s'));
        self::assertTrue($export->isDownloadable(Fx::at('2026-11-16')));
        self::assertFalse($export->isDownloadable(Fx::at('2026-11-17 09:00:00')));

        $export->expire();
        self::assertSame(ExportStatus::Expired, $export->getStatus());
        self::assertFalse($export->isDownloadable(Fx::at('2026-11-11')));
    }

    public function testExportFailureTruncatesTheCode(): void
    {
        $export = $this->export();
        $export->fail(str_repeat('e', 300));

        self::assertSame(ExportStatus::Failed, $export->getStatus());
        self::assertSame(120, strlen((string) $export->getErrorCode()));
    }

    public function testFailedExportCannotComplete(): void
    {
        $export = $this->export();
        $export->fail('boom');

        $this->expectException(LogicException::class);

        $export->complete('f.csv', 'text/csv', 'x', [], Fx::at('2026-11-10'), 7);
    }

    public function testExportFileHoldsAStringOrAStream(): void
    {
        $file = RegisterExportFile::of(Fx::uuid(40), 'bytes');
        self::assertSame(Fx::uuid(40), $file->getExportId());
        self::assertSame('bytes', $file->getContent());

        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        fwrite($stream, 'streamed');
        $property = new ReflectionProperty($file, 'content');
        $property->setValue($file, $stream);
        self::assertSame('streamed', $file->getContent());
        self::assertSame('streamed', $file->getContent());

        $property->setValue($file, 42);
        self::assertSame('', $file->getContent());
    }

    public function testRegisterNotice(): void
    {
        $notice = RegisterNotice::queue(Fx::uuid(50), 't', 'w', RegisterNotice::EXPORT_READY, ['a' => 1], '/x', 'dedupe', Fx::at('2026-11-10 09:00:00'), Fx::at('2026-11-10 08:00:00'));

        self::assertSame(Fx::uuid(50), $notice->getId());
        self::assertSame('t', $notice->getTenantId());
        self::assertSame('w', $notice->getWorkerId());
        self::assertSame('register.export_ready', $notice->getKind());
        self::assertSame(['a' => 1], $notice->getParams());
        self::assertSame('/x', $notice->getActionPath());
        self::assertSame('dedupe', $notice->getDedupeKey());
        self::assertSame('09:00', $notice->getDeliverableFrom()->format('H:i'));
        self::assertSame('08:00', $notice->getCreatedAt()->format('H:i'));
        self::assertNull($notice->getSentAt());
    }
}
