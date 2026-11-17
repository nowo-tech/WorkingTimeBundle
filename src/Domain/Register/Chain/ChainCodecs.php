<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Chain;

use Nowo\WorkingTimeBundle\Domain\Register\Enum\ChainFormat;
use Nowo\WorkingTimeBundle\Domain\Register\Model\WorkdayRevision;

use function hash;
use function hash_equals;

/**
 * Dispatches a link to its format's codec and computes `hash = sha256(prev_hash ‖ canonical)`.
 */
final class ChainCodecs
{
    /** The first link's `prev_hash` (Beacon's constant). */
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * The codec of a format.
     */
    public static function for(ChainFormat $format): ChainCodecInterface
    {
        return match ($format) {
            ChainFormat::Beacon => new BeaconChainCodec(),
            ChainFormat::Bundle => new BundleChainCodec(),
        };
    }

    /**
     * The hash of a link given its previous hash.
     */
    public static function hashOf(string $prevHash, string $canonical): string
    {
        return hash('sha256', $prevHash . $canonical);
    }

    /**
     * The hash the link must carry.
     */
    public static function expectedHash(WorkdayRevision $revision): string
    {
        return self::hashOf($revision->getPrevHash(), self::for($revision->getChainFormat())->canonical($revision));
    }

    /**
     * Whether the stored hash matches the row and the expected previous hash.
     */
    public static function isIntact(WorkdayRevision $revision, string $expectedPrevHash): bool
    {
        return $revision->getPrevHash() === $expectedPrevHash && hash_equals(self::expectedHash($revision), $revision->getHash());
    }
}
