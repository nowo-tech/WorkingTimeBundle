<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Fixtures\HostEntity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A tag a host board owns through a many-to-many with orphan removal (audit UZ9-1).
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_tag')]
class HostTag
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    public string $id;

    public function __construct(string $id)
    {
        $this->id = $id;
    }
}
