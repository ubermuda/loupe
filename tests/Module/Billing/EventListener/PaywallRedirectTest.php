<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\EventListener;

use App\Module\Billing\Service\PaywallExemptions;
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

    /** @return iterable<string, array{string}> */
    public static function exemptRoutes(): iterable
    {
        foreach (PaywallExemptions::ROUTES as $route) {
            yield $route => [$route];
        }
    }

    /**
     * The exemption list is a set of route names, so a rename or a deletion
     * leaves a name behind that silently exempts nothing. Every entry must
     * still resolve against the real router.
     */
    #[DataProvider('exemptRoutes')]
    public function test_every_exempt_route_still_exists(string $route): void
    {
        self::bootKernel();
        $router = static::getContainer()->get(RouterInterface::class);

        self::assertNotNull($router->getRouteCollection()->get($route), $route);
    }

    /**
     * The dev-only escape route cannot be checked the same way: it is
     * #[When('dev')], so it is absent from this environment's route collection
     * — which is what puts it in a separate list. A paywalled session actually
     * reaching it is exercised by the billing e2e specs, which call
     * /dev/billing-state from an expired trial in the dev environment.
     */
    public function test_the_dev_only_escape_route_is_exempt_and_outside_this_environment(): void
    {
        self::bootKernel();
        $router = static::getContainer()->get(RouterInterface::class);

        foreach (PaywallExemptions::DEV_ROUTES as $route) {
            self::assertTrue(new PaywallExemptions()->exempts($route), $route);
            self::assertNull($router->getRouteCollection()->get($route), $route);
        }
    }
}
