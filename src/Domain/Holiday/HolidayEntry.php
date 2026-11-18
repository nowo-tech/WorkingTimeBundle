<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Holiday;

use InvalidArgumentException;
use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\HolidayOrigin;
use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\HolidayScope;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;

use function mb_strlen;
use function mb_substr;
use function preg_match;
use function preg_replace;
use function trim;

/**
 * One public holiday as the component returns it (contract section 5): the date, the public name (1–120 characters,
 * control and format characters stripped), the calendar scope, where it came from and the official source a provider
 * cites (a bulletin reference and an http(s) URL).
 */
final readonly class HolidayEntry
{
    /** Longest stored name. */
    public const NAME_MAX = 120;

    /** The cleaned name. */
    public string $name;

    /** The source reference (≤ 120), or null. */
    public ?string $sourceRef;

    /** The http(s) source URL (≤ 255), or null. */
    public ?string $sourceUrl;

    /**
     * Wires the values (the name is cleaned; an empty name is refused).
     */
    public function __construct(
        public LocalDate $date,
        string $name,
        public HolidayScope $scope = HolidayScope::National,
        public HolidayOrigin $origin = HolidayOrigin::Manual,
        ?string $sourceRef = null,
        ?string $sourceUrl = null,
        public ?string $calendarId = null,
        public ?string $provider = null,
    ) {
        $this->name      = self::cleanName($name) ?? throw new InvalidArgumentException('holiday.day.error.name');
        $ref             = $sourceRef === null ? null : self::cleanName($sourceRef);
        $this->sourceRef = $ref;
        $this->sourceUrl = self::cleanUrl($sourceUrl);
    }

    /**
     * Whether the entry came from the given source: the same origin and, for a host provider (origin `provider`), the
     * same provider key (audit TD-L4: one host provider never removes another's days).
     */
    public function isFrom(HolidayOrigin $origin, ?string $provider): bool
    {
        return $this->origin === $origin && ($origin !== HolidayOrigin::Provider || $this->provider === $provider);
    }

    /**
     * A name without control or format characters, trimmed, cut at 120 characters; null when nothing is left.
     */
    public static function cleanName(string $name): ?string
    {
        $clean = trim((string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $name));
        if ($clean === '' || preg_match('//u', $clean) !== 1) {
            return null;
        }

        return mb_strlen($clean) > self::NAME_MAX ? trim(mb_substr($clean, 0, self::NAME_MAX)) : $clean;
    }

    /**
     * An http(s) URL of at most 255 characters without spaces or control characters, else null.
     */
    public static function cleanUrl(?string $url): ?string
    {
        if ($url === null || mb_strlen($url) > 255 || preg_match('#^https?://[^\s\p{C}]+$#Du', $url) !== 1) {
            return null;
        }

        return $url;
    }
}
