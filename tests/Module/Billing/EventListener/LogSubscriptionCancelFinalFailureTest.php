<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\EventListener;

use App\Module\Audit\AuditOutcome;
use App\Module\Audit\NullAuditActorProvider;
use App\Module\Billing\EventListener\LogSubscriptionCancelFinalFailure;
use App\Module\Billing\Messenger\CancelSubscriptionMessage;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

final class LogSubscriptionCancelFinalFailureTest extends TestCase
{
    private RecordingAuditor $audit;
    private LogSubscriptionCancelFinalFailure $listener;

    protected function setUp(): void
    {
        $this->audit = new RecordingAuditor(new NullAuditActorProvider());
        $this->listener = new LogSubscriptionCancelFinalFailure($this->audit->auditor);
    }

    public function test_it_records_a_failure_once_retries_are_exhausted(): void
    {
        ($this->listener)($this->event());

        $record = $this->audit->record('account.deletion_stripe_cancel_permanently_failed');
        self::assertSame(AuditOutcome::Failed, $record->outcome);
        self::assertSame(['userId' => 'user-id-123'], $record->context);
        self::assertNotNull($record->subject);
        self::assertSame('user', $record->subject->type);
        self::assertSame('user-id-123', $record->subject->id);
    }

    /** The Stripe identifiers name objects in another system and have no erasure path here. */
    public function test_the_stripe_identifiers_stay_out_of_the_trail(): void
    {
        ($this->listener)($this->event());

        $serialised = json_encode($this->audit->sink->events, \JSON_THROW_ON_ERROR)
            .json_encode($this->audit->domainChannel->records, \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('sub_123', $serialised);
        self::assertStringNotContainsString('cus_123', $serialised);
    }

    public function test_a_failure_that_will_still_retry_is_left_untouched(): void
    {
        $event = $this->event();
        $event->setForRetry();

        ($this->listener)($event);

        self::assertSame([], $this->audit->operations());
    }

    public function test_an_unrelated_message_is_ignored(): void
    {
        ($this->listener)(new WorkerMessageFailedEvent(
            new Envelope(new \stdClass()),
            'async',
            new \RuntimeException('boom'),
        ));

        self::assertSame([], $this->audit->operations());
    }

    public function test_the_listener_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(LogSubscriptionCancelFinalFailure::class);
    }

    private function event(): WorkerMessageFailedEvent
    {
        return new WorkerMessageFailedEvent(
            new Envelope(new CancelSubscriptionMessage('sub_123', 'cus_123', 'user-id-123')),
            'async',
            new \RuntimeException('boom'),
        );
    }
}
