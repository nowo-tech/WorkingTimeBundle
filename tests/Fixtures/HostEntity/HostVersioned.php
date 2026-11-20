<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Fixtures\HostEntity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A host entity with a version column (audit UZ4-3).
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_versioned')]
class HostVersioned
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    public string $id;

    #[ORM\Column(length: 80)]
    public string $text;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    public int $version = 1;

    public function __construct(string $id, string $text)
    {
        $this->id   = $id;
        $this->text = $text;
    }
}
