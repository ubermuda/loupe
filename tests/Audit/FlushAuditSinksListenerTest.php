<?php

declare(strict_types=1);

namespace App\Tests\Audit;

use App\Audit\EventListener\FlushAuditSinksListener;
use App\Module\Audit\Auditor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\EventListener\ResetServicesListener;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

/**
 * One test per event because each is the only end-of-work signal in its own
 * context: dropping any one of them loses records there and nowhere else, and a
 * shared case would not say which.
 */
final class FlushAuditSinksListenerTest extends KernelTestCase
{
    private Auditor $auditor;
    private EventDispatcherInterface $dispatcher;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->auditor = static::getContainer()->get(Auditor::class);
        $this->dispatcher = static::getContainer()->get('event_dispatcher');
        $this->connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    public function test_the_end_of_a_request_drains_the_buffer(): void
    {
        $this->recordAndAssertStillBuffered();

        $this->dispatcher->dispatch($this->terminateEvent(), KernelEvents::TERMINATE);

        self::assertSame(1, $this->recordCount());
    }

    public function test_the_end_of_a_console_command_drains_the_buffer(): void
    {
        $this->recordAndAssertStillBuffered();

        $this->dispatcher->dispatch($this->consoleTerminateEvent(), ConsoleEvents::TERMINATE);

        self::assertSame(1, $this->recordCount());
    }

    public function test_a_handled_message_drains_the_buffer(): void
    {
        $this->recordAndAssertStillBuffered();

        $this->dispatcher->dispatch(new WorkerMessageHandledEvent(new Envelope(new \stdClass()), 'async'));

        self::assertSame(1, $this->recordCount());
    }

    /**
     * The buffer must drain even when the work failed: a failure is the case an
     * audit trail is read for.
     */
    public function test_a_failed_message_drains_the_buffer(): void
    {
        $this->recordAndAssertStillBuffered();

        $this->dispatcher->dispatch($this->messageFailedEvent());

        self::assertSame(1, $this->recordCount());
    }

    /**
     * A listener below the drain records into a buffer that has already been
     * emptied. -1022 is the latest a producer can be and still be served: one
     * step later ties with Messenger's services reset, which no drain priority
     * can sit both after and before.
     */
    public function test_the_drain_runs_after_the_latest_listener_that_can_still_record(): void
    {
        $cases = [
            'terminate.late' => [
                KernelEvents::TERMINATE,
                fn () => $this->dispatcher->dispatch($this->terminateEvent(), KernelEvents::TERMINATE),
            ],
            'console.late' => [
                ConsoleEvents::TERMINATE,
                fn () => $this->dispatcher->dispatch($this->consoleTerminateEvent(), ConsoleEvents::TERMINATE),
            ],
            'handled.late' => [
                WorkerMessageHandledEvent::class,
                fn () => $this->dispatcher->dispatch(new WorkerMessageHandledEvent(new Envelope(new \stdClass()), 'async')),
            ],
            'failed.late' => [
                WorkerMessageFailedEvent::class,
                fn () => $this->dispatcher->dispatch($this->messageFailedEvent()),
            ],
            'running.late' => [
                WorkerRunningEvent::class,
                fn () => $this->dispatcher->dispatch(new WorkerRunningEvent($this->worker(), false)),
            ],
            'stopped.late' => [
                WorkerStoppedEvent::class,
                fn () => $this->dispatcher->dispatch(new WorkerStoppedEvent($this->worker())),
            ],
        ];

        foreach ($cases as $operation => [$event]) {
            $this->dispatcher->addListener(
                $event,
                fn () => $this->auditor->info($operation),
                FlushAuditSinksListener::BEFORE_SERVICES_RESET + 1,
            );
        }

        // Asserted after each dispatch, not once at the end: a later event's
        // drain would otherwise cover for an earlier event's missing one.
        $recorded = [];
        foreach ($cases as $operation => [, $dispatch]) {
            $dispatch();
            $recorded[] = $operation;

            self::assertSame($recorded, $this->operations(), sprintf('"%s" was still buffered after its own event.', $operation));
        }
    }

