<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Fixtures;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function file_get_contents;
use function http_build_query;
use function is_array;
use function is_file;
use function parse_url;
use function preg_match;
use function sprintf;

use const PHP_URL_PATH;

/**
 * The holiday providers' APIs from recorded responses (tests/Fixtures/holidays, real festivos.io and OpenHolidays
 * answers recorded 2026-11-18; the municipality list trimmed to 20 towns): no live HTTP call ever leaves a test.
 * `$fail` turns the next answers into a failure (`404`, `timeout`, `body`, `redirect`, `year`); `$requests` keeps what was asked.
 */
final class RecordedHolidayApi
{
    /** Recorded responses. */
    public const DIR = __DIR__ . '/holidays';

    public ?string $fail = null;

    /** @var list<string> URL with its query, per request */
    public array $requests = [];

    /**
     * A mock client answering from the recordings.
     */
    public static function client(self $api): MockHttpClient
    {
        return new MockHttpClient(static fn (string $method, string $url, array $options): MockResponse => $api->answer($method, $url, $options));
    }

    /**
     * @param array<mixed> $options
     */
    public function answer(string $method, string $url, array $options): MockResponse
    {
        $query            = is_array($options['query'] ?? null) && $options['query'] !== [] ? '?' . http_build_query($options['query']) : '';
        $this->requests[] = $url . $query;

        return match ($this->fail) {
            '404'      => new MockResponse('{"error":"not found"}', ['http_code' => 404]),
            'timeout'  => new MockResponse([new TransportException('Idle timeout reached.')]),
            'body'     => new MockResponse('<html>not json</html>', ['http_code' => 200]),
            'redirect' => new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://elsewhere.example/']]),
            default    => $this->recorded((string) parse_url($url, PHP_URL_PATH), $options),
        };
    }

    /**
     * @param array<mixed> $options
     */
    private function recorded(string $path, array $options): MockResponse
    {
        $query = is_array($options['query'] ?? null) ? $options['query'] : [];
        $file  = match (true) {
            preg_match('#^/v1/(\d{4})/municipio/(\d{5})\.json$#', $path, $m) === 1 => sprintf('festivos-io-%s-%s.json', $this->fail === 'year' ? '2027' : $m[1], $m[2]),
            $path === '/v1/ref/municipios.json'                                    => 'festivos-io-municipios.json',
            $path === '/Subdivisions'                                              => 'openholidays-subdivisions-es.json',
            $path === '/PublicHolidays' && isset($query['subdivisionCode'])        => 'openholidays-es-md-2026.json',
            $path === '/PublicHolidays'                                            => 'openholidays-es-2026.json',
            default                                                                => 'missing',
        };
        $full = self::DIR . '/' . $file;

        return is_file($full) ? new MockResponse((string) file_get_contents($full), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]) : new MockResponse('{}', ['http_code' => 404]);
    }
}
