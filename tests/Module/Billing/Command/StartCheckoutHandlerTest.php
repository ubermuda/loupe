<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Command;

use App\Audit\AuditChannel;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Account\Service\InstallationState;
use App\Module\Account\Service\RegistrationGate;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\NullAuditActorProvider;
use App\Module\Billing\Command\StartCheckoutCommand;
use App\Module\Billing\Command\StartCheckoutHandler;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Service\ActivePriceProvider;
use App\Module\Billing\Service\StripeGatewayInterface;
use App\Module\Billing\Service\TrialProvisioner;
use App\Tests\Support\BillingGrants;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\FeatureFlags;
use App\Tests\Support\RecordingAuditor;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\TransactionalEntityManagerStub;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Uid\Uuid;

final class StartCheckoutHandlerTest extends TestCase
{
    private const string CHECKOUT_URL = 'https://checkout.stripe.test/s';

    /** One active user against a cap of one: the gate is closed. */
    private const array CAP_CLOSED_FLAGS = ['billing.enabled' => true, 'billing.stripe_price_id' => 'price_123', 'registration.cap' => 1];

    private RecordingAuditor $audit;

    private RecordingLogger $logger;

    #[\Override]
    protected function setUp(): void
    {
        $this->audit = new RecordingAuditor(new NullAuditActorProvider());
        $this->logger = new RecordingLogger();
    }

    private function user(): User
    {
        $user = new User(fullName: 'Paying User', email: 'payer@example.com', password: 'irrelevant');
        new \ReflectionProperty(User::class, 'id')->setValue($user, Uuid::v4());

        return $user;
    }

    private function command(User $user, ?string $inviteToken = null): StartCheckoutCommand
    {
        return new StartCheckoutCommand($user, successUrl: 'https://app/success', cancelUrl: 'https://app/cancel', inviteToken: $inviteToken);
    }

    /** @param array<string, bool|int|string> $flags */
    private function handler(
        StripeGatewayInterface $stripe,
        BillingProfile $profile,
        array $flags = ['billing.enabled' => true, 'billing.stripe_price_id' => 'price_123'],
        ?WaitlistEntryRepository $waitlistEntries = null,
    ): StartCheckoutHandler {
        $profiles = $this->createStub(BillingProfileRepository::class);
        $profiles->method('findOneByUser')->willReturn($profile);

        $priceStripe = $this->createStub(StripeGatewayInterface::class);

        $users = $this->createStub(UserRepository::class);
        $users->method('countActive')->willReturn(1);

        return new StartCheckoutHandler(
            new TrialProvisioner($profiles, FeatureFlags::service($flags), $this->createStub(EntityManagerInterface::class)),
            new ActivePriceProvider(FeatureFlags::service($flags), $priceStripe, new ArrayAdapter(), new NullLogger()),
            $stripe,
            FeatureFlags::service($flags),
            TransactionalEntityManagerStub::configure($this->createStub(EntityManagerInterface::class)),
            $this->logger,
            new RegistrationGate(FeatureFlags::service($flags), $users, new InstallationState($users)),
            $waitlistEntries ?? $this->createStub(WaitlistEntryRepository::class),
            $this->audit->auditor,
        );
    }

    public function test_first_checkout_creates_the_stripe_customer_and_stores_its_id(): void
    {
        $user = $this->user();
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('+3 days'));

        $stripe = $this->createMock(StripeGatewayInterface::class);
        $stripe->expects($this->once())->method('createCustomer')->willReturn('cus_123');
        $stripe->expects($this->once())
            ->method('createCheckoutSession')
            ->with('cus_123', 'price_123', 'https://app/success', 'https://app/cancel', self::isString())
            ->willReturn(self::CHECKOUT_URL);

        $url = ($this->handler($stripe, $profile))($this->command($user));

