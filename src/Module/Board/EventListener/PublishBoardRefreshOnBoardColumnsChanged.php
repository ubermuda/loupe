<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Board\Event\BoardColumnsChanged;
use App\Outbox\AgentPush;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * Tells every open board of the project to reload. The message carries no board
 * content, because what a board shows depends on who is looking at it.
 *
 * The publish is best effort: a board that misses it shows the change on its
 * next load, so a failure is logged and the column change stands.
 */
#[AsEventListener]
final readonly class PublishBoardRefreshOnBoardColumnsChanged
{
    /**
     * @param \Closure(): HubInterface $hub a closure, because building the hub
     *                                      reads MERCURE_JWT_SECRET, which an
     *                                      instance with no hub never sets
     */
    public function __construct(
        private ProjectTopicBuilder $topics,
        private FeatureFlagService $featureFlags,
        private LoggerInterface $logger,

        #[AutowireServiceClosure(HubInterface::class)]
        private \Closure $hub,
    ) {
    }

    public function __invoke(BoardColumnsChanged $event): void
    {
        if (!$this->featureFlags->isEnabled(AgentPush::FLAG)) {
            return;
        }

        $projectId = $event->project->id ?? throw new \LogicException('Project has no id.');

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
