<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Controller;

use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\RegistrationGate;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Service\PriceView;
use App\Module\Billing\Service\StripeGatewayInterface;
use App\Tests\Support\BillingScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

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
        $profile->status = BillingStatus::Active;
        $profile->stripeSubscriptionId = 'sub_123';
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->loginUser($user);
        $crawler = $client->request(Request::METHOD_GET, '/billing/subscribe');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form[action="/billing/portal"]'));
        self::assertCount(0, $crawler->filter('form[action="/billing/checkout"]'));
    }

    public function test_an_unpaid_subscription_is_managed_in_the_portal_not_re_purchased(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $scenario = new BillingScenario(static::getContainer());
        $scenario->enableBilling();
        $user = $scenario->verifiedUser('pastdue');
        $profile = $scenario->profile($user, new \DateTimeImmutable('-30 days'));
        $profile->status = BillingStatus::PastDue;
        $profile->stripeSubscriptionId = 'sub_pastdue';
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->loginUser($user);
        $crawler = $client->request(Request::METHOD_GET, '/billing/subscribe');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form[action="/billing/portal"]'));
        self::assertCount(0, $crawler->filter('form[action="/billing/checkout"]'));
    }

    public function test_a_disabled_user_at_full_capacity_gets_a_working_waitlist_cta(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $scenario = new BillingScenario(static::getContainer());
        $scenario->enableBilling();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = $scenario->verifiedUser('caplocked');
        $user->disabledAt = new \DateTimeImmutable();
        $scenario->profile($user, new \DateTimeImmutable('-30 days'));

        // Disabled accounts do not count towards the cap, so seed one active
        // user and cap at the resulting count — count(0) < cap(0) would leave
        // the gate open.
        $scenario->verifiedUser('capfiller');
        $activeCount = static::getContainer()->get(UserRepository::class)->countActive();
        $em->persist(new FeatureFlag(name: RegistrationGate::CAP_FLAG, type: FeatureFlagType::Int, value: $activeCount));
        $em->flush();
        $em->clear();

        $client->loginUser($user);
        $crawler = $client->request(Request::METHOD_GET, '/billing/subscribe');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[action="/billing/checkout"]'));
        $form = $crawler->filter('form[action="/waitlist"]');
        self::assertCount(1, $form);
        self::assertSame($user->email, $form->filter('input[name="waitlist_join_form[email]"]')->attr('value'));

        // Form-component forms validate _token against the stateless global
        // token id `submit`, so the rendered value must be the cookie-name
        // sentinel (what csrf_token('submit') yields — a session-backed token
        // id would render a 24+ char random value nothing validates), and the
        // data-controller hook is what lets csrf_protection_controller.js
        // double-submit it in a real browser.
        $tokenInput = $form->filter('input[name="waitlist_join_form[_token]"]');
        self::assertSame('csrf-token', $tokenInput->attr('value'));
        self::assertSame('csrf-protection', $tokenInput->attr('data-controller'));

        // Submitting the rendered form as-is must pass CSRF validation — a
        // token minted for the wrong token id would 422 here.
        $client->submitForm('Join the waitlist');
        self::assertResponseRedirects('/waitlist?joined=1');
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
