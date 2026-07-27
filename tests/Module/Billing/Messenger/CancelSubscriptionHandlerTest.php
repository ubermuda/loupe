<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Messenger;

use App\Module\Billing\Messenger\CancelSubscriptionHandler;
use App\Module\Billing\Messenger\CancelSubscriptionMessage;
use App\Module\Billing\Service\StripeGatewayInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CancelSubscriptionHandlerTest extends TestCase
{
    public function test_cancels_the_subscription_via_the_gateway(): void
    {
        /** @var StripeGatewayInterface&MockObject $gateway */
        $gateway = $this->createMock(StripeGatewayInterface::class);
        $gateway->expects(self::once())->method('cancelSubscription')->with('sub_123');

        $handler = new CancelSubscriptionHandler($gateway, new NullLogger());
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

        $handler = new CancelSubscriptionHandler($gateway, new NullLogger());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stripe is down');
        $handler(new CancelSubscriptionMessage('sub_123', 'cus_123', 'user-id-123'));
    }
}
