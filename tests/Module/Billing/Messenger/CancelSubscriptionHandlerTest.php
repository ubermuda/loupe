<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Messenger;

use App\Audit\AuditChannel;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\NullAuditActorProvider;
use App\Module\Billing\Messenger\CancelSubscriptionHandler;
use App\Module\Billing\Messenger\CancelSubscriptionMessage;
use App\Module\Billing\Service\StripeGatewayInterface;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class CancelSubscriptionHandlerTest extends TestCase
{
    private RecordingAuditor $audit;

    #[\Override]
    protected function setUp(): void
    {
        $this->audit = new RecordingAuditor(new NullAuditActorProvider());
    }

    public function test_cancels_the_subscription_via_the_gateway(): void
    {
        /** @var StripeGatewayInterface&MockObject $gateway */
        $gateway = $this->createMock(StripeGatewayInterface::class);
        $gateway->expects(self::once())->method('cancelSubscription')->with('sub_123');

        $handler = new CancelSubscriptionHandler($gateway, $this->audit->auditor);
        $handler(new CancelSubscriptionMessage('sub_123', 'cus_123', 'user-id-123'));
    }

    /**
     * The handler must not catch StripeGateway failures: letting the
     * exception propagate is what makes the messenger `async` transport's
     * retry_strategy (messenger.yaml) retry the cancellation.
     */
    public function test_a_gateway_failure_propagates_so_messenger_retries(): void
    {
        /** @var StripeGatewayInterface&Stub $gateway */
        $gateway = $this->createStub(StripeGatewayInterface::class);
        $gateway->method('cancelSubscription')->willThrowException(new \RuntimeException('Stripe is down'));

        $handler = new CancelSubscriptionHandler($gateway, $this->audit->auditor);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stripe is down');
        $handler(new CancelSubscriptionMessage('sub_123', 'cus_123', 'user-id-123'));
    }

    public function test_a_failed_cancellation_records_nothing(): void
    {
        /** @var StripeGatewayInterface&Stub $gateway */
        $gateway = $this->createStub(StripeGatewayInterface::class);
        $gateway->method('cancelSubscription')->willThrowException(new \RuntimeException('Stripe is down'));

        $handler = new CancelSubscriptionHandler($gateway, $this->audit->auditor);

        try {
            $handler(new CancelSubscriptionMessage('sub_123', 'cus_123', 'user-id-123'));
            self::fail('a gateway failure must propagate');
        } catch (\RuntimeException) {
        }

        self::assertSame([], $this->audit->operations());
    }

    public function test_the_cancellation_is_recorded_against_the_deleted_user(): void
    {
        $handler = new CancelSubscriptionHandler($this->createStub(StripeGatewayInterface::class), $this->audit->auditor);

        $handler(new CancelSubscriptionMessage('sub_123', 'cus_123', 'user-id-123'));

        $record = $this->audit->record('account.deletion_stripe_subscription_canceled');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('user', $record->subject->type);
        self::assertSame('user-id-123', $record->subject->id);
        self::assertSame(['userId' => 'user-id-123'], $record->context);

        self::assertSame(['account.deletion_stripe_subscription_canceled'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
    }

    /**
     * The account row is already gone, so nothing resolves an actor here. The
     * record says who the subscription belonged to and stops there.
     */
    public function test_the_record_names_no_actor(): void
    {
        $handler = new CancelSubscriptionHandler($this->createStub(StripeGatewayInterface::class), $this->audit->auditor);

        $handler(new CancelSubscriptionMessage('sub_123', 'cus_123', 'user-id-123'));

        $record = $this->audit->record('account.deletion_stripe_subscription_canceled');
        self::assertNull($record->actor);
        self::assertNull($record->actorIdentifier);
        self::assertNull($record->credential);
        self::assertSame(AuditChannel::System->value, $record->channel);
    }

    /** A Stripe id names an object in someone else's system, so it stays out. */
    public function test_the_record_carries_no_stripe_identifier(): void
    {
        $handler = new CancelSubscriptionHandler($this->createStub(StripeGatewayInterface::class), $this->audit->auditor);

        $handler(new CancelSubscriptionMessage('sub_123', 'cus_123', 'user-id-123'));

        $context = $this->audit->record('account.deletion_stripe_subscription_canceled')->context;
        self::assertArrayNotHasKey('stripeSubscriptionId', $context);
        self::assertArrayNotHasKey('stripeCustomerId', $context);
        self::assertSame([], array_filter(
            $context,
            static fn (string|int|float|bool|null $value): bool => \is_string($value) && str_starts_with($value, 'sub_'),
        ));
    }

    /**
     * The whole log line, not only its message. Asserting the record alone
     * leaves what the sink emits unpinned, and the log stream is where the
     * Stripe subscription id used to be.
     */
    public function test_the_log_line_carries_the_record_and_no_stripe_identifier(): void
    {
        $handler = new CancelSubscriptionHandler($this->createStub(StripeGatewayInterface::class), $this->audit->auditor);

        $handler(new CancelSubscriptionMessage('sub_123', 'cus_123', 'user-id-123'));

        self::assertCount(1, $this->audit->domainChannel->records);
        self::assertSame([
            'userId' => 'user-id-123',
            'outcome' => 'success',
            'channel' => AuditChannel::System->value,
            'subjectType' => 'user',
            'subjectId' => 'user-id-123',
        ], $this->audit->domainChannel->records[0]['context']);
    }

    public function test_the_handler_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(CancelSubscriptionHandler::class);
    }
}
