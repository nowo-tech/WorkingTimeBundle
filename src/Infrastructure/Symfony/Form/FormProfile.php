<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Form;

/**
 * FormKit profile of the bundle's forms (REQ-TWIG-005 rule 11). The form types read it from here; the bundle class
 * re-exports it as `NowoWorkingTimeBundle::FORM_PROFILE` with the same value (REQ-ARCH-002: no layer depends on the
 * composition root).
 */
final class FormProfile
{
    /** The profile name. */
    public const NAME = 'working_time';
}
