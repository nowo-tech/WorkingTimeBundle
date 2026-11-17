<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Shared;

use InvalidArgumentException;

use function filter_var;
use function inet_ntop;
use function inet_pton;
use function preg_match;
use function str_repeat;
use function strlen;
use function substr;

use const FILTER_VALIDATE_IP;

/**
 * IP evidence of a clock action (spec 198 FR-027, Q-O11): only the IPv4 /24 or IPv6 /64 prefix is ever stored or shown
 * (`203.0.113.0/24`, `2001:db8:1:2::/64`); the address itself never leaves {@see self::fromIp()}.
 */
final readonly class IpPrefix
{
    private function __construct(public string $value)
    {
    }

    /**
     * The prefix of an address; null when the value is not an IP address.
     */
    public static function fromIp(string $ip): ?self
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = (string) inet_pton($ip);
        if (strlen($packed) === 4) {
            return new self(inet_ntop(substr($packed, 0, 3) . "\0") . '/24');
        }

        return new self(inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64');
    }

    /**
     * A stored prefix read back (`a.b.c.0/24` or `…::/64`).
     */
    public static function fromStored(string $value): self
    {
        if (preg_match('#^(\d{1,3}\.\d{1,3}\.\d{1,3}\.0/24|[0-9a-f:]+/64)$#D', $value) !== 1) {
            throw new InvalidArgumentException('Not a stored IP prefix.');
        }

        return new self($value);
    }

    /** The prefix. */
    public function __toString(): string
    {
        return $this->value;
    }
}
