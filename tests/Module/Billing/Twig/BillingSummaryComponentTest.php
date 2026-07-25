<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Twig;

use App\Module\Billing\Entity\BillingStatus;
use App\Tests\Support\BillingScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The billing section as it renders inside the account settings page.
 */
final class BillingSummaryComponentTest extends WebTestCase
{
    public function test_billing_is_absent_from_the_account_page_while_the_flag_is_off(): void
    {
        $client = static::createClient();
        $scenario = new BillingScenario(static::getContainer());

        $client->loginUser($scenario->verifiedUser('nobilling'));
        $crawler = $client->request(Request::METHOD_GET, '/account');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="billing-section"]'));
        self::assertCount(1, $crawler->filter('[data-testid="export-section"]'), 'the rest of the page is untouched');
    }

    public function test_a_trialing_user_sees_their_trial_and_a_way_to_subscribe(): void
    {
        $client = static::createClient();
        $scenario = new BillingScenario(static::getContainer());
        $scenario->enableBilling();
        $user = $scenario->verifiedUser('trialaccount');
        $scenario->profile($user, new \DateTimeImmutable('+3 days'));

        $client->loginUser($user);
        $crawler = $client->request(Request::METHOD_GET, '/account');

        self::assertResponseIsSuccessful();
        $section = $crawler->filter('[data-testid="billing-section"]');
        self::assertCount(1, $section);
        self::assertStringContainsString('trial', $section->text());
        self::assertCount(1, $section->filter('a[href="/billing/subscribe"]'));
    }

    public function test_a_subscriber_is_pointed_at_managing_their_subscription(): void
    {
        $client = static::createClient();
        $scenario = new BillingScenario(static::getContainer());
        $scenario->enableBilling();
        $user = $scenario->verifiedUser('subaccount');
        $profile = $scenario->profile($user, new \DateTimeImmutable('-30 days'));
        $profile->status = BillingStatus::Active;
        $profile->stripeSubscriptionId = 'sub_account';
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->loginUser($user);
        $crawler = $client->request(Request::METHOD_GET, '/account');

        self::assertResponseIsSuccessful();
        $section = $crawler->filter('[data-testid="billing-section"]');
        self::assertCount(1, $section);
        self::assertSame('Manage subscription', trim($section->filter('a[href="/billing/subscribe"]')->text()));
    }
}
