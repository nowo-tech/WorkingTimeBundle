<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Leave;

use Nowo\WorkingTimeBundle\Application\Leave\Port\LeaveSeedNamesInterface;
use Nowo\WorkingTimeBundle\NowoWorkingTimeBundle;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The seeded leave type names from the bundle's translations (`leave.seed.<code>`), in every shipped locale.
 *
 * @internal
 */
final readonly class TranslatedLeaveSeedNames implements LeaveSeedNamesInterface
{
    /** The locales the bundle ships (REQ-I18N-002 house set plus the Spanish co-official languages). */
    public const LOCALES = ['en', 'es', 'it', 'fr', 'pt', 'de', 'nl', 'ca', 'eu', 'gl'];

    /**
     * Wires the collaborators.
     */
    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * See the implemented interface.
     */
    public function names(string $seedCode): array
    {
        $names = [];
        foreach (self::LOCALES as $locale) {
            $names[$locale] = $this->translator->trans('leave.seed.' . $seedCode, [], NowoWorkingTimeBundle::TRANSLATION_DOMAIN, $locale);
        }

        return $names;
    }
}
