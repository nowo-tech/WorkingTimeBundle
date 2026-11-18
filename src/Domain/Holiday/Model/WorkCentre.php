<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Holiday\Model;

use Nowo\WorkingTimeBundle\Domain\Holiday\Exception\HolidayRefusedException;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayEntry;
use Nowo\WorkingTimeBundle\Domain\Shared\Uuid;

use function array_unique;
use function array_values;
use function in_array;
use function is_string;
use function mb_strlen;

/**
 * A work centre of a tenant (contract section 6.7; spec 204 FR-008): a name and the holiday calendars that apply to the
 * people who work there (a Madrid office and a Barcelona office have different holidays). A worker's centre comes from
 * their schedule row (register); the tenant's default centre otherwise.
 */
class WorkCentre
{
    /** Longest name. */
    public const NAME_MAX = 80;

    private string $name = '';

    /** @var array<mixed> the stored json list */
    private array $calendarIds = [];

    private bool $isDefault = false;

    private function __construct(
        private string $id,
        private string $tenantId,
    ) {
    }

    /**
     * A new centre.
     *
     * @param list<string> $calendarIds
     *
     * @throws HolidayRefusedException `holiday.centre.error.name`
     */
    public static function create(string $id, string $tenantId, string $name, array $calendarIds, bool $default): self
    {
        $centre = new self(Uuid::assert($id), $tenantId);
        $centre->change($name, $calendarIds, $default);

        return $centre;
    }

    /**
     * Name, calendars and the default flag.
     *
     * @param list<string> $calendarIds
     *
     * @throws HolidayRefusedException `holiday.centre.error.name`
     */
    public function change(string $name, array $calendarIds, bool $default): void
    {
        $clean = HolidayEntry::cleanName($name);
        if ($clean === null || mb_strlen($clean) > self::NAME_MAX) {
            throw new HolidayRefusedException('holiday.centre.error.name');
        }
        $ids = [];
        foreach ($calendarIds as $calendarId) {
            $ids[] = Uuid::assert($calendarId, 'calendar id');
        }
        $this->name        = $clean; // @igor-ignore - entity state, not a service
        $this->calendarIds = array_values(array_unique($ids)); // @igor-ignore - entity state, not a service
        $this->isDefault   = $default; // @igor-ignore - entity state, not a service
    }

    /**
     * Forgets a removed calendar.
     */
    public function forget(string $calendarId): void
    {
        $kept = [];
        foreach ($this->calendarIds() as $id) {
            if ($id !== $calendarId) {
                $kept[] = $id;
            }
        }
        $this->calendarIds = $kept; // @igor-ignore - entity state, not a service
    }

    /** Row id. */
    public function getId(): string
    {
        return $this->id;
    }

    /** Tenant reference. */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    /** Name. */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Calendar ids (only well-formed ids are returned, whatever the stored json holds).
     *
     * @return list<string>
     */
    public function calendarIds(): array
    {
        $ids = [];
        foreach ($this->calendarIds as $id) {
            if (is_string($id) && Uuid::isValid($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** Whether the calendar applies to this centre. */
    public function covers(string $calendarId): bool
    {
        return in_array($calendarId, $this->calendarIds(), true);
    }

    /** Whether this is the tenant's default centre. */
    public function isDefault(): bool
    {
        return $this->isDefault;
    }
}
