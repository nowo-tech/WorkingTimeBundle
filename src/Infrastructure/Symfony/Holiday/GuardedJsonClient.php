<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Holiday;

use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuardInterface;
use Nowo\WorkingTimeBundle\Domain\Holiday\Exception\HolidayRefusedException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * The only way the holiday providers call out (contract section 9.2): a GET of a fixed https base URI through the
 * outbound URL guard (OutboundUrlGuardBundle: no private or reserved targets, DNS pinned), a timeout, no redirects,
 * `Accept: application/json`, and no personal data (only the location and the year are in the URL or the query). Any
 * failure — refused URL, network, HTTP status, unreadable body — is the provider's one translated error. The HTTP
 * client is the host's (`holidays.http_client`) or one created for the call (worker mode: nothing kept).
 *
 * @internal
 */
final readonly class GuardedJsonClient
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private OutboundUrlGuardInterface $guard,
        private ?HttpClientInterface $client = null,
    ) {
    }

    /**
     * The decoded JSON body of a GET.
     *
     * @param array<string, string> $query
     *
     * @throws HolidayRefusedException `$errorKey`
     *
     * @return array<mixed>
     */
    public function get(string $url, array $query, int $timeout, string $errorKey): array
    {
        try {
            $options = $this->guard->httpClientOptions($url) + [
                'query'         => $query,
                'timeout'       => $timeout,
                'max_duration'  => $timeout * 3,
                'max_redirects' => 0,
                'headers'       => ['Accept' => 'application/json'],
            ];

            return ($this->client ?? HttpClient::create())->request('GET', $url, $options)->toArray();
        } catch (Throwable) {
            throw new HolidayRefusedException($errorKey);
        }
    }
}
