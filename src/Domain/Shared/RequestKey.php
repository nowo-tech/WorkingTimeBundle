<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Shared;

use Nowo\WorkingTimeBundle\Domain\Register\Exception\InvalidRequestKeyException;

use function preg_match;

/**
 * The idempotency key of a clock action (contract section 5; Beacon REQ-SEC-004 F6): 1–80 visible ASCII characters,
 * matched byte for byte, never trimmed or truncated. The web prefixes it `web:`, the client API `api:`.
 */
final readonly class RequestKey
{
    /** The accepted form. */
    public const PATTERN = '/^[\x21-\x7E]{1,80}$/D';

    /**
     * @throws InvalidRequestKeyException when the key is empty, too long or has anything but visible ASCII
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidRequestKeyException();
        }
    }

    /**
     * A key from the web UI (`web:` + the form's key).
     *
     * @throws InvalidRequestKeyException
     */
    public static function web(string $key): self
    {
        return new self('web:' . $key);
    }

    /**
     * A key from a client app through the TimeTrack API (`api:` + the client's key).
     *
     * @throws InvalidRequestKeyException
     */
    public static function api(string $key): self
    {
        return new self('api:' . $key);
    }
}
