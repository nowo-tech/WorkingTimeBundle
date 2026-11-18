<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Holiday;

use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuardInterface;
use Nowo\WorkingTimeBundle\Domain\Holiday\Exception\HolidayRefusedException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * The only way the holiday providers call out (contract section 9.2): a GET of a fixed https base URI through the
 * outbound URL guard (OutboundUrlGuardBundle: no private or reserved targets, DNS pinned), a timeout, no redirects,
 * `Accept: application/json`, a body cap of 2 MiB, and no personal data (only the location and the year are in the URL or the query). Any
 * failure — refused URL, network, HTTP status, unreadable body — is the provider's one translated error. The HTTP
 * client is the host's (`holidays.http_client`) or one created for the call (worker mode: nothing kept).
 *
 * @internal
 */
final readonly class GuardedJsonClient
{
    /** Largest response body read (audit TD-L3): a provider's yearly list is a few kilobytes. */
    public const MAX_BYTES = 2_097_152;

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private OutboundUrlGuardInterface $guard,
        private ?HttpClientInterface $client = null,
        private int $maxBytes = self::MAX_BYTES,
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
                // A body over the cap (announced or received) aborts the transfer before it fills the memory.
                'on_progress' => function (int $received, int $announced): void { // @igor-ignore - the client calls this closure during the request and keeps nothing
                    if ($received > $this->maxBytes || $announced > $this->maxBytes) {
                        throw new TransportException('The response is larger than the cap.');
                    }
                },
            ];

            return ($this->client ?? HttpClient::create())->request('GET', $url, $options)->toArray();
        } catch (Throwable) {
            throw new HolidayRefusedException($errorKey);
        }
    }
}
