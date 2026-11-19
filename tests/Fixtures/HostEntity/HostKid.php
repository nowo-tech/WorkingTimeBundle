<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Fixtures\HostEntity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A kid in an orphan-removal one-to-many of a host note (audit UZ6-1).
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_kid')]
class HostKid
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    public string $id;

    #[ORM\ManyToOne(inversedBy: 'kids')]
    #[ORM\JoinColumn(nullable: true)]
    public ?HostNote $note = null;

    public function __construct(string $id)
    {
        $this->id = $id;
    }
}
