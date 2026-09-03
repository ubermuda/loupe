<?php

declare(strict_types=1);

namespace App\Module\Billing\EventListener;

use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use App\Module\Billing\Messenger\CancelSubscriptionMessage;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * The account row is already deleted by the time a CancelSubscriptionMessage
 * exhausts its retries, so there is nothing left to mark failed — this record
 * is the only durable trace. It carries the deleted account's own id and no
 * Stripe identifier; StripeGateway stamps `app_user_id` on every customer it
 * creates, so support searches the dashboard by that and cancels manually.
 */
#[AsEventListener]
final readonly class LogSubscriptionCancelFinalFailure
{
    public function __construct(
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
    }
}
