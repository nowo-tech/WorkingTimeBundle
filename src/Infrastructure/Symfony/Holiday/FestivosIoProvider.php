<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Holiday;

use InvalidArgumentException;
use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\HolidayOrigin;
use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\HolidayScope;
use Nowo\WorkingTimeBundle\Domain\Holiday\Exception\HolidayRefusedException;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayEntry;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayFetchResult;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayLocation;
use Nowo\WorkingTimeBundle\Domain\Port\HolidayProviderInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\LocalDate;

use function array_filter;
use function is_array;
use function is_string;
use function ksort;
use function mb_substr;
use function reset;
use function rtrim;
use function str_ends_with;
use function strtolower;
use function substr;
use function trim;

/**
 * Spain's public holidays of one municipality from festivos.io (contract section 9.2; Beacon's `FestivosIoClient`,
 * owner decision D-20261112-12): static files, no key, `GET /v1/{year}/municipio/{ine}.json` gives the national,
 * regional and local holidays of the town in one call, each with its level and its bulletin source; a year whose local
 * holidays are not published yet carries `local_status: pending` (provisional). CC BY 4.0: every screen that shows an
 * import names it ({@see self::ATTRIBUTION}). Only the INE code and the year leave the server.
 */
final readonly class FestivosIoProvider implements HolidayProviderInterface
{
    /** Provider key. */
    public const KEY = 'festivos_io';

    /** The licence notice to show with the data. */
    public const ATTRIBUTION = 'festivos.io — CC BY 4.0 — https://festivos.io';

    /** Error translation key. */
    public const ERROR = 'holiday.provider.festivos_io.error';

    /** festivos.io levels and the calendar scope each maps to. */
    private const LEVELS = ['national' => HolidayScope::National, 'regional' => HolidayScope::Regional, 'local' => HolidayScope::Local];

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private GuardedJsonClient $http,
        private string $baseUri = 'https://festivos.io',
        private int $timeout = 10,
    ) {
    }

    /**
     * See the implemented interface.
     */
    public function key(): string
    {
        return self::KEY;
    }

    /**
     * See the implemented interface.
     */
    public function label(): string
    {
        return 'holiday.provider.festivos_io.label';
    }

    /**
     * See the implemented interface.
     */
    public function attribution(): string
    {
        return self::ATTRIBUTION;
    }

    /**
     * See the implemented interface.
     */
    public function supports(HolidayLocation $location): bool
    {
        return $location->country === 'ES' && $location->municipality !== null;
    }

    /**
     * See the implemented interface.
     */
    public function fetch(HolidayLocation $location, int $year, string $language): HolidayFetchResult
    {
        if (!$this->supports($location)) {
            throw new HolidayRefusedException('holiday.provider.festivos_io.municipality');
        }
        $url  = $this->sourceUrl((string) $location->municipality, $year);
        $body = $this->http->get($url, [], $this->timeout, self::ERROR);
        if (!is_array($body['holidays'] ?? null) || ($body['year'] ?? null) !== $year) {
            throw new HolidayRefusedException(self::ERROR);
        }
        $language = strtolower($language);
        $entries  = [];
        foreach ($body['holidays'] as $row) {
            $entry = is_array($row) ? $this->entry($row, $year, $language) : null;
            if ($entry instanceof HolidayEntry && !isset($entries[$entry->date->value])) {
                $entries[$entry->date->value] = $entry;
            }
        }
        ksort($entries);
        $municipality = is_array($body['municipality'] ?? null) ? $body['municipality'] : [];
        $place        = is_string($municipality['name'] ?? null) ? mb_substr($municipality['name'], 0, 80) : null;
        $list         = [];
        foreach ($entries as $entry) {
            $list[] = $entry;
        }

        return new HolidayFetchResult(
            $list,
            $url,
            $place,
            is_string($body['last_updated'] ?? null) ? substr($body['last_updated'], 0, 10) : null,
            $this->provisional($body),
            self::ATTRIBUTION,
        );
    }

    /** The URL of a town's year (a public file, no secret): the calendar's source note. */
    public function sourceUrl(string $municipality, int $year): string
    {
        return rtrim($this->baseUri, '/') . '/v1/' . $year . '/municipio/' . $municipality . '.json';
    }

    /**
     * A year is provisional while any of its levels is pending (`local_status: pending` for next year).
     *
     * @param array<mixed> $body
     */
    private function provisional(array $body): bool
    {
        foreach ($body as $key => $value) {
            if (is_string($key) && str_ends_with($key, '_status') && $value === 'pending') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $row
     */
    private function entry(array $row, int $year, string $language): ?HolidayEntry
    {
        $level = is_string($row['level'] ?? null) ? (self::LEVELS[$row['level']] ?? null) : null;
        $date  = LocalDate::tryParse($row['date'] ?? null);
        $name  = $this->name($row['name'] ?? null, $language);
        if ($level === null || !$date instanceof LocalDate || $date->year() !== $year || $name === null) {
            return null;
        }
        $source = is_array($row['source'] ?? null) ? $row['source'] : [];
        try {
            return new HolidayEntry($date, $name, $level, HolidayOrigin::FestivosIo, is_string($source['ref'] ?? null) ? $source['ref'] : null, is_string($source['url'] ?? null) ? $source['url'] : null);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The name in the language, else Spanish, else the first one (`{"es": "…", "ca": "…"}`).
     */
    private function name(mixed $names, string $language): ?string
    {
        if (!is_array($names)) {
            return null;
        }
        $texts = array_filter($names, static fn (mixed $text): bool => is_string($text) && trim($text) !== '');
        $text  = $texts[$language] ?? $texts['es'] ?? reset($texts);

        return is_string($text) ? trim($text) : null;
    }
}
