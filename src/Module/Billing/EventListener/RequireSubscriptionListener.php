<?php

declare(strict_types=1);

namespace App\Module\Billing\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Billing\Service\PaywallGate;
use App\Routing\PaywallExempt;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

// Priority 4: after the security firewall (8) and RedirectUnverifiedUserListener (5),
// so the token is populated and unverified users were already diverted.
#[AsEventListener(priority: 4)]
final readonly class RequireSubscriptionListener
{
    private const string ADMIN_ROUTE_PREFIX = 'app_admin_';

    private const string FEATURE_FLAGS_ROUTE_PREFIX = 'ubermuda_feature_flags_';

    /**
     * Machine clients authenticate against the stateless token firewalls with an
     * API token. They are gated exactly like the UI, but an HTML 302 to the
     * subscribe page would corrupt their response rather than explain anything,
     * so they get a 402 with a machine-readable body instead.
     */
    private const array MACHINE_PATH_PREFIXES = ['/api/', '/mcp'];

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private PaywallGate $gate,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof User || !$user->isVerified()) {
            return;
        }

        $request = $event->getRequest();
        $isMachineRequest = array_any(self::MACHINE_PATH_PREFIXES, fn ($prefix) => str_starts_with($request->getPathInfo(), (string) $prefix));

        if (!$isMachineRequest) {
            $route = $request->attributes->get('_route');
            // `_paywallExempt` is a route default set by PaywallExemptRouteLoader
            // from the #[PaywallExempt] attribute on the matched controller — the
            // paywall itself, leaving, verification plumbing, and the account
            // pages through which a user exports their data or closes their
            // account. Reading it here (instead of a route-name allowlist) means
            // the exemption travels with the route: it cannot silently stop
            // protecting a route that gets renamed.
            if (!is_string($route)
                || true === $request->attributes->get(PaywallExempt::ROUTE_DEFAULT)
                || str_starts_with($route, self::ADMIN_ROUTE_PREFIX)
                || str_starts_with($route, self::FEATURE_FLAGS_ROUTE_PREFIX)) {
                return;
            }
        }

        if ($this->gate->allows($user)) {
            return;
        }

        $event->setResponse($isMachineRequest
            ? new JsonResponse(
                ['error' => 'subscription_required', 'subscribeUrl' => $this->urlGenerator->generate('app_billing_subscribe', referenceType: UrlGeneratorInterface::ABSOLUTE_URL)],
                Response::HTTP_PAYMENT_REQUIRED,
            )
            : new RedirectResponse($this->urlGenerator->generate('app_billing_subscribe')));
    }
}
