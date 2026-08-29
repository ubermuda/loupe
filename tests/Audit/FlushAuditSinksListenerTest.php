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
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;

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

        $this->dispatcher->dispatch(new TerminateEvent(
            self::$kernel ?? throw new \LogicException('The kernel is booted in setUp.'),
            new Request(),
            new Response(),
        ), KernelEvents::TERMINATE);

        self::assertSame(1, $this->recordCount());
    }

    public function test_the_end_of_a_console_command_drains_the_buffer(): void
    {
        $this->recordAndAssertStillBuffered();

        $this->dispatcher->dispatch(new ConsoleTerminateEvent(
            new Command('app:whatever'),
            new ArrayInput([]),
            new NullOutput(),
            Command::SUCCESS,
        ), ConsoleEvents::TERMINATE);

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

        $this->dispatcher->dispatch(new WorkerMessageFailedEvent(
            // Stamped as already routed to `failed`, so Messenger's own
            // listeners do not re-dispatch the envelope during the test.
            new Envelope(new \stdClass(), [new SentToFailureTransportStamp('failed')]),
            'async',
            new \RuntimeException('the handler blew up'),
        ));

        self::assertSame(1, $this->recordCount());
    }

    private function recordAndAssertStillBuffered(): void
    {
        $this->auditor->info('document.deleted');

        self::assertSame(0, $this->recordCount(), 'Nothing may reach the table before the drain.');
    }

    private function recordCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM audit_log');
    }
}
