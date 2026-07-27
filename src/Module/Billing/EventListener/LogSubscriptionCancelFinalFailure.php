<?php

declare(strict_types=1);

namespace App\Module\Billing\EventListener;

use App\Module\Billing\Messenger\CancelSubscriptionMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * The account row is already deleted by the time a CancelSubscriptionMessage
 * exhausts its retries, so there is nothing left to mark failed — this error
 * log is the only durable reconciliation record. The customer remains
 * findable in the Stripe dashboard by the logged customer id, and support
 * cancels manually. Mirrors MarkExportFailedOnFinalFailure's shape.
 */
#[AsEventListener]
final readonly class LogSubscriptionCancelFinalFailure
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof CancelSubscriptionMessage || $event->willRetry()) {
            return;
        }

        $this->logger->error('account.deletion.stripe_cancel_permanently_failed', [
            'userId' => $message->deletedUserId,
            'stripeSubscriptionId' => $message->stripeSubscriptionId,
            'stripeCustomerId' => $message->stripeCustomerId,
        ]);
    }
}
