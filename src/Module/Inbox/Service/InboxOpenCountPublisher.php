<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Mercure\LiveUpdates;
use App\Mercure\UserTopicBuilder;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Project\Entity\Project;
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
 * Tells the owner's open pages that the open count of a project changed, so the
 * sidebar pill reloads it. The message holds no count: two publishes can arrive
 * out of order, and a page that reads the count itself cannot go back in time.
 *
 * It publishes at terminate, so a hub that hangs never slows the change, and a
 * failure is only logged: the pill shows the right count on the next load.
 */
final class InboxOpenCountPublisher implements ResetInterface
{
    public const string TYPE = 'inbox.open_count_changed';

    /** @var array<string, array{projectId: Uuid, ownerId: Uuid, confirm: list<string>|null}> */
    private array $pending = [];

    /**
     * @param \Closure(): HubInterface $hub a closure, because building the hub
     *                                      reads MERCURE_JWT_SECRET, which an
     *                                      instance with no hub never sets
     */
    public function __construct(
        private readonly UserTopicBuilder $topics,
        private readonly InboxItemRepository $inboxItems,
        private readonly FeatureFlagService $featureFlags,
        private readonly LoggerInterface $logger,

        #[AutowireServiceClosure(HubInterface::class)]
        private readonly \Closure $hub,
    ) {
    }

    /** An item of the project opened or closed in a transaction that has committed. */
    public function countChanged(Project $project): void
    {
        $this->record($project, null);
    }

    /**
     * The items closed inside a transaction the caller does not own. The count
     * publishes only when one of them reads closed at terminate, so a rollback
     * publishes nothing.
     *
     * @param list<InboxItem> $items
     */
    public function closedBeforeCommit(array $items): void
    {
        foreach ($items as $item) {
            $this->record($item->project, (string) $item->id);
        }
    }

    #[AsEventListener(KernelEvents::TERMINATE)]
    #[AsEventListener(ConsoleEvents::TERMINATE)]
    public function publish(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        if ([] === $pending || !$this->featureFlags->isEnabled(LiveUpdates::FLAG)) {
            return;
        }

        foreach ($pending as $entry) {
            try {
                if (null !== $entry['confirm'] && [] !== $entry['confirm'] && !$this->inboxItems->anyStoredClosed($entry['confirm'])) {
                    continue;
                }

                ($this->hub)()->publish(new Update(
                    $this->topics->forInbox($entry['ownerId']),
                    json_encode(['type' => self::TYPE, 'projectId' => (string) $entry['projectId']], \JSON_THROW_ON_ERROR),
                    private: true,
                ));
            } catch (\Throwable $e) {
                $this->logger->warning('inbox.open_count_publish_failed', [
                    'projectId' => (string) $entry['projectId'],
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

    /** Scalars only: after a rollback the EntityManager is closed and cannot load the owner. */
    private function record(Project $project, ?string $closedItemId): void
    {
        $projectId = $project->id ?? throw new \LogicException('Project has no id.');
        $key = (string) $projectId;
        $entry = $this->pending[$key] ?? [
            'projectId' => $projectId,
            'ownerId' => $project->owner->id ?? throw new \LogicException('Project owner has no id.'),
            'confirm' => [],
        ];

        // A committed change needs no confirmation, whatever else the request closed.
        if (null === $closedItemId || null === $entry['confirm']) {
            $entry['confirm'] = null;
        } else {
            $entry['confirm'][] = $closedItemId;
        }

        $this->pending[$key] = $entry;
    }
}
