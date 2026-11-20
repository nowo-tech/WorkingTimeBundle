<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Twig;

use Nowo\WorkingTimeBundle\Domain\Register\Exception\PermissionDeniedException;
use Nowo\WorkingTimeBundle\Domain\Register\Exception\RegisterUnavailableException;
use Nowo\WorkingTimeBundle\Domain\Register\Service\RegisterClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

use function intdiv;
use function max;
use function sprintf;

/**
 * Twig helpers (contract section 11.1): `working_time_header_clock()` — the header quick action (next action and the
 * day's worked time, or nothing when the register is not there for the person) — and `|working_time_format_minutes`
 * (`7:05`). Stateless: the state is asked per call.
 */
final class WorkingTimeTwigExtension extends AbstractExtension
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private readonly RegisterClockInterface $clock,
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    /**
     * The functions.
     */
    public function getFunctions(): array
    {
        return [new TwigFunction('working_time_header_clock', $this->headerClock(...), ['needs_environment' => true, 'is_safe' => ['html']])];
    }

    /**
     * The filters.
     */
    public function getFilters(): array
    {
        return [new TwigFilter('working_time_format_minutes', self::formatMinutes(...))];
    }

    /**
     * The header button (empty when the person has no register here).
     */
    public function headerClock(Environment $twig): string
    {
        try {
            $state = $this->clock->state();
        } catch (RegisterUnavailableException|PermissionDeniedException) {
            return '';
        }

        return $twig->render('@NowoWorkingTimeBundle/register/_header_clock.html.twig', ['state' => $state, 'href' => $this->router->generate('nowo_working_time_today')]);
    }

    /**
     * `7:05` of a number of minutes.
     */
    public static function formatMinutes(int|float|null $minutes): string
    {
        $minutes = max(0, (int) $minutes);

        return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
