<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Fixtures\HostEntity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A host entity with a generated id (audit UZ2-5): persisted, it waits in the scheduled insertions until a flush. Its
 * note is cascade-persisted (audit UZ3-3).
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

    /** Cascade-persisted (audit UZ3-3) and cascade-detached (audit UZ4-5). */
    #[ORM\ManyToOne(cascade: ['persist', 'detach'], inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: true)]
    public ?HostNote $note = null;

    public function __construct(string $text)
    {
        $this->text = $text;
    }
}
