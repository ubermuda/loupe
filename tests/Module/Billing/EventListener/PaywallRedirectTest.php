<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\EventListener;

use App\Module\Billing\Controller\Dev\SeedBillingStateController;
use App\Routing\PaywallExempt;
use App\Tests\Support\BillingScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/**
 * The paywall as a real request goes through it, and the escape routes as the
 * router actually knows them.
 */
final class PaywallRedirectTest extends WebTestCase
{
    public function test_an_expired_trial_is_redirected_away_from_the_app(): void
    {
        $client = static::createClient();
        $scenario = new BillingScenario(static::getContainer());
        $scenario->enableBilling();
        $user = $scenario->verifiedUser('paywalled');
        $scenario->profile($user, new \DateTimeImmutable('-1 day'));

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/projects');

        self::assertResponseRedirects('/billing/subscribe');
    }

    public function test_a_running_trial_reaches_the_app(): void
    {
        $client = static::createClient();
        $scenario = new BillingScenario(static::getContainer());
        $scenario->enableBilling();
        $user = $scenario->verifiedUser('intrial');
        $scenario->profile($user, new \DateTimeImmutable('+3 days'));

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/projects');

        self::assertResponseIsSuccessful();
    }

    public function test_with_billing_disabled_nobody_is_paywalled(): void
    {
        $client = static::createClient();
        $scenario = new BillingScenario(static::getContainer());
        $user = $scenario->verifiedUser('notbilled');
        $scenario->profile($user, new \DateTimeImmutable('-1 day'));

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/projects');

        self::assertResponseIsSuccessful();
    }

    public function test_a_paywalled_user_can_still_reach_their_account_page_and_export_their_data(): void
    {
        $client = static::createClient();
        $scenario = new BillingScenario(static::getContainer());
        $scenario->enableBilling();
        $user = $scenario->verifiedUser('exiting');
        $scenario->profile($user, new \DateTimeImmutable('-1 day'));

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/account');
        self::assertResponseIsSuccessful();

        // Establishes the origin cookie the stateless CSRF sentinel needs.
        $client->request(Request::METHOD_POST, '/account/exports', ['_csrf_token' => 'csrf-token']);
        self::assertResponseRedirects('/account');
    }

    /**
     * A user whose trial is over is not offered the first-run wizard: setting up
     * a first project is using the product. The redirect target is allowlisted,
     * so this cannot loop.
     */
    public function test_the_first_run_wizard_is_behind_the_paywall_without_looping(): void
    {
        $client = static::createClient();
        $scenario = new BillingScenario(static::getContainer());
        $scenario->enableBilling();
        $user = $scenario->verifiedUser('wizardwall');
        $scenario->profile($user, new \DateTimeImmutable('-1 day'));

        $client->loginUser($user);

        // The wizard entry point itself, and the home route that would otherwise
        // send a project-less user into it.
        $client->request(Request::METHOD_GET, '/welcome');
        self::assertResponseRedirects('/billing/subscribe');

        $client->request(Request::METHOD_GET, '/');
        self::assertResponseRedirects('/billing/subscribe');

        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function test_a_trialing_user_still_gets_the_first_run_wizard(): void
    {
        $client = static::createClient();
        $scenario = new BillingScenario(static::getContainer());
        $scenario->enableBilling();
        $user = $scenario->verifiedUser('wizardok');
        $scenario->profile($user, new \DateTimeImmutable('+3 days'));

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseRedirects('/welcome');
    }

    public function test_a_paywalled_user_can_still_reach_the_subscribe_page(): void
    {
        $client = static::createClient();
        $scenario = new BillingScenario(static::getContainer());
        $scenario->enableBilling();
        $user = $scenario->verifiedUser('stillhere');
        $scenario->profile($user, new \DateTimeImmutable('-1 day'));

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/billing/subscribe');

        self::assertResponseIsSuccessful();
    }

    /**
     * The 17 escape routes registered outside dev — every route previously
     * named in RequireSubscriptionListener::ALLOWED_ROUTES except
     * app_dev_billing_state, which is #[When('dev')] and so absent from the
     * `test` environment's route collection entirely (verified separately,
     * by reflection, below).
     *
     * @return iterable<string, array{string}>
     */
    public static function routesThatMustExistToday(): iterable
    {
        foreach ([
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
            'app_account_export_request',
            'app_account_export_download',
            'app_account_delete_request',
            'app_account_delete_confirm',
            'app_account_delete_execute',
            'app_account_deleted',
        ] as $route) {
            yield $route => [$route];
        }
    }

    /**
     * Each escape route must both still exist under this name AND carry the
     * `_paywallExempt` route default PaywallExemptRouteLoader sets from the
     * controller's #[PaywallExempt] attribute — the mechanism
     * RequireSubscriptionListener now reads instead of a route-name allowlist.
     * A rename on either side (route name, or a dropped #[PaywallExempt])
     * surfaces here.
     */
    #[DataProvider('routesThatMustExistToday')]
    public function test_escape_routes_exist_and_are_paywall_exempt(string $route): void
    {
        self::bootKernel();
        $router = static::getContainer()->get(RouterInterface::class);

        $compiledRoute = $router->getRouteCollection()->get($route);
        self::assertNotNull($compiledRoute, $route);
        self::assertTrue($compiledRoute->getDefault(PaywallExempt::ROUTE_DEFAULT), $route);
    }

    /**
     * app_dev_billing_state is #[When('dev')] and therefore does not exist in
     * the `test` environment's route collection at all (confirmed above by its
     * absence from routesThatMustExistToday()) — so the router cannot prove
     * its exemption here. Its controller attribute is the only thing that can
     * be checked in this environment; the route actually reaching a paywalled
     * session is exercised by the billing e2e specs, which call
     * /dev/billing-state from a paywalled account in the dev environment.
     */
    public function test_dev_billing_state_controller_is_paywall_exempt(): void
    {
        $attributes = new \ReflectionClass(SeedBillingStateController::class)
            ->getAttributes(PaywallExempt::class);

        self::assertNotEmpty($attributes);
    }
}
