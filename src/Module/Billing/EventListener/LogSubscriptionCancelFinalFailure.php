<?php

declare(strict_types=1);

namespace App\Module\Billing\EventListener;

use App\Module\Billing\Messenger\CancelSubscriptionMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * The account row is already deleted by the time a CancelSubscriptionMessage
 * exhausts its retries, so there is nothing left to mark failed — this pair is
 * the only durable trace. The record carries the deleted account's own id; the
 * Stripe identifiers support cancels by stay in the log line, which is not a
 * store an erasure request has to reach.
 */
#[AsEventListener]
final readonly class LogSubscriptionCancelFinalFailure
{
    public function __construct(
        private LoggerInterface $logger,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof CancelSubscriptionMessage || $event->willRetry()) {
            return;
        }

        $this->auditor->record(
            'account.deletion_stripe_cancel_permanently_failed',
            AuditOutcome::Failed,
            ['userId' => $message->deletedUserId],
            new AuditSubject('user', $message->deletedUserId),
        );

        $this->logger->error('account.deletion_stripe_cancel_permanently_failed', [
            'userId' => $message->deletedUserId,
            'stripeSubscriptionId' => $message->stripeSubscriptionId,
            'stripeCustomerId' => $message->stripeCustomerId,
        ]);
    }
}
