<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Infrastructure\Holiday;

use Nowo\OutboundUrlGuardBundle\Guard\ConfiguredAllowPrivateResolver;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuardFactory;
use Nowo\OutboundUrlGuardBundle\Guard\RuntimeOutboundUrlGuard;
use Nowo\WorkingTimeBundle\Application\Holiday\HolidayProviderRegistry;
use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\HolidayOrigin;
use Nowo\WorkingTimeBundle\Domain\Holiday\Enum\HolidayScope;
use Nowo\WorkingTimeBundle\Domain\Holiday\Exception\HolidayRefusedException;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayEntry;
use Nowo\WorkingTimeBundle\Domain\Holiday\HolidayLocation;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Holiday\FestivosIoMunicipalityDirectory;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Holiday\FestivosIoProvider;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Holiday\GuardedJsonClient;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Holiday\OpenHolidaysProvider;
use Nowo\WorkingTimeBundle\Tests\Fixtures\RecordedHolidayApi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_combine;
use function array_map;
use function count;
use function hash;
use function json_encode;

/**
 * The holiday providers on recorded responses only (contract section 20: `MockHttpClient`, no live HTTP, no DNS):
 * festivos.io (national + regional + local of a town, provisional year, attribution, bulletin sources), OpenHolidays
 * (nationwide vs a region, multi-day holidays, subdivisions cached), the municipality directory (cache only on a
 * search), and every failure mapped to the provider's one error (HTTP 404, timeout, bad body, redirect, guard refusal).
 */
final class ProvidersTest extends TestCase
{
    private RecordedHolidayApi $api;

    protected function setUp(): void
    {
        $this->api = new RecordedHolidayApi();
    }

    public function testFestivosIoGivesTheThreeLevelsOfATown(): void
    {
        $provider = new FestivosIoProvider($this->http(), 'https://festivos.io/');
        $result   = $provider->fetch(new HolidayLocation('ES', 'ES-MD', '28079'), 2026, 'ca');

        self::assertSame('festivos_io', $provider->key());
        self::assertSame('holiday.provider.festivos_io.label', $provider->label());
        self::assertSame(FestivosIoProvider::ATTRIBUTION, $result->attribution);
        self::assertSame('Madrid', $result->place);
        self::assertSame('2026-07-08', $result->lastUpdated);
        self::assertFalse($result->provisional);
        self::assertSame('https://festivos.io/v1/2026/municipio/28079.json', $result->sourceUrl);
        self::assertCount(14, $result->entries);
        $byDate = [];
        foreach ($result->entries as $entry) {
            $byDate[$entry->date->value] = $entry;
        }
        self::assertSame(HolidayScope::Regional, $byDate['2026-05-02']->scope);
        self::assertSame(HolidayScope::Local, $byDate['2026-05-15']->scope);
        self::assertSame('San Isidro Labrador', $byDate['2026-05-15']->name, 'Spanish when the language is missing');
        self::assertSame(HolidayOrigin::FestivosIo, $byDate['2026-01-01']->origin);
        self::assertSame('BOE-A-2025-21667', $byDate['2026-01-01']->sourceRef);
        self::assertSame(['https://festivos.io/v1/2026/municipio/28079.json'], $this->api->requests, 'only the town and the year leave the server');
        self::assertTrue($provider->fetch(new HolidayLocation('ES', null, '28079'), 2027, 'es')->provisional, 'local_status: pending');
    }

    public function testFestivosIoNeedsASpanishTown(): void
    {
        $provider = new FestivosIoProvider($this->http());
        self::assertFalse($provider->supports(new HolidayLocation('ES')));
        self::assertFalse($provider->supports(new HolidayLocation('PT', null, '12345')));
        $this->expectExceptionObject(new HolidayRefusedException('holiday.provider.festivos_io.municipality'));
        $provider->fetch(new HolidayLocation('ES'), 2026, 'es');
    }

