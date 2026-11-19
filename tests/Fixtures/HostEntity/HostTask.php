<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Fixtures\HostEntity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A child in a cascade-all inverse collection of a host note (audit UZ5-1): EntityManager::refresh() of the note would
 * cascade to it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_task')]
class HostTask
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 80)]
    public string $text;

    #[ORM\ManyToOne(inversedBy: 'tasks')]
    #[ORM\JoinColumn(nullable: true)]
    public ?HostNote $note = null;

    public function __construct(string $text)
    {
        $this->text = $text;
    }
}
