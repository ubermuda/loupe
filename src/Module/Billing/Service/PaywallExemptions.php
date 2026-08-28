<?php

declare(strict_types=1);

namespace App\Module\Billing\Service;

/**
 * The routes a user the paywall would otherwise block may still reach: the
 * paywall itself, auth, verification, terms acceptance, data export and
 * account deletion.
 *
 * Deny-by-default: a route absent from these lists is paywalled, so a
 * forgotten entry blocks a user loudly rather than making a feature free.
 * Every name is checked against the real router by PaywallRedirectTest.
 */
final readonly class PaywallExemptions
{
    /** @var list<string> */
    public const array ROUTES = [
        'app_billing_subscribe',
        'app_billing_checkout',
        'app_billing_checkout_success',
        'app_billing_portal',
        'app_login',
        'app_logout',
        'app_register_check_email',
        'app_register_resend',
        'app_verify_email',
        'app_waitlist_join',
        'app_account_settings',
        'app_account_profile',
        // The paywall runs above the terms gate, so dropping either of these
        // traps a user with a lapsed subscription and stale terms in a loop
        // between the subscribe page and the acceptance interstitial.
        'app_account_accept_terms',
        'app_account_accept_terms_submit',
        'app_account_suspended',
        'app_account_export_request',
        'app_account_export_download',
        'app_account_delete_request',
        'app_account_delete_confirm',
        'app_account_delete_execute',
        'app_account_deleted',
    ];

    /**
     * Registered #[When('dev')], so these names are absent from the route
     * collection everywhere else and cannot be asserted against the router.
     *
     * @var list<string>
     */
    public const array DEV_ROUTES = [
        'app_dev_billing_state',
    ];

    /**
     * Route-name prefixes owned by the admin area and by the feature-flags
     * bundle, whose controllers are not ours to list route by route.
     *
     * @var list<string>
     */
    public const array ROUTE_PREFIXES = [
        'app_admin_',
        'ubermuda_feature_flags_',
    ];

    public function exempts(string $route): bool
    {
        return \in_array($route, self::ROUTES, true)
            || \in_array($route, self::DEV_ROUTES, true)
            || array_any(self::ROUTE_PREFIXES, static fn (string $prefix): bool => str_starts_with($route, $prefix));
    }
}
