<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Holiday\Model;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\HolidayScope;
use Nowo\WorkingTimeBundle\Domain\Holiday\Exception\HolidayRefusedException;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayEntry;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayLocation;
use Nowo\WorkingTimeBundle\Domain\Shared\Uuid;

use function mb_strlen;
use function mb_substr;
use function preg_match;
use function trim;

/**
 * A holiday calendar of a tenant for one year (contract section 6.7; spec 204 FR-008): national, regional or local,
 * with the place it covers (country, subdivision, INE municipality) for the providers, the provider that filled it
 * last, whether that year is still provisional, the attribution the provider requires and a source note (the bulletin
 * or the URL it was read from). Work centres group calendars; a calendar works without the register or leave.
 */
class HolidayCalendar
{
    /** Longest name. */
    public const NAME_MAX = 80;

    /** Provider key of a calendar filled by hand. */
    public const MANUAL = 'manual';

    private string $name = '';

    private HolidayScope $scope = HolidayScope::National;

    private int $year = 2026;

    private ?string $country = null;

    private ?string $subdivision = null;

    private ?string $municipalityCode = null;

    private string $provider = self::MANUAL;

    private bool $provisional = false;

    private ?string $attribution = null;

    private ?string $sourceNote = null;

    private ?DateTimeImmutable $lastFetchedAt = null;

    private function __construct(
        private string $id,
        private string $tenantId,
        private DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * A new calendar.
     *
     * @throws HolidayRefusedException `holiday.calendar.error.*`
     */
    public static function create(string $id, string $tenantId, string $name, HolidayScope $scope, int $year, ?string $sourceNote, ?HolidayLocation $location, DateTimeImmutable $at): self
    {
        $calendar = new self(Uuid::assert($id), $tenantId, $at->setTimezone(new DateTimeZone('UTC')));
        $calendar->change($name, $scope, $year, $sourceNote);
        $calendar->locate($location);

        return $calendar;
    }

    /**
     * Name, scope, year and source note.
     *
     * @throws HolidayRefusedException `holiday.calendar.error.name` | `holiday.calendar.error.year`
     */
    public function change(string $name, HolidayScope $scope, int $year, ?string $sourceNote): void
    {
        $clean = HolidayEntry::cleanName($name);
        if ($clean === null || mb_strlen($clean) > self::NAME_MAX) {
            throw new HolidayRefusedException('holiday.calendar.error.name');
        }
        if ($year < 2000 || $year > 2100) {
            throw new HolidayRefusedException('holiday.calendar.error.year');
        }
        $note             = $sourceNote === null ? null : HolidayEntry::cleanName($sourceNote);
        $this->name       = $clean; // @igor-ignore - entity state, not a service
        $this->scope      = $scope; // @igor-ignore - entity state, not a service
        $this->year       = $year; // @igor-ignore - entity state, not a service
        $this->sourceNote = $note === null ? null : mb_substr($note, 0, 255); // @igor-ignore - entity state, not a service
    }

    /**
     * The place the providers look up (null clears it).
     */
    public function locate(?HolidayLocation $location): void
    {
        $this->country          = $location?->country; // @igor-ignore - entity state, not a service
        $this->subdivision      = $location?->subdivision; // @igor-ignore - entity state, not a service
        $this->municipalityCode = $location?->municipality; // @igor-ignore - entity state, not a service
    }

    /**
     * A provider filled the calendar (confirm of a provider import).
     *
     * @throws HolidayRefusedException `holiday.calendar.error.provider`
     */
    public function recordFetch(string $provider, bool $provisional, ?string $attribution, ?string $sourceNote, DateTimeImmutable $at): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $provider) !== 1) {
            throw new HolidayRefusedException('holiday.calendar.error.provider');
        }
        $this->provider      = $provider; // @igor-ignore - entity state, not a service
        $this->provisional   = $provisional; // @igor-ignore - entity state, not a service
        $this->attribution   = $attribution === null ? null : mb_substr(trim($attribution), 0, 255); // @igor-ignore - entity state, not a service
        $note                = $sourceNote === null ? null : HolidayEntry::cleanName($sourceNote);
        $this->sourceNote    = $note === null ? $this->sourceNote : mb_substr($note, 0, 255); // @igor-ignore - entity state, not a service
        $this->lastFetchedAt = $at->setTimezone(new DateTimeZone('UTC')); // @igor-ignore - entity state, not a service
    }

    /**
     * A file import filled the calendar (the provider key becomes `file` unless a provider filled it before).
     */
    public function recordFileImport(): void
    {
        if ($this->provider === self::MANUAL) {
            $this->provider = 'file'; // @igor-ignore - entity state, not a service
        }
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

    /** Scope. */
    public function getScope(): HolidayScope
    {
        return $this->scope;
    }

    /** Year. */
    public function getYear(): int
    {
        return $this->year;
    }

    /** The place (null when none is set). */
    public function location(): ?HolidayLocation
    {
        return $this->country === null ? null : new HolidayLocation($this->country, $this->subdivision, $this->municipalityCode);
    }

    /** Country (ISO 3166-1 alpha-2). */
    public function getCountry(): ?string
    {
        return $this->country;
    }

    /** Subdivision (ISO 3166-2). */
    public function getSubdivision(): ?string
    {
        return $this->subdivision;
    }

    /** INE municipality code. */
    public function getMunicipalityCode(): ?string
    {
        return $this->municipalityCode;
    }

    /** Provider key that filled it last (`manual`, `file`, `festivos_io`, `openholidays`, a host key). */
    public function getProvider(): string
    {
        return $this->provider;
    }

    /** Whether the provider said the year is not final yet. */
    public function isProvisional(): bool
    {
        return $this->provisional;
    }

    /** Attribution the provider requires (festivos.io CC BY 4.0). */
    public function getAttribution(): ?string
    {
        return $this->attribution;
    }

    /** Source note. */
    public function getSourceNote(): ?string
    {
        return $this->sourceNote;
    }

    /** When a provider filled it last. */
    public function getLastFetchedAt(): ?DateTimeImmutable
    {
        return $this->lastFetchedAt;
    }

    /** When it was created. */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
