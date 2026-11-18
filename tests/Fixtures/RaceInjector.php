<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Fixtures;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Nowo\WorkingTimeBundle\Domain\Holiday\Model\HolidayCalendar;
use Nowo\WorkingTimeBundle\Domain\Holiday\Model\HolidayDay;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\LeaveSettings;
use Nowo\WorkingTimeBundle\Domain\Leave\Model\LeaveType;
use Nowo\WorkingTimeBundle\Domain\Register\Model\CorrectionRequest;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettings;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkdayRevision;

use function str_pad;
use function str_repeat;

use const STR_PAD_LEFT;

/**
 * Doctrine `prePersist` listener of the race tests: while `TestContext::$races` is positive, it stores another
 * writer's link with the same chain seq just before the bundle's own (as a concurrent request would), so the unique
 * key refuses the bundle's insert.
 */
final class RaceInjector
{
    public function __construct(private readonly TestContext $context)
    {
    }

    /**
     * Another writer saved first: the row's version moves under our flush.
     *
     * @param LifecycleEventArgs<EntityManagerInterface> $args
     */
    public function preUpdate(LifecycleEventArgs $args): void
    {
        $em     = $args->getObjectManager();
        $object = $args->getObject();
        if (($object instanceof RegisterSettings || $object instanceof CorrectionRequest || $object instanceof LeaveType || $object instanceof HolidayCalendar) && $this->context->bumpVersions) {
            $this->context->bumpVersions = false;
            $em->getConnection()->executeStatement('UPDATE ' . $em->getClassMetadata($object::class)->getTableName() . ' SET version = version + 1');
        }
    }

    /**
     * @param LifecycleEventArgs<EntityManagerInterface> $args
     */
    public function prePersist(LifecycleEventArgs $args): void
    {
        $em     = $args->getObjectManager();
        $object = $args->getObject();
        if ($object instanceof RegisterSettings && $this->context->settingsRace) {
            $this->context->settingsRace = false;
            $em->getConnection()->insert($em->getClassMetadata(RegisterSettings::class)->getTableName(), [
                'tenant_id'       => $object->getTenantId(), 'enabled' => 0, 'breaks_counted' => 0, 'flex_minutes' => 0, 'min_break_minutes' => 0,
                'task_timer_link' => 0, 'reminders_allowed' => 0, 'monthly_summary_mandatory' => 0, 'ip_evidence' => 0, 'ip_notices' => '[]',
                'retention_years' => 4, 'correction_window_days' => 31, 'version' => 1, 'updated_at' => '2026-11-18 10:00:00',
            ]);
        }
        if ($object instanceof LeaveSettings && $this->context->settingsRace) {
            $this->context->settingsRace = false;
            $em->getConnection()->insert($em->getClassMetadata(LeaveSettings::class)->getTableName(), [
                'tenant_id'      => $object->getTenantId(), 'enabled' => 0, 'colleagues_see_absences' => 1, 'medical_documents' => 0, 'attachment_retention_months' => 12,
                'overtime_ratio' => 100, 'rounding' => 'nearest', 'protected_carry_months' => 18, 'approval_chains' => '[]', 'min_staffing' => '[]', 'version' => 1, 'updated_at' => '2026-11-18 10:00:00',
            ]);
        }
        if ($object instanceof LeaveType && $object->getSeedCode() !== null && $this->context->seedRaces > 0) {
            --$this->context->seedRaces;
            $em->getConnection()->insert($em->getClassMetadata(LeaveType::class)->getTableName(), [
                'id'         => '01900000-0000-7000-8000-' . str_pad((string) $this->context->seedRaces, 12, '0', STR_PAD_LEFT), 'tenant_id' => $object->getTenantId(), 'seed_code' => $object->getSeedCode(),
                'names'      => '{}', 'paid' => 1, 'allowance_source' => 'none', 'unit' => 'working_days', 'needs_approval' => 1, 'needs_attachment' => 0, 'medical' => 0, 'colour' => 'blue',
                'carry_over' => 0, 'archived' => 0, 'sort_order' => 1, 'version' => 1, 'created_at' => '2026-11-18 10:00:00',
            ]);
        }
        if ($object instanceof HolidayDay && $this->context->dayRaces > 0) {
            --$this->context->dayRaces;
            $em->getConnection()->insert($em->getClassMetadata(HolidayDay::class)->getTableName(), [
                'id'          => '01900000-0000-7000-8000-' . str_pad((string) (100 + $this->context->dayRaces), 12, '0', STR_PAD_LEFT), 'tenant_id' => $object->getTenantId(),
                'calendar_id' => $object->getCalendarId(), 'holiday_date' => $object->date()->value, 'name' => 'racer', 'origin' => 'file',
            ]);
        }
        if ($object instanceof WorkdayRevision && $this->context->bumpVersions) {
            $this->context->bumpVersions = false;
            $em->getConnection()->executeStatement('UPDATE ' . $em->getClassMetadata(CorrectionRequest::class)->getTableName() . ' SET version = version + 1');
        }
        $revision = $object;
        if (!$revision instanceof WorkdayRevision || $this->context->races <= 0) {
            return;
        }
        --$this->context->races;
        $em->getConnection()->insert($em->getClassMetadata(WorkdayRevision::class)->getTableName(), [
            'id'           => '01900000-0000-7000-8000-' . str_pad((string) $this->context->races, 12, '0', STR_PAD_LEFT),
            'tenant_id'    => $revision->getTenantId(),
            'worker_id'    => $revision->getWorkerId(),
            'workday_id'   => $revision->getWorkdayId(),
            'seq'          => $revision->getSeq(),
            'kind'         => 'clock_in',
            'payload'      => '{}',
            'author_name'  => 'Racer',
            'created_at'   => '2026-11-18 10:00:00',
            'prev_hash'    => $revision->getPrevHash(),
            'hash'         => str_repeat('f', 64),
            'chain_format' => 2,
        ]);
    }
}
