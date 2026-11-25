<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Controller;

use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\UiKitBundle\View\AreaNav;
use Nowo\UiKitBundle\View\AreaNavItem;
use Nowo\UiKitBundle\View\Label;
use Nowo\WorkingTimeBundle\Application\Register\RegisterAccess;
use Nowo\WorkingTimeBundle\Domain\Shared\Exception\AccessDeniedException;
use Nowo\WorkingTimeBundle\Domain\Shared\Exception\NotAvailableException;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkingTimePermission;
use Nowo\WorkingTimeBundle\Domain\TranslationDomain;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;
use Twig\Environment;

use function bin2hex;
use function random_bytes;

/**
 * What every register page shares (contract section 11): rendering, the area navigation, flashes, the 404 / 403
 * mapping of the gate (a missing tenant, feature or membership is 404, a missing permission 403), the 409 +
 * Retry-After answer of a stale version (transaction-doctrine's conflict policy) and the CSRF forms. Stateless.
 *
 * @internal
 */
final readonly class UiSupport
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private Environment $twig,
        private UrlGeneratorInterface $router,
        private FormFactoryInterface $forms,
        private RegisterAccess $access,
        private ConflictResponsePolicy $conflicts,
        private TranslatorInterface $translator,
        private ?string $layout = null,
    ) {
    }

    /**
     * A translation label of the bundle.
     *
     * @param array<string, float|int|string|null> $params
     */
    public static function label(string $key, array $params = []): Label
    {
        return new Label($key, TranslationDomain::NAME, $params);
    }

    /**
     * A translated text of the bundle.
     *
     * @param array<string, mixed> $params
     */
    public function trans(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params, TranslationDomain::NAME);
    }

    /**
     * Renders a template.
     *
     * @param array<string, mixed> $context
     * @param array<string, string> $headers
     */
    public function render(string $template, array $context, int $status = Response::HTTP_OK, array $headers = []): Response
    {
        return new Response($this->twig->render($template, $context + ['wt_layout' => $this->layout]), $status, $headers);
    }

    /**
     * The layout of the pages (null: UiKit's admin layout).
     */
    public function layout(): ?string
    {
        return $this->layout;
    }

    /**
     * A route URL.
     *
     * @param array<string, mixed> $params
     */
    public function url(string $route, array $params = []): string
    {
        return $this->router->generate($route, $params);
    }

    /**
     * A 303 redirect to a route.
     *
     * @param array<string, mixed> $params
     */
    public function redirect(string $route, array $params = []): RedirectResponse
    {
        return new RedirectResponse($this->url($route, $params), Response::HTTP_SEE_OTHER);
    }

    /**
     * Adds a flash, translated in the request's locale (translation key of the bundle).
     */
    public function flash(Request $request, string $type, string $key): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $this->trans($key));
        }
    }

    /**
     * Adds a flash with parameters, translated in the request's locale.
     *
     * @param array<string, int|string> $params
     */
    public function flashParams(Request $request, string $type, string $key, array $params): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $this->trans($key, $params));
        }
    }

    /**
     * Runs a page and maps the gates' refusals (register, holidays, leave): 404, then 403.
     *
     * @param callable(): Response $page
     */
    public function guard(callable $page): Response
    {
        try {
            return $page();
        } catch (NotAvailableException $e) {
            throw new NotFoundHttpException('Not found.', $e);
        } catch (AccessDeniedException $e) {
            throw new AccessDeniedHttpException('Forbidden.', $e);
        }
    }

    /**
     * The status and headers of a stale version (409, REQ-RUNTIME-003 rule 6): `X-Conflict-Code` from
     * `ConflictCode::forRequest()` (CONFLICT_002 when the request committed something before), `Retry-After` only for
     * CONFLICT_001.
     *
     * @return array{int, array<string, string>}
     */
    public function conflict(Request $request, Throwable $failure): array
    {
        return [$this->conflicts->status, $this->conflicts->headersFor(ConflictCode::forRequest($request, $failure))];
    }

    /**
     * A named form.
     *
     * @param class-string<FormTypeInterface<mixed>> $type
     * @param array<string, mixed>|null $data
     * @param array<string, mixed> $options
     *
     * @return FormInterface<mixed>
     */
    public function form(string $type, ?array $data = null, array $options = [], ?string $name = null): FormInterface
    {
        return $name === null
            ? $this->forms->create($type, $data, $options)
            : $this->forms->createNamed($name, $type, $data, $options);
    }

    /**
     * A fresh idempotency key for a clock button.
     */
    public static function requestKey(): string
    {
        return bin2hex(random_bytes(12));
    }

    /**
     * The register's area navigation, with the entries the actor may open.
     */
    public function tabs(string $current): AreaNav
    {
        $items   = [new AreaNavItem(self::label('nav.today'), $this->url('nowo_working_time_today'), $current === 'today')];
        $items[] = new AreaNavItem(self::label('nav.month'), $this->url('nowo_working_time_month_current'), $current === 'month');
        $items[] = new AreaNavItem(self::label('nav.exports'), $this->url('nowo_working_time_exports'), $current === 'exports');
        if ($this->access->can(WorkingTimePermission::ManageTeam)) {
            $items[] = new AreaNavItem(self::label('nav.team'), $this->url('nowo_working_time_team'), $current === 'team');
            $items[] = new AreaNavItem(self::label('nav.schedules'), $this->url('nowo_working_time_schedules'), $current === 'schedules');
        }
        $items[] = new AreaNavItem(self::label('nav.preferences'), $this->url('nowo_working_time_preferences'), $current === 'preferences');
        if ($this->access->can(WorkingTimePermission::ManageSettings)) {
            $items[] = new AreaNavItem(self::label('nav.settings'), $this->url('nowo_working_time_settings'), $current === 'settings');
        }

        return new AreaNav($items, self::label('nav.label'));
    }
}