    public function testFestivosIoSkipsUnusableRows(): void
    {
        $body = json_encode(['year' => 2026, 'municipality' => ['ine' => '00001'], 'holidays' => [
            ['date' => '2026-01-01', 'name' => ['es' => 'Año Nuevo', 'en' => 'New Year'], 'level' => 'national'],
            ['date' => '2026-01-01', 'name' => ['es' => 'Duplicate'], 'level' => 'national'],
            ['date' => '2025-12-31', 'name' => ['es' => 'Last year'], 'level' => 'national'],
            ['date' => '2026-02-30', 'name' => ['es' => 'Bad date'], 'level' => 'national'],
            ['date' => '2026-03-01', 'name' => ['es' => 'Unknown level'], 'level' => 'province'],
            ['date' => '2026-03-02', 'name' => 'not a map', 'level' => 'local'],
            ['date' => '2026-03-03', 'name' => ['es' => ' '], 'level' => 'local'],
            ['date' => '2026-03-04', 'name' => ['fr' => 'Premier'], 'level' => 'local', 'source' => ['ref' => 'X', 'url' => 'ftp://x']],
            ['date' => '2026-03-05', 'name' => ['es' => "\u{0001}"], 'level' => 'local'],
            'garbage',
        ]]);
        $provider = new FestivosIoProvider(new GuardedJsonClient($this->guard(), new MockHttpClient(new MockResponse((string) $body))));
        $result   = $provider->fetch(new HolidayLocation('ES', null, '00001'), 2026, 'en');

        self::assertSame(['2026-01-01' => 'New Year', '2026-03-04' => 'Premier'], array_combine(array_map(static fn (HolidayEntry $e): string => $e->date->value, $result->entries), array_map(static fn (HolidayEntry $e): string => $e->name, $result->entries)));
        self::assertNull($result->entries[1]->sourceUrl, 'only http(s) sources are kept');
        self::assertNull($result->place);
        self::assertNull($result->lastUpdated);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function failures(): iterable
    {
        yield 'unknown town or year (404)' => ['404'];
        yield 'timeout' => ['timeout'];
        yield 'not JSON' => ['body'];
        yield 'a redirect is never followed' => ['redirect'];
        yield 'another year in the body' => ['year'];
    }

    #[DataProvider('failures')]
    public function testEveryFestivosIoFailureIsOneError(string $fail): void
    {
        $this->api->fail = $fail;
        $this->expectExceptionObject(new HolidayRefusedException(FestivosIoProvider::ERROR));
        (new FestivosIoProvider($this->http()))->fetch(new HolidayLocation('ES', null, '28079'), 2026, 'es');
    }

    #[DataProvider('failures')]
    public function testEveryOpenHolidaysFailureIsOneError(string $fail): void
    {
        $this->api->fail = $fail === 'year' ? 'body' : $fail;
        $this->expectExceptionObject(new HolidayRefusedException(OpenHolidaysProvider::ERROR));
        (new OpenHolidaysProvider($this->http()))->fetch(new HolidayLocation('ES'), 2026, 'es');
    }

    public function testTheGuardRefusesAPrivateHost(): void
    {
        $provider = new FestivosIoProvider($this->http(), 'https://127.0.0.1');
        try {
            $provider->fetch(new HolidayLocation('ES', null, '28079'), 2026, 'es');
            self::fail('refused');
        } catch (HolidayRefusedException $e) {
            self::assertSame(FestivosIoProvider::ERROR, $e->key);
        }
        self::assertSame([], $this->api->requests, 'nothing was sent');
    }

    public function testOpenHolidaysNationalAndRegional(): void
    {
        $provider = new OpenHolidaysProvider($this->http(), new ArrayAdapter());
        $national = $provider->fetch(new HolidayLocation('es'), 2026, 'es_ES');
        $regional = $provider->fetch(new HolidayLocation('ES', 'ES-MD'), 2026, 'es');

        self::assertSame('openholidays', $provider->key());
        self::assertSame('holiday.provider.openholidays.label', $provider->label());
        self::assertTrue($provider->supports(new HolidayLocation('DE')));
        self::assertNotSame([], $national->entries);
        foreach ($national->entries as $entry) {
            self::assertSame(HolidayScope::National, $entry->scope);
            self::assertSame(HolidayOrigin::OpenHolidays, $entry->origin);
        }
        self::assertSame(['2026-04-02', '2026-05-02', '2026-07-25', '2026-11-02', '2026-12-07'], array_map(static fn (HolidayEntry $e): string => $e->date->value, $regional->entries), 'the region\'s own holidays only');
        self::assertSame(HolidayScope::Regional, $regional->entries[0]->scope);
        self::assertSame('ES-MD', $regional->place);
        self::assertStringContainsString('subdivisionCode=ES-MD', $regional->sourceUrl);
        self::assertStringContainsString('languageIsoCode=ES', $this->api->requests[0]);
    }

    public function testOpenHolidaysSpansAndSkips(): void
    {
        $body = (string) json_encode([
            ['type' => 'Public', 'nationwide' => true, 'startDate' => '2025-12-31', 'endDate' => '2026-01-02', 'name' => [['language' => 'EN', 'text' => 'Turn of the year'], ['language' => 'DE', 'text' => 'Jahreswechsel']]],
            ['type' => 'Public', 'nationwide' => true, 'startDate' => '2026-12-31', 'endDate' => '2027-01-01', 'name' => [['language' => 'XX', 'text' => ' '], ['text' => 'Silvester']]],
            ['type' => 'Bank', 'nationwide' => true, 'startDate' => '2026-03-01', 'name' => [['language' => 'DE', 'text' => 'Bank']]],
            ['type' => 'Public', 'nationwide' => false, 'startDate' => '2026-03-02', 'name' => [['language' => 'DE', 'text' => 'Regional']]],
            ['type' => 'Public', 'nationwide' => true, 'startDate' => 'soon', 'name' => [['language' => 'DE', 'text' => 'Bad']]],
            ['type' => 'Public', 'nationwide' => true, 'startDate' => '2026-03-03', 'name' => 'flat'],
            ['type' => 'Public', 'nationwide' => true, 'startDate' => '2026-03-04', 'name' => [['language' => 'DE', 'text' => "\u{0001}"], 'x']],
            'garbage',
        ]);
        $provider = new OpenHolidaysProvider(new GuardedJsonClient($this->guard(), new MockHttpClient(new MockResponse($body))));
        $result   = $provider->fetch(new HolidayLocation('DE'), 2026, 'de');

        self::assertSame(['2026-01-01' => 'Jahreswechsel', '2026-01-02' => 'Jahreswechsel', '2026-12-31' => 'Silvester'], array_combine(array_map(static fn (HolidayEntry $e): string => $e->date->value, $result->entries), array_map(static fn (HolidayEntry $e): string => $e->name, $result->entries)));
        $this->expectExceptionObject(new HolidayRefusedException(OpenHolidaysProvider::ERROR));
        (new OpenHolidaysProvider(new GuardedJsonClient($this->guard(), new MockHttpClient(new MockResponse('{"a": 1}')))))->fetch(new HolidayLocation('DE'), 2026, '');
    }

    public function testOpenHolidaysSubdivisionsAreCachedADay(): void
    {
        $cache    = new ArrayAdapter();
        $provider = new OpenHolidaysProvider($this->http(), $cache);
        $regions  = $provider->subdivisions('es', 'es');

        self::assertSame('Comunidad de Madrid', $regions['ES-MD'] ?? null);
        self::assertArrayNotHasKey('ES-AN-GR', $regions, 'top-level regions only');
        $this->api->fail = 'timeout';
        self::assertSame($regions, $provider->subdivisions('ES', 'ES'), 'served from the cache');
        self::assertCount(1, $this->api->requests);
        $this->expectExceptionObject(new HolidayRefusedException(OpenHolidaysProvider::ERROR));
        $provider->subdivisions('Spain', 'es');
    }

    public function testOpenHolidaysSubdivisionsWithoutACache(): void
    {
        $regions = (new OpenHolidaysProvider($this->http()))->subdivisions('ES', 'es');
        self::assertArrayHasKey('ES-CT', $regions);
        $regions = (new OpenHolidaysProvider($this->http()))->subdivisions('ES', 'es');
        self::assertCount(2, $this->api->requests, 'no cache: asked again');
        self::assertNotSame([], $regions);
    }

    public function testTheMunicipalityDirectorySearchesTheCacheOnly(): void
    {
        $cache     = new ArrayAdapter();
        $directory = new FestivosIoMunicipalityDirectory($this->http(), $cache);

        self::assertFalse($directory->isLoaded());
        self::assertSame([], $directory->search('madrid'));
        self::assertNull($directory->name('28079'));
        self::assertSame([], $this->api->requests, 'a search never calls out');
        self::assertSame(20, $directory->refresh());
        self::assertTrue($directory->isLoaded());
        self::assertSame(['28079', '28058', '28065'], array_map(static fn (array $r): string => $r['value'], $directory->search('MADRID')), 'the town first, then the province\'s towns');
        self::assertSame([['value' => '28079', 'text' => 'Madrid (Madrid) · 28079']], $directory->search('Madrid', 1));
        self::assertSame('08019', $directory->search('barcel')[0]['value']);
        self::assertSame(['28058', '28065', '28079'], array_map(static fn (array $r): string => $r['value'], $directory->search('280')), 'digits match the code\'s start');
        self::assertSame('01001', $directory->search('alava', 1)[0]['value'], 'the province name, accents aside');
        self::assertSame([], $directory->search('m'));
        self::assertSame([], $directory->search('zzzz'));
        self::assertSame('Madrid (Madrid)', $directory->name('28079'));
        self::assertNull($directory->name('99999'));
        self::assertCount(1, $this->api->requests);
        self::assertGreaterThan(1, count($directory->search('ma', 500)));
    }

    public function testTheMunicipalityDirectoryRefusesAnEmptyListAndKeepsTheOldOne(): void
    {
        $cache = new ArrayAdapter();
        $cache->save($cache->getItem('nowo_wt.festivos_io.municipalities.' . hash('xxh128', 'https://festivos.io'))->set([['28079', 'Madrid', ''], 'bad', [1, 2, 3]]));
        $directory = new FestivosIoMunicipalityDirectory(new GuardedJsonClient($this->guard(), new MockHttpClient(new MockResponse('{"municipalities": [{"ine": "1", "name": "Bad"}, "x"]}'))), $cache);

        self::assertSame('Madrid', $directory->name('28079'), 'no province: the name alone');
        try {
            $directory->refresh();
            self::fail('empty');
        } catch (HolidayRefusedException $e) {
            self::assertSame(FestivosIoProvider::ERROR, $e->key);
        }
        self::assertTrue($directory->isLoaded());
    }

    public function testTheRegistryKnowsTheEnabledProviders(): void
    {
        $festivos = new FestivosIoProvider($this->http());
        $open     = new OpenHolidaysProvider($this->http());
        $registry = new HolidayProviderRegistry([$festivos, $open], ['openholidays']);

        self::assertSame([$festivos], $registry->all());
        self::assertSame($festivos, $registry->get('festivos_io'));
        $this->expectExceptionObject(new HolidayRefusedException('holiday.provider.error.unknown'));
        $registry->get('openholidays');
    }

    private function http(): GuardedJsonClient
    {
        return new GuardedJsonClient($this->guard(), RecordedHolidayApi::client($this->api));
    }

    private function guard(): RuntimeOutboundUrlGuard
    {
        return new RuntimeOutboundUrlGuard(new OutboundUrlGuardFactory(new ConfiguredAllowPrivateResolver(false), false));
    }
}
