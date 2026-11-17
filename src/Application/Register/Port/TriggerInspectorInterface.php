<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Application\Register\Port;

/**
 * Whether the append-only triggers are installed (contract section 6.4): the settings page warns while they are
 * missing, and `integrity.triggers: require` refuses to turn the register on.
 */
interface TriggerInspectorInterface
{
    /**
     * The names of the expected triggers that are missing (empty: all installed).
     *
     * @return list<string>
     */
    public function missing(): array;
}
