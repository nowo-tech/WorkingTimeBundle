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
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

use function array_is_list;
use function asort;
use function hash;
use function http_build_query;
use function is_array;
use function is_string;
use function ksort;
use function preg_match;
use function rtrim;
use function sprintf;
use function strtoupper;
use function trim;

/**
 * Public holidays from the OpenHolidays API (contract section 9.2; Beacon's `OpenHolidaysClient`):
 * `GET /PublicHolidays?countryIsoCode&languageIsoCode&validFrom&validTo[&subdivisionCode]`, `GET /Subdivisions`.
 *
 * - A national calendar (no subdivision) keeps the holidays marked `nationwide`.
 * - A regional calendar asks for its subdivision (`ES-MD`) and keeps that region's own holidays (not nationwide);
 *   OpenHolidays has no municipalities.
 * - Only `type: Public`; a holiday spanning several days gives one row per day.
 *
 * Only the country, the subdivision, the language and the year leave the server. Subdivisions are cached a day in the
 * configured pool, keyed by base URI and country (public data, never per person).
 */
final readonly class OpenHolidaysProvider implements HolidayProviderInterface
{
    /** Provider key. */
    public const KEY = 'openholidays';

    /** Error translation key. */
    public const ERROR = 'holiday.provider.openholidays.error';

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private GuardedJsonClient $http,
        private ?CacheItemPoolInterface $cache = null,
        private string $baseUri = 'https://openholidaysapi.org',
        private int $timeout = 10,
        private int $subdivisionsTtl = 86400,
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
        return 'holiday.provider.openholidays.label';
    }

    /**
     * See the implemented interface.
     */
    public function attribution(): string
    {
        return 'OpenHolidays API — https://www.openholidaysapi.org';
    }

    /**
     * See the implemented interface.
     */
    public function supports(HolidayLocation $location): bool
    {
        return true;
    }

    /**
     * See the implemented interface.
     */
    public function fetch(HolidayLocation $location, int $year, string $language): HolidayFetchResult
    {
        $language = $this->language($language);
        $query    = ['countryIsoCode' => $location->country, 'languageIsoCode' => $language, 'validFrom' => sprintf('%d-01-01', $year), 'validTo' => sprintf('%d-12-31', $year)];
        if ($location->subdivision !== null) {
            $query['subdivisionCode'] = $location->subdivision;
        }
        $url     = rtrim($this->baseUri, '/') . '/PublicHolidays';
        $scope   = $location->subdivision === null ? HolidayScope::National : HolidayScope::Regional;
        $entries = [];
        foreach ($this->list($url, $query) as $row) {
            if (!is_array($row) || ($row['type'] ?? null) !== 'Public' || ($location->subdivision === null) !== (($row['nationwide'] ?? false) === true)) {
                continue;
            }
            $name  = $this->text($row['name'] ?? null, $language);
            $start = LocalDate::tryParse($row['startDate'] ?? null);
            $end   = LocalDate::tryParse($row['endDate'] ?? null) ?? $start;
            if ($name === null || !$start instanceof LocalDate || !$end instanceof LocalDate) {
                continue;
            }
            for ($day = $start; $day->compare($end) <= 0 && $day->year() <= $year; $day = $day->plusDays(1)) {
                if ($day->year() === $year && !isset($entries[$day->value])) {
                    try {
                        $entries[$day->value] = new HolidayEntry($day, $name, $scope, HolidayOrigin::OpenHolidays);
                    } catch (InvalidArgumentException) {
                        continue;
                    }
                }
            }
        }
        ksort($entries);
        $list = [];
        foreach ($entries as $entry) {
            $list[] = $entry;
        }

        return new HolidayFetchResult($list, $url . '?' . http_build_query($query), $location->subdivision ?? $location->country, null, false, $this->attribution());
    }

    /**
     * The country's top-level subdivisions (regions), cached for `subdivisions_ttl` seconds.
     *
     * @throws HolidayRefusedException `holiday.provider.openholidays.error`
     *
     * @return array<string, string> code => name, by name
     */
    public function subdivisions(string $country, string $language): array
    {
        $country  = strtoupper(trim($country));
        $language = $this->language($language);
        if (preg_match('/^[A-Z]{2}$/D', $country) !== 1) {
            throw new HolidayRefusedException(self::ERROR);
        }
        $key  = 'nowo_wt.openholidays.subdivisions.' . hash('xxh128', $this->baseUri) . '.' . $country . '.' . $language;
        $item = $this->cache?->getItem($key);
        if ($item instanceof CacheItemInterface && $item->isHit() && is_array($item->get())) {
            /** @var array<string, string> $cached */
            $cached = $item->get();

            return $cached;
        }
        $regions = [];
        foreach ($this->list(rtrim($this->baseUri, '/') . '/Subdivisions', ['countryIsoCode' => $country, 'languageIsoCode' => $language]) as $row) {
            if (is_array($row) && is_string($row['code'] ?? null) && preg_match('/^[A-Z]{2}-[A-Z0-9]{1,3}$/D', $row['code']) === 1) {
                $regions[$row['code']] = $this->text($row['name'] ?? null, $language) ?? $row['code'];
            }
        }
        asort($regions);
        if ($item instanceof CacheItemInterface && $this->cache instanceof CacheItemPoolInterface) {
            $this->cache->save($item->set($regions)->expiresAfter($this->subdivisionsTtl));
        }

        return $regions;
    }

    /**
     * @param array<string, string> $query
     *
     * @throws HolidayRefusedException
     *
     * @return list<mixed>
     */
    private function list(string $url, array $query): array
    {
        $body = $this->http->get($url, $query, $this->timeout, self::ERROR);
        if (!array_is_list($body)) {
            throw new HolidayRefusedException(self::ERROR);
        }

        return $body;
    }

    /** An ISO 639-1 code in capitals (`EN` for anything else). */
    private function language(string $language): string
    {
        $code = strtoupper(trim($language));

        return preg_match('/^([A-Z]{2})(?:[_-].*)?$/D', $code, $m) === 1 ? $m[1] : 'EN';
    }

    /** The localized text in the language, else the first one. */
    private function text(mixed $texts, string $language): ?string
    {
        if (!is_array($texts)) {
            return null;
        }
        $first = null;
        foreach ($texts as $text) {
            if (!is_array($text) || !is_string($text['text'] ?? null) || trim($text['text']) === '') {
                continue;
            }
            $first ??= trim($text['text']);
            if (is_string($text['language'] ?? null) && strtoupper($text['language']) === $language) {
                return trim($text['text']);
            }
        }

        return $first;
    }
}
