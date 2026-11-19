<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Fixtures\HostEntity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An entity of the single-entity-manager host (audit ST-M4): the bundle must leave it managed and its pending changes
 * unflushed.
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_note')]
class HostNote
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    public string $id;

    #[ORM\Column(length: 80)]
    public string $text;

    /** @var Collection<int, HostLabel> A many-to-many (audit UZ4-1) */
    #[ORM\ManyToMany(targetEntity: HostLabel::class)]
    #[ORM\JoinTable(name: 'host_note_label')]
    public Collection $labels;

    /** @var Collection<int, HostEvent> An inverse one-to-many that cascades inserts (audit UZ4-2) */
    #[ORM\OneToMany(targetEntity: HostEvent::class, mappedBy: 'note', cascade: ['persist'])]
    public Collection $events;

    /** @var Collection<int, HostTask> An inverse one-to-many with cascade all (audit UZ5-1) */
    #[ORM\OneToMany(targetEntity: HostTask::class, mappedBy: 'note', cascade: ['all'])]
    public Collection $tasks;

    public function __construct(string $id, string $text)
    {
        $this->id     = $id;
        $this->text   = $text;
        $this->labels = new ArrayCollection();
        $this->events = new ArrayCollection();
        $this->tasks  = new ArrayCollection();
    }
}
