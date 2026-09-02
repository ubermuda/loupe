<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\EventListener;

use App\Module\Billing\EventListener\LogSubscriptionCancelFinalFailure;
use App\Module\Billing\Messenger\CancelSubscriptionMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

final class LogSubscriptionCancelFinalFailureTest extends TestCase
{
    public function test_logs_an_error_once_retries_are_exhausted(): void
    {
        /** @var LoggerInterface&MockObject $logger */
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'account.deletion_stripe_cancel_permanently_failed',
            [
                'userId' => 'user-id-123',
                'stripeSubscriptionId' => 'sub_123',
                'stripeCustomerId' => 'cus_123',
            ],
        );

        $listener = new LogSubscriptionCancelFinalFailure($logger);

        $event = new WorkerMessageFailedEvent(
            new Envelope(new CancelSubscriptionMessage('sub_123', 'cus_123', 'user-id-123')),
            'async',
            new \RuntimeException('boom'),
        );

        $listener($event);
    }

    public function test_a_failure_that_will_still_retry_is_left_untouched(): void
    {
        /** @var LoggerInterface&MockObject $logger */
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $listener = new LogSubscriptionCancelFinalFailure($logger);

        $event = new WorkerMessageFailedEvent(
            new Envelope(new CancelSubscriptionMessage('sub_123', 'cus_123', 'user-id-123')),
            'async',
            new \RuntimeException('boom'),
        );
        $event->setForRetry();

        $listener($event);
    }

    public function test_an_unrelated_message_is_ignored(): void
    {
        /** @var LoggerInterface&MockObject $logger */
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $listener = new LogSubscriptionCancelFinalFailure($logger);

        $event = new WorkerMessageFailedEvent(
            new Envelope(new \stdClass()),
            'async',
            new \RuntimeException('boom'),
        );

        $listener($event);
    }
}
