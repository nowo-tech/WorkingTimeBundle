<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Fixtures\HostEntity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A host entity with a generated id (audit UZ2-5): persisted, it waits in the scheduled insertions until a flush.
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_event')]
class HostEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 80)]
    public string $text;

    public function __construct(string $text)
    {
        $this->text = $text;
    }
}
