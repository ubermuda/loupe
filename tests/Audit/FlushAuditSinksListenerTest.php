<?php

declare(strict_types=1);

namespace App\Tests\Audit;

use App\Module\Audit\Auditor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
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
     * The drain has to be the last listener on each of these events: one below
     * it records into a buffer that has already been emptied. Symfony
     * Scheduler's own post-run dispatch is such a listener, and Loupe runs cron
     * tasks through the scheduler.
     */
    public function test_the_drain_runs_after_a_listener_registered_below_the_default_priority(): void
    {
        $late = [
            KernelEvents::TERMINATE => 'terminate.late',
            ConsoleEvents::TERMINATE => 'console.late',
            WorkerMessageHandledEvent::class => 'handled.late',
            WorkerMessageFailedEvent::class => 'failed.late',
        ];

        foreach ($late as $event => $operation) {
            $this->dispatcher->addListener($event, fn () => $this->auditor->info($operation), -100);
        }

        $this->dispatcher->dispatch($this->terminateEvent(), KernelEvents::TERMINATE);
        $this->dispatcher->dispatch($this->consoleTerminateEvent(), ConsoleEvents::TERMINATE);
        $this->dispatcher->dispatch(new WorkerMessageHandledEvent(new Envelope(new \stdClass()), 'async'));
        $this->dispatcher->dispatch($this->messageFailedEvent());

        self::assertSame(array_values($late), $this->operations());
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