    /**
     * The other deadline. ResetServicesListener empties the sink through the
     * services resetter, so the drain has to be strictly earlier — and equal
     * priorities resolve by registration order, which would make that an
     * accident of compilation rather than a decision.
     *
     * messenger:consume adds that listener at runtime, so it is absent from the
     * compiled dispatcher and this test has to add it the same way.
     *
     * @param class-string $event
     */
    #[DataProvider('eventsMessengerResetsServicesOn')]
    public function test_the_drain_runs_before_messenger_resets_the_services(string $event): void
    {
        $this->dispatcher->addSubscriber(new ResetServicesListener(static::getContainer()->get('services_resetter')));

        self::assertGreaterThan(
            $this->priorityOf($event, ResetServicesListener::class),
            $this->priorityOf($event, FlushAuditSinksListener::class),
            sprintf('A drain at or below the reset writes nothing on "%s": the reset empties the buffer first.', $event),
        );

        $this->recordAndAssertStillBuffered();
        $this->dispatcher->dispatch($this->workerEvent($event));

        self::assertSame(1, $this->recordCount(), sprintf('The record was reset away before "%s" drained it.', $event));
    }

    /**
     * ResetServicesListener subscribes to these two separately, so a regression
     * on one says nothing about the other.
     *
     * @return iterable<string, array{class-string}>
     */
    public static function eventsMessengerResetsServicesOn(): iterable
    {
        yield 'after each message' => [WorkerRunningEvent::class];
        yield 'at worker shutdown' => [WorkerStoppedEvent::class];
    }

    /**
     * A message a WorkerMessageReceivedEvent listener declines emits neither a
     * handled nor a failed event — Worker::handleMessage() returns before
     * either — so the per-message WorkerRunningEvent is the only drain that path
     * has. Driven through a real Worker, because the claim is about Messenger's
     * own ordering rather than about this listener.
     */
    public function test_a_declined_message_still_drains_what_was_recorded_while_receiving_it(): void
    {
        $this->dispatcher->addListener(
            WorkerMessageReceivedEvent::class,
            function (WorkerMessageReceivedEvent $event): void {
                $this->auditor->info('message.declined');
                $event->shouldHandle(false);
            },
        );
        $this->dispatcher->addListener(
            WorkerRunningEvent::class,
            static fn (WorkerRunningEvent $event) => $event->getWorker()->stop(),
        );

        $transport = new InMemoryTransport();
        $transport->send(new Envelope(new \stdClass()));

        new Worker(['async' => $transport], new MessageBus(), $this->dispatcher)->run(['sleep' => 0]);

        self::assertSame(['message.declined'], $this->operations());
    }

    private function recordAndAssertStillBuffered(): void
    {
        $this->auditor->info('document.deleted');

        self::assertSame(0, $this->recordCount(), 'Nothing may reach the table before the drain.');
    }

    private function terminateEvent(): TerminateEvent
    {
        return new TerminateEvent(
            self::$kernel ?? throw new \LogicException('The kernel is booted in setUp.'),
            new Request(),
            new Response(),
        );
    }

    private function consoleTerminateEvent(): ConsoleTerminateEvent
    {
        return new ConsoleTerminateEvent(new Command('app:whatever'), new ArrayInput([]), new NullOutput(), Command::SUCCESS);
    }

    private function messageFailedEvent(): WorkerMessageFailedEvent
    {
        return new WorkerMessageFailedEvent(
            // Stamped as already routed to `failed`, so Messenger's own
            // listeners do not re-dispatch the envelope during the test.
            new Envelope(new \stdClass(), [new SentToFailureTransportStamp('failed')]),
            'async',
            new \RuntimeException('the handler blew up'),
        );
    }

    private function worker(): Worker
    {
        return new Worker([], new MessageBus(), $this->dispatcher);
    }

    /** @param class-string $event */
    private function workerEvent(string $event): object
    {
        return match ($event) {
            WorkerRunningEvent::class => new WorkerRunningEvent($this->worker(), false),
            WorkerStoppedEvent::class => new WorkerStoppedEvent($this->worker()),
            default => throw new \LogicException(sprintf('No factory for "%s".', $event)),
        };
    }

    /**
     * The priority a listener is really registered at. Matched against what the
     * dispatcher itself lists, because getListenerPriority() answers null for a
     * callable rebuilt by hand rather than taken from getListeners().
     *
     * @param class-string $listenerClass
     */
    private function priorityOf(string $event, string $listenerClass): int
    {
        foreach ($this->dispatcher->getListeners($event) as $listener) {
            if (\is_array($listener) && $listener[0] instanceof $listenerClass) {
                return $this->dispatcher->getListenerPriority($event, $listener)
                    ?? throw new \LogicException(sprintf('"%s" was listed on "%s" but has no priority.', $listenerClass, $event));
            }
        }

        throw new \LogicException(sprintf('No "%s" listener is registered on "%s".', $listenerClass, $event));
    }

    private function recordCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM audit_log');
    }

    /**
     * @return list<string>
     */
    private function operations(): array
    {
        return $this->connection->fetchFirstColumn('SELECT operation FROM audit_log ORDER BY occurred_at, id');
    }
}
