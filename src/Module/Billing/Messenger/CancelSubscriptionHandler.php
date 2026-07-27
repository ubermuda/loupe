<?php

declare(strict_types=1);

namespace App\Module\Billing\Messenger;

use App\Module\Billing\Service\StripeGatewayInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Cancels the Stripe subscription for a deleted account. Deliberately does
 * not catch StripeGateway failures: letting the exception propagate is what
 * makes the `async` transport's retry_strategy (3 attempts, see
 * messenger.yaml) retry the cancellation. A permanent failure after retries
 * are exhausted is logged by LogSubscriptionCancelFinalFailure.
 */
#[AsMessageHandler]
final readonly class CancelSubscriptionHandler
{
    public function __construct(
        private StripeGatewayInterface $stripeGateway,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CancelSubscriptionMessage $message): void
    {
        $this->stripeGateway->cancelSubscription($message->stripeSubscriptionId);
        $this->logger->info('account.deletion.stripe_subscription_canceled', [
            'userId' => $message->deletedUserId,
            'stripeSubscriptionId' => $message->stripeSubscriptionId,
        ]);
    }
}
