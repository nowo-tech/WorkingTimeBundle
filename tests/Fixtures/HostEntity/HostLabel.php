<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Fixtures\HostEntity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A label host notes link to through a many-to-many (audit UZ4-1).
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_label')]
class HostLabel
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    public string $id;

    public function __construct(string $id)
    {
        $this->id = $id;
    }
}
