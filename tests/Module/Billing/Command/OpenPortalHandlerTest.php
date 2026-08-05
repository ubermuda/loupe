<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Billing\Command\OpenPortalCommand;
use App\Module\Billing\Command\OpenPortalHandler;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Service\StripeGatewayInterface;
use App\Tests\Support\FeatureFlags;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class OpenPortalHandlerTest extends TestCase
{
    private const string PORTAL_URL = 'https://portal.stripe.test/s';

    private function user(): User
    {
        return new User(fullName: 'Paying User', email: 'payer@example.com', password: 'irrelevant');
    }

    /** @param array<string, bool|int|string> $flags */
    private function handler(
        StripeGatewayInterface $stripe,
        ?BillingProfile $profile,
        array $flags = ['billing.enabled' => true],
    ): OpenPortalHandler {
        $profiles = $this->createStub(BillingProfileRepository::class);
        $profiles->method('findOneByUser')->willReturn($profile);

        return new OpenPortalHandler($profiles, $stripe, FeatureFlags::service($flags), new NullLogger());
    }

    public function test_a_customer_gets_a_portal_session(): void
    {
        $user = $this->user();
        $profile = new BillingProfile($user, trialEndsAt: new \DateTimeImmutable('-1 day'));
        $profile->stripeCustomerId = 'cus_123';

        $stripe = $this->createMock(StripeGatewayInterface::class);
        $stripe->expects($this->once())
            ->method('createPortalSession')
            ->with('cus_123', 'https://app/billing')
            ->willReturn(self::PORTAL_URL);

        $url = ($this->handler($stripe, $profile))(new OpenPortalCommand($user, returnUrl: 'https://app/billing'));

        self::assertSame(self::PORTAL_URL, $url);
    }

    public function test_a_user_without_a_stripe_customer_is_a_domain_error(): void
    {
        $user = $this->user();
        $stripe = $this->createMock(StripeGatewayInterface::class);
        $stripe->expects($this->never())->method('createPortalSession');

        $handler = $this->handler($stripe, new BillingProfile($user, trialEndsAt: new \DateTimeImmutable('-1 day')));

        try {
            $handler(new OpenPortalCommand($user, returnUrl: 'https://app/billing'));
            self::fail('expected DomainErrors');
        } catch (DomainErrors $e) {
            self::assertContains('billing.error.no_customer', $e->errors);
        }
    }

    public function test_a_user_without_a_profile_is_a_domain_error(): void
    {
        $user = $this->user();
        $stripe = $this->createMock(StripeGatewayInterface::class);
        $stripe->expects($this->never())->method('createPortalSession');

        $handler = $this->handler($stripe, null);

        $this->expectException(DomainErrors::class);
        $handler(new OpenPortalCommand($user, returnUrl: 'https://app/billing'));
    }

    public function test_a_stripe_failure_becomes_a_domain_error_instead_of_a_crash(): void
    {
        $user = $this->user();
        $profile = new BillingProfile($user, trialEndsAt: new \DateTimeImmutable('-1 day'));
        $profile->stripeCustomerId = 'cus_123';

        $stripe = $this->createStub(StripeGatewayInterface::class);
        $stripe->method('createPortalSession')->willThrowException(new \RuntimeException('stripe is down'));

        try {
            ($this->handler($stripe, $profile))(new OpenPortalCommand($user, returnUrl: 'https://app/billing'));
            self::fail('expected DomainErrors');
        } catch (DomainErrors $e) {
            self::assertContains('billing.error.stripe_unavailable', $e->errors);
        }
    }

    public function test_billing_disabled_is_a_domain_error(): void
    {
        $user = $this->user();
        $profile = new BillingProfile($user, trialEndsAt: new \DateTimeImmutable('-1 day'));
        $profile->stripeCustomerId = 'cus_123';

        $stripe = $this->createMock(StripeGatewayInterface::class);
        $stripe->expects($this->never())->method('createPortalSession');

        $handler = $this->handler($stripe, $profile, ['billing.enabled' => false]);

        try {
            $handler(new OpenPortalCommand($user, returnUrl: 'https://app/billing'));
            self::fail('expected DomainErrors');
        } catch (DomainErrors $e) {
            self::assertContains('billing.error.disabled', $e->errors);
        }
    }
}
