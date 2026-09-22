<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Outbox\Command\DrainOutboxCommand;
use App\Outbox\Command\DrainOutboxHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Drains the outbox at terminate, so an event reaches a bridge in the time a
 * request takes rather than in the time the cron takes.
 *
 * It runs after the response, so it is after the producer's transaction
 * committed. A publish inside that transaction would push an event for a write
 * that then rolls back.
 *
 * It reuses DrainOutboxHandler rather than publishing on its own, so the claim,
 * the lease, the push flag and the failure backoff have one implementation.
 * DrainOutboxTask stays the retry for a row this pass missed.
 */
final class ImmediateOutboxPublisher implements ResetInterface
{
    /**
     * How many rows one request publishes before it leaves the rest to the
     * cron. Each row is an HTTP call to the hub, and this runs in the request's
     * own process, so an unbounded drain lets one request publish a whole
     * backlog while a worker waits on it. A request writes one or two rows, so
     * this covers its own work with headroom and nothing more.
     */
    private const int LIMIT = 8;

    private bool $pending = false;

    /**
     * @param \Closure(): DrainOutboxHandler $drainOutbox a closure, because the
     *                                                    handler builds the hub,
     *                                                    which reads
     *                                                    MERCURE_JWT_SECRET
     */
    public function __construct(
        private readonly LoggerInterface $logger,

        #[AutowireServiceClosure(DrainOutboxHandler::class)]
        private readonly \Closure $drainOutbox,
    ) {
    }

    /** An outbox row was written, so this request has something to publish. */
    public function rowWritten(): void
    {
        $this->pending = true;
    }

    #[AsEventListener(KernelEvents::TERMINATE)]
    #[AsEventListener(ConsoleEvents::TERMINATE)]
    public function publish(): void
    {
        if (!$this->pending) {
            return;
        }
        $this->pending = false;

        try {
            ($this->drainOutbox)()(new DrainOutboxCommand(self::LIMIT));
        } catch (\Throwable $e) {
            $this->logger->warning('outbox.immediate_publish_failed', ['error' => $e->getMessage()]);
        }
    }

    #[\Override]
    public function reset(): void
    {
        $this->pending = false;
    }
}
