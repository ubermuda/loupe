<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Command;

use App\Audit\AuditChannel;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\NullAuditActorProvider;
use App\Module\Billing\Command\OpenPortalCommand;
use App\Module\Billing\Command\OpenPortalHandler;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Service\StripeGatewayInterface;
use App\Tests\Support\BillingGrants;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\FeatureFlags;
use App\Tests\Support\RecordingAuditor;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class OpenPortalHandlerTest extends TestCase
{
    private const string PORTAL_URL = 'https://portal.stripe.test/s';

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

    /** @param array<string, bool|int|string> $flags */
    private function handler(
        StripeGatewayInterface $stripe,
        ?BillingProfile $profile,
        array $flags = ['billing.enabled' => true],
    ): OpenPortalHandler {
        $profiles = $this->createStub(BillingProfileRepository::class);
        $profiles->method('findOneByUser')->willReturn($profile);

        return new OpenPortalHandler($profiles, $stripe, FeatureFlags::service($flags), $this->logger, $this->audit->auditor);
    }

    public function test_a_customer_gets_a_portal_session(): void
    {
        $user = $this->user();
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('-1 day'));
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

        $handler = $this->handler($stripe, BillingGrants::profileWithTrial($user, new \DateTimeImmutable('-1 day')));

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
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('-1 day'));
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
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('-1 day'));
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

    public function test_an_opened_portal_is_recorded_against_the_user(): void
    {
        $user = $this->user();
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('-1 day'));
        $profile->stripeCustomerId = 'cus_123';

        $stripe = $this->createStub(StripeGatewayInterface::class);
        $stripe->method('createPortalSession')->willReturn(self::PORTAL_URL);

        ($this->handler($stripe, $profile))(new OpenPortalCommand($user, returnUrl: 'https://app/billing'));

        $record = $this->audit->record('billing.portal.opened');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('user', $record->subject->type);
        self::assertSame((string) $user->id, $record->subject->id);
        self::assertSame(['userId' => (string) $user->id], $record->context);

        self::assertSame(['billing.portal.opened'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
    }

    /**
     * The whole log line, not only its message: the sink is what puts the
     * record back into the log stream the handler used to write to directly.
     */
    public function test_the_log_line_the_sink_emits_carries_the_record(): void
    {
        $user = $this->user();
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('-1 day'));
        $profile->stripeCustomerId = 'cus_123';

        $stripe = $this->createStub(StripeGatewayInterface::class);
        $stripe->method('createPortalSession')->willReturn(self::PORTAL_URL);

        ($this->handler($stripe, $profile))(new OpenPortalCommand($user, returnUrl: 'https://app/billing'));

        self::assertCount(1, $this->audit->domainChannel->records);
        self::assertSame([
            'userId' => (string) $user->id,
            'outcome' => 'success',
            'channel' => AuditChannel::System->value,
            'subjectType' => 'user',
            'subjectId' => (string) $user->id,
        ], $this->audit->domainChannel->records[0]['context']);
    }

    public function test_a_stripe_failure_stays_a_diagnostic_and_records_nothing(): void
    {
        $user = $this->user();
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('-1 day'));
        $profile->stripeCustomerId = 'cus_123';

        $stripe = $this->createStub(StripeGatewayInterface::class);
        $stripe->method('createPortalSession')->willThrowException(new \RuntimeException('stripe is down'));

        try {
            ($this->handler($stripe, $profile))(new OpenPortalCommand($user, returnUrl: 'https://app/billing'));
            self::fail('expected DomainErrors');
        } catch (DomainErrors) {
        }

        self::assertSame([], $this->audit->operations());
        self::assertSame(
            ['billing.portal.stripe_failed'],
            array_map(static fn (array $entry): string => $entry['message'], $this->logger->records),
        );
    }

    /**
     * The handler keeps its logger for the Stripe-failure diagnostic, so the
     * reflection check cannot apply. The opened-portal operation must still
     * reach the log stream through the sink alone.
     */
    public function test_the_opened_operation_is_never_logged_directly(): void
    {
        $user = $this->user();
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('-1 day'));
        $profile->stripeCustomerId = 'cus_123';

        $stripe = $this->createStub(StripeGatewayInterface::class);
        $stripe->method('createPortalSession')->willReturn(self::PORTAL_URL);

        ($this->handler($stripe, $profile))(new OpenPortalCommand($user, returnUrl: 'https://app/billing'));

        DirectLogging::assertOperationNotLoggedBy($this->logger, 'billing.portal.opened');
    }
}
