<?php

declare(strict_types=1);

namespace App\Mercure;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Contracts\Service\ResetInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * Sends a private live update to the pages open on a topic. The update names
 * what changed and the origin of the change, so a page can tell its own changes.
 *
 * It publishes after the response or the message, so a hub that hangs never
 * slows a change, and a failure is only logged: the page catches up on reload.
 */
final class LiveUpdatePublisher implements ResetInterface
{
    public const string ORIGIN_HEADER = 'X-Loupe-Origin';

    private const int ORIGIN_MAX_LENGTH = 64;

    /** @var array<string, array{topic: string, data: string}> */
    private array $pending = [];

    /**
     * @param \Closure(): HubInterface $hub a closure, because building the hub
     *                                      reads MERCURE_JWT_SECRET, which an
     *                                      instance with no hub never sets
     */
    public function __construct(
        private readonly RequestStack $requests,
        private readonly FeatureFlagService $featureFlags,
        private readonly LoggerInterface $logger,

        #[AutowireServiceClosure(HubInterface::class)]
        private readonly \Closure $hub,
    ) {
    }

    /**
     * Call it after the change commits, so a rollback signals nothing.
     *
     * @param array<string, scalar|null> $payload
     */
    public function queue(string $topic, array $payload): void
    {
        if (!\array_key_exists('origin', $payload)) {
            $payload['origin'] = $this->origin();
        }

        $data = json_encode($payload, \JSON_THROW_ON_ERROR);
        $this->pending[$topic."\n".$data] = ['topic' => $topic, 'data' => $data];
    }

    /**
     * The messenger worker resets this service after each message and
     * terminates only at its time limit, so a worker publishes here.
     */
    #[AsEventListener(WorkerMessageHandledEvent::class)]
    #[AsEventListener(KernelEvents::TERMINATE)]
    #[AsEventListener(ConsoleEvents::TERMINATE)]
    public function publish(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        if ([] === $pending || !$this->featureFlags->isEnabled(LiveUpdates::FLAG)) {
            return;
        }

        foreach ($pending as $update) {
            try {
                ($this->hub)()->publish(new Update($update['topic'], $update['data'], private: true));
            } catch (\Throwable $e) {
                $this->logger->warning('live_updates.publish_failed', [
                    'topic' => $update['topic'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    #[\Override]
    public function reset(): void
    {
        $this->pending = [];
    }

    private function origin(): ?string
    {
        $header = $this->requests->getMainRequest()?->headers->get(self::ORIGIN_HEADER);
        if (null === $header || !mb_check_encoding($header, 'UTF-8')) {
            return null;
        }

        $origin = mb_substr(trim($header), 0, self::ORIGIN_MAX_LENGTH);

        return '' === $origin ? null : $origin;
    }
}
