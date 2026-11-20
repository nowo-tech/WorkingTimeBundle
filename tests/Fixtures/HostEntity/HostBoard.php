<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Fixtures\HostEntity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A host board owning its tags through a many-to-many with orphan removal (audit UZ9-1): clear() queues every tag for
 * orphan removal and schedules the collection deletion, with no delete diff left.
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_board')]
class HostBoard
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    public string $id;

    /** @var Collection<int, HostTag> */
    #[ORM\ManyToMany(targetEntity: HostTag::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\JoinTable(name: 'host_board_tag')]
    public Collection $tags;

    public function __construct(string $id)
    {
        $this->id   = $id;
        $this->tags = new ArrayCollection();
    }
}
