<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Domain\Register\Service;

use Nowo\WorkingTimeBundle\Domain\Register\Exception\PermissionDeniedException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\RegisterUnavailableException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\SettingsRefusedException;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettings;
use Nowo\WorkingTimeBundle\Domain\Register\Model\RegisterSettingsInput;

/**
 * The tenant's register settings (contract section 7), `manage_settings` only. Updates are checked against the version
 * the admin saw (stale ⇒ 409 + Retry-After, nothing stored). Enabling IP evidence requires the notice text.
 *
 * @throws RegisterUnavailableException
 * @throws PermissionDeniedException
 */
interface RegisterSettingsEditorInterface
{
    /**
     * The settings and the version to post back (0 while never saved).
     *
     * @return array{RegisterSettings, int}
     */
    public function get(): array;

    /**
     * Saves the values.
     *
     * @throws SettingsRefusedException
     */
    public function update(RegisterSettingsInput $input, int $expectedVersion): RegisterSettings;
}
