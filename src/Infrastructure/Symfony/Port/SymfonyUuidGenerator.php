<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port;

use Nowo\WorkingTimeBundle\Domain\Port\IdGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Default id generator: UUIDv7 (time ordered), lowercase canonical form.
 */
final readonly class SymfonyUuidGenerator implements IdGeneratorInterface
{
    /**
     * A new UUIDv7.
     */
    public function generate(): string
    {
        return Uuid::v7()->toRfc4122();
    }
}
