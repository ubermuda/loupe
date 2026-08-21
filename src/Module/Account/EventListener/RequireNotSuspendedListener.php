<?php

declare(strict_types=1);

namespace App\Module\Account\EventListener;

use App\Module\Account\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\UX\Turbo\TurboBundle;

// Priority 6: after the firewall (8) so the token is resolved, and above the
// unverified (5), paywall (4) and terms (3) gates — a suspended account should
// not be asked to verify, pay or accept anything first.
#[AsEventListener(priority: 6)]
final readonly class RequireNotSuspendedListener
{
    public const string SUSPENDED_ROUTE = 'app_account_suspended';

    /** Profiler, the install wizard, and the JSON API: none can render an interstitial. */
    private const array EXEMPT_PATH_PREFIXES = ['/_', '/install', '/api/'];

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof User || !$user->isSuspended()) {
            return;
        }

        if ($this->isExempt($event->getRequest())) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate(self::SUSPENDED_ROUTE)));
    }

    private function isExempt(Request $request): bool
    {
        // By route name, not path: the suspended page redirecting to itself is
        // the one failure mode that takes the whole app down.
        if (self::SUSPENDED_ROUTE === $request->attributes->get('_route')) {
            return true;
        }

        $path = $request->getPathInfo();

        // Leaving must always be possible.
        if ('/logout' === $path) {
            return true;
        }

        // Bearer-token clients cannot render an interstitial.
        if ('/mcp' === $path || str_starts_with($path, '/mcp/')) {
            return true;
        }

        if (array_any(self::EXEMPT_PATH_PREFIXES, static fn (string $prefix): bool => str_starts_with($path, $prefix))) {
            return true;
        }

        return !$this->isHtmlNavigation($request);
    }

    /**
     * Only safe HTML navigations are gated, matching the terms gate: an HTML
     * redirect sent to a Turbo-stream or JSON fetch lands in a frame or a parse
     * error, and unsafe methods stay with the voters that authorize them.
     */
    private function isHtmlNavigation(Request $request): bool
    {
        if (!$request->isMethodSafe()) {
            return false;
        }

        if (str_contains((string) $request->headers->get('Accept', ''), TurboBundle::STREAM_MEDIA_TYPE)) {
            return false;
        }

        return 'html' === $request->getPreferredFormat();
    }
}
