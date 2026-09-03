<?php

declare(strict_types=1);

namespace App\Module\Billing\Messenger;

use App\Module\Billing\Service\StripeGatewayInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

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
        private Auditor $auditor,
    ) {
    }

    public function __invoke(CancelSubscriptionMessage $message): void
    {
        $this->stripeGateway->cancelSubscription($message->stripeSubscriptionId);

        // The Stripe subscription id stays out: it names a Stripe object, and
        // an audit context carries this app's own identifiers only. The user
        // the subscription belonged to is the subject, which is what the
        // record has to say.
        $this->auditor->record(
            'account.deletion_stripe_subscription_canceled',
            AuditOutcome::Success,
            ['userId' => $message->deletedUserId],
            new AuditSubject('user', $message->deletedUserId),
        );
    }
}
