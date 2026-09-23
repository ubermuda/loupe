<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Mercure\LiveUpdates;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Project\Entity\Project;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * Tells the open worker run pages of a project to reload. The message carries
 * no run, because what a page shows depends on who is looking at it.
 *
 * It publishes after the response or the message, so a hub that hangs never
 * slows a report, and a failure is only logged: the page shows the change on
 * its next load.
 */
final class WorkerRunChangedPublisher implements ResetInterface
{
    public const string TYPE = 'worker_run.changed';

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

    /** Call it after the change commits, so a rollback signals nothing. */
    public function runsChanged(Project $project): void
    {
        $projectId = $project->id ?? throw new \LogicException('Project has no id.');
        $this->pending[(string) $projectId] = $projectId;
    }

    /**
     * The messenger worker resets this service after each message and
     * terminates only at its time limit, so the scheduled sweep publishes here.
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

        foreach ($pending as $projectId) {
            try {
                ($this->hub)()->publish(new Update(
                    $this->topics->forWorkerRuns($projectId),
                    json_encode(['type' => self::TYPE], \JSON_THROW_ON_ERROR),
                    private: true,
                ));
            } catch (\Throwable $e) {
                $this->logger->warning('bridge.worker_run_publish_failed', [
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
