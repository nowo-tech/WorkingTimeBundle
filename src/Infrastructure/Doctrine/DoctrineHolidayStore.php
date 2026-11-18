<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\WorkingTimeBundle\Application\Holiday\Port\HolidayStoreInterface;
use Nowo\WorkingTimeBundle\Domain\Holiday\Model\HolidayCalendar;
use Nowo\WorkingTimeBundle\Domain\Holiday\Model\HolidayDay;
use Nowo\WorkingTimeBundle\Domain\Holiday\Model\WorkCentre;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;

use function array_filter;
use function array_values;

/**
 * The holiday component's persistence on the bundle's entity manager. Every lookup is scoped by the tenant and the
 * result re-checked with `===` (rule LQ: the column collation is exact on every supported database; this is the
 * second layer).
 */
final readonly class DoctrineHolidayStore implements HolidayStoreInterface
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private DoctrineTransactionRunner $runner)
    {
    }

    /**
     * See the implemented interface.
     */
    public function calendars(TenantRef $tenant): array
    {
        /** @var list<HolidayCalendar> $rows */
        $rows = $this->em()->createQueryBuilder()->select('c')->from(HolidayCalendar::class, 'c')
            ->where('c.tenantId = :tenant')->setParameter('tenant', $tenant->value)
            ->orderBy('c.year', 'DESC')->addOrderBy('c.name')->addOrderBy('c.id')->getQuery()->getResult();

        return array_values(array_filter($rows, static fn (HolidayCalendar $c): bool => $c->getTenantId() === $tenant->value));
    }

    /**
     * See the implemented interface.
     */
    public function calendar(TenantRef $tenant, string $id): ?HolidayCalendar
    {
        $calendar = $this->em()->find(HolidayCalendar::class, $id);

        return $calendar instanceof HolidayCalendar && $calendar->getTenantId() === $tenant->value && $calendar->getId() === $id ? $calendar : null;
    }

    /**
     * See the implemented interface.
     */
    public function addCalendar(HolidayCalendar $calendar): void
    {
        $this->em()->persist($calendar);
    }

    /**
     * See the implemented interface.
     */
    public function removeCalendar(HolidayCalendar $calendar): void
    {
        $em = $this->em();
        $em->createQueryBuilder()->delete(HolidayDay::class, 'd')
            ->where('d.tenantId = :tenant AND d.calendarId = :calendar')
            ->setParameter('tenant', $calendar->getTenantId())->setParameter('calendar', $calendar->getId())
            ->getQuery()->execute();
        $em->remove($calendar); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
    }

    /**
     * See the implemented interface.
     */
    public function days(TenantRef $tenant, array $calendarIds): array
    {
        /** @var list<HolidayDay> $rows */
        $rows = $this->em()->createQueryBuilder()->select('d')->from(HolidayDay::class, 'd')
            ->where('d.tenantId = :tenant AND d.calendarId IN (:calendars)')
            ->setParameter('tenant', $tenant->value)->setParameter('calendars', $calendarIds)
            ->orderBy('d.holidayDate')->addOrderBy('d.id')->getQuery()->getResult();

        return $this->own($tenant, $rows);
    }

    /**
     * See the implemented interface.
     */
    public function daysBetween(TenantRef $tenant, ?array $calendarIds, LocalDate $from, LocalDate $to): array
    {
        $query = $this->em()->createQueryBuilder()->select('d')->from(HolidayDay::class, 'd')
            ->where('d.tenantId = :tenant AND d.holidayDate BETWEEN :from AND :to')
            ->setParameter('tenant', $tenant->value)
            ->setParameter('from', $from->toDateTime(), 'date_immutable')->setParameter('to', $to->toDateTime(), 'date_immutable')
            ->orderBy('d.holidayDate')->addOrderBy('d.id');
        if ($calendarIds !== null) {
            $query->andWhere('d.calendarId IN (:calendars)')->setParameter('calendars', $calendarIds);
        }
        /** @var list<HolidayDay> $rows */
        $rows = $query->getQuery()->getResult();

        return $this->own($tenant, $rows);
    }

    /**
     * See the implemented interface.
     */
    public function day(TenantRef $tenant, string $calendarId, string $id): ?HolidayDay
    {
        $day = $this->em()->find(HolidayDay::class, $id);

        return $day instanceof HolidayDay && $day->getTenantId() === $tenant->value && $day->getCalendarId() === $calendarId && $day->getId() === $id ? $day : null;
    }

    /**
     * See the implemented interface.
     */
    public function addDay(HolidayDay $day): void
    {
        $this->em()->persist($day);
    }

    /**
     * See the implemented interface.
     */
    public function removeDay(HolidayDay $day): void
    {
        $this->em()->remove($day);
    }

    /**
     * See the implemented interface.
     */
    public function centres(TenantRef $tenant): array
    {
        /** @var list<WorkCentre> $rows */
        $rows = $this->em()->createQueryBuilder()->select('w')->from(WorkCentre::class, 'w')
            ->where('w.tenantId = :tenant')->setParameter('tenant', $tenant->value)
            ->orderBy('w.name')->addOrderBy('w.id')->getQuery()->getResult();

        return array_values(array_filter($rows, static fn (WorkCentre $w): bool => $w->getTenantId() === $tenant->value));
    }

    /**
     * See the implemented interface.
     */
    public function centre(TenantRef $tenant, string $id): ?WorkCentre
    {
        $centre = $this->em()->find(WorkCentre::class, $id);

        return $centre instanceof WorkCentre && $centre->getTenantId() === $tenant->value && $centre->getId() === $id ? $centre : null;
    }

    /**
     * See the implemented interface.
     */
    public function addCentre(WorkCentre $centre): void
    {
        $this->em()->persist($centre);
    }

    /**
     * See the implemented interface.
     */
    public function removeCentre(WorkCentre $centre): void
    {
        $this->em()->remove($centre);
    }

    /**
     * @param list<HolidayDay> $rows
     *
     * @return list<HolidayDay>
     */
    private function own(TenantRef $tenant, array $rows): array
    {
        return array_values(array_filter($rows, static fn (HolidayDay $d): bool => $d->getTenantId() === $tenant->value));
    }

    private function em(): EntityManagerInterface
    {
        return $this->runner->manager();
    }
}
