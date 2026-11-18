<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Shared;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

use function checkdate;
use function is_string;
use function preg_match;
use function sprintf;
use function substr;

/**
 * A calendar date without time (`Y-m-d`), as a workday's local date or a schedule's validity.
 */
final readonly class LocalDate
{
    /**
     * @param string $value `Y-m-d`
     */
    public function __construct(public string $value)
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException(sprintf('"%s" is not a Y-m-d date.', $value));
        }
    }

    /**
     * A strict `Y-m-d` date, or null when the value is not one (no overflow: `2026-02-31` is refused).
     */
    public static function tryParse(mixed $value): ?self
    {
        try {
            return is_string($value) ? new self($value) : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The local date of an instant in a time zone.
     */
    public static function of(DateTimeInterface $at, DateTimeZone $zone): self
    {
        return new self(DateTimeImmutable::createFromInterface($at)->setTimezone($zone)->format('Y-m-d'));
    }

    /**
     * The date part of a date-time (as it is, no time zone conversion).
     */
    public static function fromDateTime(DateTimeInterface $date): self
    {
        return new self($date->format('Y-m-d'));
    }

    /**
     * Midnight of this date in the zone (UTC when null).
     */
    public function toDateTime(?DateTimeZone $zone = null): DateTimeImmutable
    {
        return new DateTimeImmutable($this->value . ' 00:00:00', $zone ?? new DateTimeZone('UTC'));
    }

    /** `Y-m`. */
    public function month(): string
    {
        return substr($this->value, 0, 7);
    }

    /** The year. */
    public function year(): int
    {
        return (int) substr($this->value, 0, 4);
    }

    /** ISO weekday, 1 (Monday) to 7 (Sunday). */
    public function weekday(): int
    {
        return (int) $this->toDateTime()->format('N');
    }

    /**
     * The date `$days` later (earlier when negative).
     */
    public function plusDays(int $days): self
    {
        return new self($this->toDateTime()->modify(sprintf('%+d days', $days))->format('Y-m-d'));
    }

    /** -1, 0 or 1. */
    public function compare(self $other): int
    {
        return $this->value <=> $other->value;
    }

    /** Same date. */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /** `Y-m-d`. */
    public function __toString(): string
    {
        return $this->value;
    }
}
