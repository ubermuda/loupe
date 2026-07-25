<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Billing\Command\StartCheckoutCommand;
use App\Module\Billing\Command\StartCheckoutHandler;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Service\ActivePriceProvider;
use App\Module\Billing\Service\StripeGatewayInterface;
use App\Module\Billing\Service\TrialProvisioner;
use App\Tests\Support\FeatureFlags;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class StartCheckoutHandlerTest extends TestCase
{
    private const string CHECKOUT_URL = 'https://checkout.stripe.test/s';

    private function user(): User
    {
        return new User(username: 'payer', fullName: 'Paying User', email: 'payer@example.com', password: 'irrelevant');
    }

    private function command(User $user): StartCheckoutCommand
    {
        return new StartCheckoutCommand($user, successUrl: 'https://app/success', cancelUrl: 'https://app/cancel');
    }

    /** @param array<string, bool|int|string> $flags */
    private function handler(
        StripeGatewayInterface $stripe,
        BillingProfile $profile,
        array $flags = ['billing.enabled' => true, 'billing.stripe_price_id' => 'price_123'],
    ): StartCheckoutHandler {
        $profiles = $this->createStub(BillingProfileRepository::class);
        $profiles->method('findOneByUser')->willReturn($profile);

        $priceStripe = $this->createStub(StripeGatewayInterface::class);

        return new StartCheckoutHandler(
            new TrialProvisioner($profiles, FeatureFlags::service($flags), $this->createStub(EntityManagerInterface::class)),
            new ActivePriceProvider(FeatureFlags::service($flags), $priceStripe, new ArrayAdapter(), new NullLogger()),
            $stripe,
            FeatureFlags::service($flags),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
        );
    }

    public function test_first_checkout_creates_the_stripe_customer_and_stores_its_id(): void
    {
        $user = $this->user();
        $profile = new BillingProfile($user, trialEndsAt: new \DateTimeImmutable('+3 days'));

        $stripe = $this->createMock(StripeGatewayInterface::class);
        $stripe->expects($this->once())->method('createCustomer')->willReturn('cus_123');
        $stripe->expects($this->once())
            ->method('createCheckoutSession')
            ->with('cus_123', 'price_123', 'https://app/success', 'https://app/cancel')
            ->willReturn(self::CHECKOUT_URL);

        $url = ($this->handler($stripe, $profile))($this->command($user));

        self::assertSame(self::CHECKOUT_URL, $url);
        self::assertSame('cus_123', $profile->stripeCustomerId);
    }

    public function test_an_existing_customer_id_is_reused(): void
    {
        $user = $this->user();
        $profile = new BillingProfile($user, trialEndsAt: new \DateTimeImmutable('+3 days'));
        $profile->stripeCustomerId = 'cus_existing';

        $stripe = $this->createMock(StripeGatewayInterface::class);
        $stripe->expects($this->never())->method('createCustomer');
        $stripe->expects($this->once())
            ->method('createCheckoutSession')
            ->with('cus_existing', 'price_123', 'https://app/success', 'https://app/cancel')
            ->willReturn(self::CHECKOUT_URL);

        ($this->handler($stripe, $profile))($this->command($user));

        self::assertSame('cus_existing', $profile->stripeCustomerId);
    }

    public function test_missing_price_flag_is_a_domain_error_and_creates_no_stripe_state(): void
    {
        $user = $this->user();
        $stripe = $this->createMock(StripeGatewayInterface::class);
        $stripe->expects($this->never())->method('createCustomer');
        $stripe->expects($this->never())->method('createCheckoutSession');

        $handler = $this->handler(
            $stripe,
            new BillingProfile($user, trialEndsAt: new \DateTimeImmutable('+3 days')),
            ['billing.enabled' => true],
        );

        try {
            $handler($this->command($user));
            self::fail('expected DomainErrors');
        } catch (DomainErrors $e) {
            self::assertContains('billing.error.no_active_price', $e->errors);
        }
    }

    public function test_billing_disabled_is_a_domain_error_and_creates_no_stripe_state(): void
    {
        $user = $this->user();
        $stripe = $this->createMock(StripeGatewayInterface::class);
        $stripe->expects($this->never())->method('createCustomer');
        $stripe->expects($this->never())->method('createCheckoutSession');

        $handler = $this->handler(
            $stripe,
            new BillingProfile($user, trialEndsAt: new \DateTimeImmutable('+3 days')),
            ['billing.enabled' => false, 'billing.stripe_price_id' => 'price_123'],
        );

        try {
            $handler($this->command($user));
            self::fail('expected DomainErrors');
        } catch (DomainErrors $e) {
            self::assertContains('billing.error.disabled', $e->errors);
        }
    }
}
