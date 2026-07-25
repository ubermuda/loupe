<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Controller;

use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Service\PriceView;
use App\Module\Billing\Service\StripeGatewayInterface;
use App\Tests\Support\BillingScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShowSubscribeControllerTest extends WebTestCase
{
    private function stubPrice(KernelBrowser $client, string $priceId): void
    {
        $stripe = $this->createStub(StripeGatewayInterface::class);
        $stripe->method('retrievePrice')->willReturn(
            new PriceView(priceId: $priceId, unitAmount: 900, currency: 'eur', interval: 'month'),
        );
        $client->getContainer()->set(StripeGatewayInterface::class, $stripe);
    }

    public function test_anonymous_visitors_are_sent_to_the_login_page(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/billing/subscribe');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function test_a_trialing_user_sees_the_price_and_a_checkout_button(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $scenario = new BillingScenario(static::getContainer());
        $scenario->enableBilling();
        $scenario->priceFlag('price_subscribe_page');
        $this->stubPrice($client, 'price_subscribe_page');

        $client->loginUser($scenario->verifiedUser('subscriber'));
        $crawler = $client->request(Request::METHOD_GET, '/billing/subscribe');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('9.00', $crawler->filter('.lp-billing-price__amount')->text());
        self::assertSame('per month', $crawler->filter('.lp-billing-price__interval')->text());
        self::assertCount(1, $crawler->filter('form[action="/billing/checkout"]'));
    }

    public function test_visiting_the_page_provisions_the_trial(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $scenario = new BillingScenario(static::getContainer());
        $scenario->enableBilling();
        $user = $scenario->verifiedUser('provisioned');

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/billing/subscribe');

        self::assertResponseIsSuccessful();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $profile = static::getContainer()->get(BillingProfileRepository::class)->findOneBy(['user' => $user->id]);
        self::assertInstanceOf(BillingProfile::class, $profile);
        self::assertGreaterThan(new \DateTimeImmutable(), $profile->trialEndsAt);
    }

    public function test_an_expired_trial_shows_the_paywall_state_without_redirecting(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $scenario = new BillingScenario(static::getContainer());
        $scenario->enableBilling();
        $user = $scenario->verifiedUser('expired');
        $scenario->profile($user, new \DateTimeImmutable('-1 day'));

        $client->loginUser($user);
        $crawler = $client->request(Request::METHOD_GET, '/billing/subscribe');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.lp-billing-status--expired'));
    }

    public function test_a_subscribed_user_is_offered_the_portal_instead_of_checkout(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $scenario = new BillingScenario(static::getContainer());
        $scenario->enableBilling();
        $user = $scenario->verifiedUser('subscribed');
        $profile = $scenario->profile($user, new \DateTimeImmutable('-30 days'));
        $profile->status = \App\Module\Billing\Entity\BillingStatus::Active;
        $profile->stripeSubscriptionId = 'sub_123';
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->loginUser($user);
        $crawler = $client->request(Request::METHOD_GET, '/billing/subscribe');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form[action="/billing/portal"]'));
        self::assertCount(0, $crawler->filter('form[action="/billing/checkout"]'));
    }

    public function test_with_billing_disabled_the_page_offers_no_actions(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $scenario = new BillingScenario(static::getContainer());

        $client->loginUser($scenario->verifiedUser('freeuser'));
        $crawler = $client->request(Request::METHOD_GET, '/billing/subscribe');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[action="/billing/checkout"]'));
    }
}