        self::assertSame(self::CHECKOUT_URL, $url);
        self::assertSame('cus_123', $profile->stripeCustomerId);
    }

    public function test_an_existing_customer_id_is_reused(): void
    {
        $user = $this->user();
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('+3 days'));
        $profile->stripeCustomerId = 'cus_existing';

        $stripe = $this->createMock(StripeGatewayInterface::class);
        $stripe->expects($this->never())->method('createCustomer');
        $stripe->expects($this->once())
            ->method('createCheckoutSession')
            ->with('cus_existing', 'price_123', 'https://app/success', 'https://app/cancel', self::isString())
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
            BillingGrants::profileWithTrial($user, new \DateTimeImmutable('+3 days')),
            ['billing.enabled' => true],
        );

        try {
            $handler($this->command($user));
            self::fail('expected DomainErrors');
        } catch (DomainErrors $e) {
            self::assertContains('billing.error.no_active_price', $e->errors);
        }
    }

    public function test_a_customer_with_a_live_subscription_is_never_sent_to_checkout_again(): void
    {
        $user = $this->user();
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('-30 days'));
        $profile->stripeCustomerId = 'cus_existing';
        BillingGrants::stripe($profile, BillingStatus::PastDue, new \DateTimeImmutable('-1 day'), 'sub_existing');

        $stripe = $this->createMock(StripeGatewayInterface::class);
        $stripe->expects($this->never())->method('createCheckoutSession');

        try {
            ($this->handler($stripe, $profile))($this->command($user));
            self::fail('expected DomainErrors');
        } catch (DomainErrors $e) {
            self::assertContains('billing.error.subscription_exists', $e->errors);
        }
    }

    public function test_a_stripe_failure_becomes_a_domain_error_instead_of_a_crash(): void
    {
        $user = $this->user();
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('+3 days'));
        $profile->stripeCustomerId = 'cus_existing';

        $stripe = $this->createStub(StripeGatewayInterface::class);
        $stripe->method('createCheckoutSession')->willThrowException(new \RuntimeException('stripe is down'));

        try {
            ($this->handler($stripe, $profile))($this->command($user));
            self::fail('expected DomainErrors');
        } catch (DomainErrors $e) {
            self::assertContains('billing.error.stripe_unavailable', $e->errors);
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
            BillingGrants::profileWithTrial($user, new \DateTimeImmutable('+3 days')),
            ['billing.enabled' => false, 'billing.stripe_price_id' => 'price_123'],
        );

        try {
            $handler($this->command($user));
            self::fail('expected DomainErrors');
        } catch (DomainErrors $e) {
            self::assertContains('billing.error.disabled', $e->errors);
        }
    }

    public function test_a_disabled_user_cannot_start_checkout_when_the_cap_is_full(): void
    {
        $user = $this->user();
        $user->disabledAt = new \DateTimeImmutable();

        $stripe = $this->createMock(StripeGatewayInterface::class);
        $stripe->expects($this->never())->method('createCustomer');
        $stripe->expects($this->never())->method('createCheckoutSession');

        $handler = $this->handler(
            $stripe,
            BillingGrants::profileWithTrial($user, new \DateTimeImmutable('-30 days')),
            self::CAP_CLOSED_FLAGS,
        );

        try {
            $handler($this->command($user));
            self::fail('expected DomainErrors');
        } catch (DomainErrors $e) {
            self::assertContains('billing.error.capacity_full', $e->errors);
        }
    }

    public function test_a_valid_matching_invite_lets_a_disabled_user_through_a_full_cap(): void
    {
        $user = $this->user();
        $user->disabledAt = new \DateTimeImmutable();
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('-30 days'));
        $profile->stripeCustomerId = 'cus_existing';

        $entry = new WaitlistEntry('payer@example.com');
        $token = $entry->issueInviteToken();
        $waitlistEntries = $this->createStub(WaitlistEntryRepository::class);
        $waitlistEntries->method('findOneByValidInviteToken')->willReturn($entry);

        $stripe = $this->createMock(StripeGatewayInterface::class);
        $stripe->expects($this->once())->method('createCheckoutSession')->willReturn(self::CHECKOUT_URL);

        $url = ($this->handler($stripe, $profile, self::CAP_CLOSED_FLAGS, $waitlistEntries))($this->command($user, $token));

        self::assertSame(self::CHECKOUT_URL, $url);
    }

    public function test_a_disabled_user_can_start_checkout_while_the_cap_has_room(): void
    {
        $user = $this->user();
        $user->disabledAt = new \DateTimeImmutable();
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('-30 days'));
        $profile->stripeCustomerId = 'cus_existing';

        $stripe = $this->createMock(StripeGatewayInterface::class);
        $stripe->expects($this->once())->method('createCheckoutSession')->willReturn(self::CHECKOUT_URL);

        $url = ($this->handler($stripe, $profile))($this->command($user));

        self::assertSame(self::CHECKOUT_URL, $url);
    }

    public function test_the_cap_never_gates_an_enabled_user(): void
    {
        $user = $this->user();
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('+3 days'));
        $profile->stripeCustomerId = 'cus_existing';

        $stripe = $this->createMock(StripeGatewayInterface::class);
        $stripe->expects($this->once())->method('createCheckoutSession')->willReturn(self::CHECKOUT_URL);

        $url = ($this->handler($stripe, $profile, self::CAP_CLOSED_FLAGS))($this->command($user));

        self::assertSame(self::CHECKOUT_URL, $url);
    }

    public function test_an_invite_issued_to_a_different_email_does_not_open_a_full_cap(): void
    {
        $user = $this->user();
        $user->disabledAt = new \DateTimeImmutable();

        $entry = new WaitlistEntry('someone-else@example.com');
        $token = $entry->issueInviteToken();
        $waitlistEntries = $this->createStub(WaitlistEntryRepository::class);
        $waitlistEntries->method('findOneByValidInviteToken')->willReturn($entry);

        $stripe = $this->createMock(StripeGatewayInterface::class);
        $stripe->expects($this->never())->method('createCheckoutSession');

        $handler = $this->handler(
            $stripe,
            BillingGrants::profileWithTrial($user, new \DateTimeImmutable('-30 days')),
            self::CAP_CLOSED_FLAGS,
            $waitlistEntries,
        );

        try {
            $handler($this->command($user, $token));
            self::fail('expected DomainErrors');
        } catch (DomainErrors $e) {
            self::assertContains('billing.error.capacity_full', $e->errors);
        }
    }

    public function test_a_started_checkout_is_recorded_against_the_user(): void
    {
        $user = $this->user();
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('+3 days'));
        $profile->stripeCustomerId = 'cus_existing';

        $stripe = $this->createStub(StripeGatewayInterface::class);
        $stripe->method('createCheckoutSession')->willReturn(self::CHECKOUT_URL);

        ($this->handler($stripe, $profile))($this->command($user));

        $record = $this->audit->record('billing.checkout.started');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('user', $record->subject->type);
        self::assertSame((string) $user->id, $record->subject->id);
        self::assertSame(['userId' => (string) $user->id], $record->context);

        self::assertSame(['billing.checkout.started'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
    }

    /** A Stripe price id names an object in someone else's system. */
    public function test_the_record_carries_no_stripe_price_identifier(): void
    {
        $user = $this->user();
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('+3 days'));
        $profile->stripeCustomerId = 'cus_existing';

        $stripe = $this->createStub(StripeGatewayInterface::class);
        $stripe->method('createCheckoutSession')->willReturn(self::CHECKOUT_URL);

        ($this->handler($stripe, $profile))($this->command($user));

        $context = $this->audit->record('billing.checkout.started')->context;
        self::assertArrayNotHasKey('priceId', $context);
        self::assertSame([], array_filter(
            $context,
            static fn (string|int|float|bool|null $value): bool => \is_string($value) && str_starts_with($value, 'price_'),
        ));
    }

    public function test_a_stripe_failure_stays_a_diagnostic_and_records_nothing(): void
    {
        $user = $this->user();
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('+3 days'));
        $profile->stripeCustomerId = 'cus_existing';

        $stripe = $this->createStub(StripeGatewayInterface::class);
        $stripe->method('createCheckoutSession')->willThrowException(new \RuntimeException('stripe is down'));

        try {
            ($this->handler($stripe, $profile))($this->command($user));
            self::fail('expected DomainErrors');
        } catch (DomainErrors) {
        }

        self::assertSame([], $this->audit->operations());
        self::assertSame(
            ['billing.checkout.stripe_failed'],
            array_map(static fn (array $entry): string => $entry['message'], $this->logger->records),
        );
    }

    /**
     * The handler keeps its logger for the Stripe-failure diagnostic, so the
     * reflection check cannot apply. The started operation must still reach
     * the log stream through the sink alone.
     */
    public function test_the_started_operation_is_never_logged_directly(): void
    {
        $user = $this->user();
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('+3 days'));
        $profile->stripeCustomerId = 'cus_existing';

        $stripe = $this->createStub(StripeGatewayInterface::class);
        $stripe->method('createCheckoutSession')->willReturn(self::CHECKOUT_URL);

        ($this->handler($stripe, $profile))($this->command($user));

        DirectLogging::assertOperationNotLoggedBy($this->logger, 'billing.checkout.started');
    }

    /**
     * The whole log line, not only its message: the sink is what puts the
     * record back into the log stream, and the price id used to be there.
     */
    public function test_the_log_line_the_sink_emits_carries_the_record(): void
    {
        $user = $this->user();
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('+3 days'));
        $profile->stripeCustomerId = 'cus_existing';

        $stripe = $this->createStub(StripeGatewayInterface::class);
        $stripe->method('createCheckoutSession')->willReturn(self::CHECKOUT_URL);

        ($this->handler($stripe, $profile))($this->command($user));

        self::assertCount(1, $this->audit->domainChannel->records);
        self::assertSame([
            'userId' => (string) $user->id,
            'outcome' => 'success',
            'channel' => AuditChannel::System->value,
            'subjectType' => 'user',
            'subjectId' => (string) $user->id,
        ], $this->audit->domainChannel->records[0]['context']);
    }
}
