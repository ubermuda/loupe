<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Controller;

use App\Module\Billing\Service\StripeGateway;
use App\Module\Billing\Service\StripeGatewayInterface;
use App\Tests\Support\BillingScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * A deployment that has turned billing on before its Stripe keys are in place
 * must still be able to render the pages that explain billing — otherwise an
 * out-of-trial user is redirected to a 500 with no way back to their account.
 *
 * StripeClient rejects an empty API key in its constructor, so a gateway built
 * with no key reproduces exactly that deployment.
 */
final class UnconfiguredStripeTest extends WebTestCase
{
    private function scenarioWithoutStripeKeys(): BillingScenario
    {
        static::getContainer()->set(StripeGatewayInterface::class, new StripeGateway(''));

        $scenario = new BillingScenario(static::getContainer());
        $scenario->enableBilling();
        // With a price configured, the page really does try to reach Stripe.
        $scenario->priceFlag('price_unconfigured');

        return $scenario;
    }

    public function test_the_subscribe_page_renders_without_a_price_instead_of_failing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $scenario = $this->scenarioWithoutStripeKeys();

        $client->loginUser($scenario->verifiedUser('nokeys'));
        $crawler = $client->request(Request::METHOD_GET, '/billing/subscribe');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('h1'));
        self::assertCount(0, $crawler->filter('.lp-billing-price'), 'no price is quoted when Stripe cannot be reached');
    }

    public function test_the_account_page_still_renders_its_other_sections(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $scenario = $this->scenarioWithoutStripeKeys();

        $client->loginUser($scenario->verifiedUser('nokeysaccount'));
        $crawler = $client->request(Request::METHOD_GET, '/account');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="export-section"]'));
        self::assertCount(1, $crawler->filter('[data-testid="billing-section"]'));
    }

    public function test_checkout_reports_that_it_is_unavailable_rather_than_crashing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $scenario = $this->scenarioWithoutStripeKeys();

        $client->loginUser($scenario->verifiedUser('nokeyscheckout'));
        $client->request(Request::METHOD_GET, '/projects');
        $client->request(Request::METHOD_POST, '/billing/checkout', ['_csrf_token' => 'csrf-token']);

        self::assertResponseRedirects('/billing/subscribe');
        $crawler = $client->followRedirect();
        self::assertCount(1, $crawler->filter('.lp-flash--error'));
    }
}
