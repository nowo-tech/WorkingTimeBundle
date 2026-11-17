<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Fixtures\HostEntity;

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

    public function __construct(string $id, string $text)
    {
        $this->id   = $id;
        $this->text = $text;
    }
}
