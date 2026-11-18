<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Holiday;

use Nowo\WorkingTimeBundle\Domain\Holiday\Exception\HolidayRefusedException;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayLocation;
use Nowo\WorkingTimeBundle\Domain\Holiday\Service\MunicipalityDirectoryInterface;
use Psr\Cache\CacheItemPoolInterface;

use function array_slice;
use function count;
use function hash;
use function is_array;
use function is_string;
use function max;
use function mb_strlen;
use function mb_substr;
use function min;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function Symfony\Component\String\u;
use function usort;

/**
 * Spain's municipalities and their INE codes (contract section 7; Beacon's `MunicipalityDirectory`,
 * D-20261112-17): festivos.io's `/v1/ref/municipios.json` (about 8 100 towns from the INE dictionary), fetched only by
 * {@see self::refresh()} (an admin action) through the outbound guard, and kept in the configured cache pool for
 * `municipalities_ttl` (a week). A search reads the cache and never calls out. Place names only, no personal data.
 */
final readonly class FestivosIoMunicipalityDirectory implements MunicipalityDirectoryInterface
{
    /** Largest result list. */
    public const MAX_RESULTS = 50;

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private GuardedJsonClient $http,
        private CacheItemPoolInterface $cache,
        private string $baseUri = 'https://festivos.io',
        private int $timeout = 10,
        private int $ttl = 604800,
    ) {
    }

    /**
     * See the implemented interface.
     */
    public function search(string $query, int $limit = 20): array
    {
        $towns = $this->cached();
        $query = $this->fold($query);
        if ($towns === null || mb_strlen($query) < 2) {
            return [];
        }
        $digits = preg_match('/^\d+$/D', $query) === 1;
        $ranked = [];
        foreach ($towns as [$code, $name, $province]) {
            $folded = $this->fold($name);
            $rank   = match (true) {
                $digits                                      => str_starts_with($code, $query) ? 0 : null,
                str_starts_with($folded, $query)             => 0,
                str_contains($folded, $query)                => 1,
                str_contains($this->fold($province), $query) => 2,
                default                                      => null,
            };
            if ($rank !== null) {
                $ranked[] = [$rank, $folded, ['value' => $code, 'text' => sprintf('%s (%s) · %s', $name, $province, $code)]];
            }
        }
        usort($ranked, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $out = [];
        foreach (array_slice($ranked, 0, max(1, min(self::MAX_RESULTS, $limit))) as $row) {
            $out[] = $row[2];
        }

        return $out;
    }

    /**
     * See the implemented interface.
     */
    public function name(string $ineCode): ?string
    {
        foreach ($this->cached() ?? [] as [$code, $name, $province]) {
            if ($code === $ineCode) {
                return $province === '' ? $name : sprintf('%s (%s)', $name, $province);
            }
        }

        return null;
    }

    /**
     * See the implemented interface.
     */
    public function isLoaded(): bool
    {
        return $this->cached() !== null;
    }

    /**
     * See the implemented interface.
     */
    public function refresh(): int
    {
        $body  = $this->http->get(rtrim($this->baseUri, '/') . '/v1/ref/municipios.json', [], $this->timeout, FestivosIoProvider::ERROR);
        $towns = [];
        foreach (is_array($body['municipalities'] ?? null) ? $body['municipalities'] : [] as $row) {
            if (is_array($row) && is_string($row['ine'] ?? null) && HolidayLocation::isMunicipalityCode($row['ine']) && is_string($row['name'] ?? null)) {
                $towns[] = [$row['ine'], mb_substr($row['name'], 0, 80), is_string($row['province_name'] ?? null) ? mb_substr($row['province_name'], 0, 80) : ''];
            }
        }
        if ($towns === []) {
            throw new HolidayRefusedException(FestivosIoProvider::ERROR);
        }
        $this->cache->save($this->cache->getItem($this->key())->set($towns)->expiresAfter($this->ttl));

        return count($towns);
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>|null
     */
    private function cached(): ?array
    {
        $item = $this->cache->getItem($this->key());
        if (!$item->isHit() || !is_array($item->get())) {
            return null;
        }
        $towns = [];
        foreach ($item->get() as $row) {
            if (is_array($row) && is_string($row[0] ?? null) && is_string($row[1] ?? null) && is_string($row[2] ?? null)) {
                $towns[] = [$row[0], $row[1], $row[2]];
            }
        }

        return $towns;
    }

    private function key(): string
    {
        return 'nowo_wt.festivos_io.municipalities.' . hash('xxh128', $this->baseUri);
    }

    private function fold(string $text): string
    {
        return u($text)->ascii()->lower()->trim()->toString();
    }
}
