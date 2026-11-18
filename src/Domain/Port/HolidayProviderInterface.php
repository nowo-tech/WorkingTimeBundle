<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Port;

use Nowo\WorkingTimeBundle\Domain\Holiday\Exception\HolidayRefusedException;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayFetchResult;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayLocation;

/**
 * A source of official holidays (contract section 8.2), tagged `nowo_working_time.holiday_provider`. Built in:
 * `festivos_io` (Spain, by INE municipality) and `openholidays` (OpenHolidays API, country / subdivision); hosts add
 * their own (another country's official feed) without overriding anything. A provider runs only on an admin action
 * (preview, then confirm), sends nothing but the location and the year, and maps every failure to one translated
 * error (`holiday.provider.<key>.error`).
 */
interface HolidayProviderInterface
{
    /** Stable key (`[a-z][a-z0-9_]*`, ≤ 40), stored on the calendar. */
    public function key(): string;

    /** Translation key of the provider's name (domain `NowoWorkingTimeBundle`) or a plain label. */
    public function label(): string;

    /** The attribution every screen that shows its data must carry, or null. */
    public function attribution(): ?string;

    /** Whether the provider can answer for the location (country, subdivision, municipality). */
    public function supports(HolidayLocation $location): bool;

    /**
     * The holidays of the location and year, names in the language when the source has it.
     *
     * @throws HolidayRefusedException one translated error per provider; nothing is saved
     */
    public function fetch(HolidayLocation $location, int $year, string $language): HolidayFetchResult;
}
