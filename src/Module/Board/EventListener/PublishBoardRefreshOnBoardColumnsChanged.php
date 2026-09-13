<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Board\Event\BoardColumnsChanged;
use App\Outbox\AgentPush;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * Tells every open board of a project to reload. The message carries no board
 * content, because what a board shows depends on who is looking at it.
 *
 * It publishes at terminate, after the response is sent, so a hub that hangs
 * never slows a column change. The publish is best effort: a board that misses
 * it shows the change on its next load, so a failure is only logged.
 */
final class PublishBoardRefreshOnBoardColumnsChanged implements ResetInterface
{
    /** @var array<string, Uuid> */
    private array $pending = [];

    /**
     * @param \Closure(): HubInterface $hub a closure, because building the hub
     *                                      reads MERCURE_JWT_SECRET, which an
     *                                      instance with no hub never sets
     */
    public function __construct(
        private readonly ProjectTopicBuilder $topics,
        private readonly FeatureFlagService $featureFlags,
        private readonly LoggerInterface $logger,

        #[AutowireServiceClosure(HubInterface::class)]
        private readonly \Closure $hub,
    ) {
    }

    #[AsEventListener]
    public function __invoke(BoardColumnsChanged $event): void
    {
        $projectId = $event->project->id ?? throw new \LogicException('Project has no id.');
        $this->pending[(string) $projectId] = $projectId;
    }

    #[AsEventListener(KernelEvents::TERMINATE)]
    #[AsEventListener(ConsoleEvents::TERMINATE)]
    public function publish(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        if ([] === $pending || !$this->featureFlags->isEnabled(AgentPush::FLAG)) {
            return;
        }

        foreach ($pending as $projectId) {
            try {
                ($this->hub)()->publish(new Update(
                    $this->topics->forBoard($projectId),
                    json_encode(['type' => 'board.columns_changed'], \JSON_THROW_ON_ERROR),
                    private: true,
                ));
            } catch (\Throwable $e) {
                $this->logger->warning('board.refresh_publish_failed', [
                    'projectId' => (string) $projectId,
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
}
