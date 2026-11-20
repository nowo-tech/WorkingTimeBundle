<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Fixtures\HostEntity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A host entity with a readonly generated id (audit UZ4-4): the id cannot be reset after a rolled-back insert.
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_readonly')]
class HostReadonly
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.uninitializedReadonly (Doctrine assigns the generated id once, on insert)
    public readonly int $id;

    #[ORM\Column(length: 80)]
    public string $text;

    public function __construct(string $text)
    {
        $this->text = $text;
    }
}
