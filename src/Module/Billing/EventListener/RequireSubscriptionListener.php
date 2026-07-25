<?php

declare(strict_types=1);

namespace App\Module\Billing\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Billing\Service\PaywallGate;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

// Priority 4: after the security firewall (8) and RedirectUnverifiedUserListener (5),
// so the token is populated and unverified users were already diverted.
#[AsEventListener(priority: 4)]
final readonly class RequireSubscriptionListener
{
    /**
     * Routes an out-of-trial user may still reach: the paywall itself, leaving,
     * verification plumbing, and the account pages through which they export
     * their data or close their account — nobody is ever locked away from their
     * own data by an unpaid invoice. Names for pages that do not exist yet are
     * inert (the in_array simply never matches) and become live the moment the
     * route is registered.
     */
    public const array ALLOWED_ROUTES = [
        'app_billing_subscribe',
        'app_billing_checkout',
        'app_billing_checkout_success',
        'app_billing_portal',
        'app_login',
        'app_logout',
        'app_register_check_email',
        'app_register_resend',
        'app_verify_email',
        // Data export.
        'app_account_settings',
        'app_account_export_request',
        'app_account_export_download',
        // Account deletion.
        'app_account_delete_request',
        'app_account_delete_confirm',
        'app_account_delete_execute',
        'app_account_goodbye',
        // Dev-only seeding seam (registered by #[When('dev')], absent in prod):
        // an e2e run that has just paywalled itself must still be able to call
        // it to switch billing back off.
        'app_dev_billing_state',
    ];

    private const string ADMIN_ROUTE_PREFIX = 'app_admin_';

    private const string FEATURE_FLAGS_ROUTE_PREFIX = 'ubermuda_feature_flags_';

    /**
     * Machine clients authenticate against the stateless token firewalls with an
     * API token. Handing them an HTML 302 to the subscribe page would corrupt
     * their response rather than explain anything, so they are left alone here.
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
        foreach (self::MACHINE_PATH_PREFIXES as $prefix) {
            if (str_starts_with($request->getPathInfo(), $prefix)) {
                return;
            }
        }

        $route = $request->attributes->get('_route');
        if (!is_string($route)
            || in_array($route, self::ALLOWED_ROUTES, true)
            || str_starts_with($route, self::ADMIN_ROUTE_PREFIX)
            || str_starts_with($route, self::FEATURE_FLAGS_ROUTE_PREFIX)) {
            return;
        }

        if ($this->gate->allows($user)) {
            return;
        }

        $event->setResponse(
            new RedirectResponse($this->urlGenerator->generate('app_billing_subscribe'))
        );
    }
}
