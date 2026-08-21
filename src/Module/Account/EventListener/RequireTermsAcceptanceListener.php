<?php

declare(strict_types=1);

namespace App\Module\Account\EventListener;

use App\Module\Account\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\UX\Turbo\TurboBundle;

// Priority 3: after the firewall (8) so the token is resolved, and below the
// unverified (5) and paywall (4) gates. Sitting above either of them loops —
// this gate would send the user to the acceptance page and that one would send
// them straight back.
#[AsEventListener(priority: 3)]
final readonly class RequireTermsAcceptanceListener
{
    public const string INTENDED_PATH_SESSION_KEY = 'terms.intended_path';

    public const string ACCEPTANCE_ROUTE = 'app_account_accept_terms';

    /** Both halves of the interstitial: gating the POST would make accepting impossible. */
    public const array ACCEPTANCE_ROUTES = [self::ACCEPTANCE_ROUTE, 'app_account_accept_terms_submit'];

    /** Must stay readable, or there is no way to know what is being accepted. */
    private const array LEGAL_PATHS = ['/terms', '/privacy', '/ai-policy'];

    /** Profiler, the install wizard, and the JSON API: none can render an interstitial. */
    private const array EXEMPT_PATH_PREFIXES = ['/_', '/install', '/api/'];

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private UrlGeneratorInterface $urlGenerator,

        #[Autowire(param: 'app.terms.version')]
        private string $termsVersion,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof User || $user->termsVersion === $this->termsVersion) {
            return;
        }

        $request = $event->getRequest();
        if ($this->isExempt($request)) {
            return;
        }

        // Only a GET is worth coming back to; replaying a POST URI as a
        // redirect target after acceptance would 405.
        if ($request->isMethod(Request::METHOD_GET) && $request->hasSession()) {
            $request->getSession()->set(self::INTENDED_PATH_SESSION_KEY, $request->getRequestUri());
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate(self::ACCEPTANCE_ROUTE)));
    }

    private function isExempt(Request $request): bool
    {
        // By route name, not path: the acceptance page redirecting to itself is
        // the one failure mode that takes the whole app down.
        if (in_array($request->attributes->get('_route'), self::ACCEPTANCE_ROUTES, true)) {
            return true;
        }

        $path = $request->getPathInfo();

        // Bearer-token clients have no way to accept anything, so gating them
        // would break every agent rather than prompt a human.
        if ('/mcp' === $path || str_starts_with($path, '/mcp/')) {
            return true;
        }

        // Leaving must always be possible.
        if ('/logout' === $path) {
            return true;
        }

        if (in_array($path, self::LEGAL_PATHS, true)) {
            return true;
        }

        if (array_any(self::EXEMPT_PATH_PREFIXES, static fn (string $prefix): bool => str_starts_with($path, $prefix))) {
            return true;
        }

        return !$this->isHtmlNavigation($request);
    }

    /**
     * Only safe methods are gated. Redirecting a submission would discard it
     * with nothing to resume — the intended path below is recorded for GET
     * alone, because replaying a POST URI would 405. A Turbo-stream or JSON
     * fetch is skipped too, since an HTML redirect would land in a frame or a
     * parse error.
     *
     * So writes stay ungated: this is a navigation interstitial, not an
     * authorization boundary, and voters still authorize every write.
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
