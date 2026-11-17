<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Shared;

use InvalidArgumentException;

use function preg_match;
use function sprintf;
use function strlen;

/**
 * Validation shared by the opaque host references (contract section 5, rule LQ): 1–128 bytes of valid UTF-8, no
 * control or format characters, never trimmed or case folded; two references are equal only when their bytes are.
 */
trait OpaqueRefTrait
{
    /** Longest reference in bytes (the `string(128)` binary column). */
    public const MAX_BYTES = 128;

    /**
     * @param string $value the host id, exactly as the host gives it
     */
    public function __construct(public readonly string $value)
    {
        if ($value === '' || strlen($value) > self::MAX_BYTES || preg_match('//u', $value) !== 1 || preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 1) {
            throw new InvalidArgumentException(sprintf('%s must be 1-128 bytes of UTF-8 without control or format characters.', static::class));
        }
    }

    /**
     * Byte-exact comparison (rule LQ: `usera` is not `UserA`).
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * The reference, for logs and templates.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
