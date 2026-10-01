<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardAutomationAction;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\BoardAutomationSettingsRepository;
use App\Module\Board\Repository\CardAutomationRepository;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Board\Service\SyncLine;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Forge\Service\PullRequestBranchUpdaters;
use App\Module\Forge\Service\PullRequestSyncFailed;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

/**
 * Asks the forge to update the branch of the next approved pull request that
 * is behind its base. The lock on the settings row lets one pass per project
 * pick at a time. The forge call runs outside any transaction, so a slow forge
 * holds no lock.
 */
final readonly class SyncNextPullRequestHandler
{
    /** Guards against a nonsense header only. GitHub can ask for more than an hour. */
    private const int MAX_RETRY_DELAY_SECONDS = 86_400;

    public function __construct(
        private ProjectRepository $projects,
        private BoardAvailability $board,
        private BoardAutomationSettingsRepository $boardAutomationSettings,
        private ForgePullRequestRepository $forgePullRequests,
        private PullRequestBranchUpdaters $updaters,
        private CardPullRequestRepository $cardPullRequests,
        private CardAutomationRepository $cardAutomations,
        private CardEventRepository $cardEvents,
        private EntityManagerInterface $em,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncNextPullRequestCommand $command): void
    {
        if (!$this->board->isEnabled()) {
            return;
        }
        $project = $this->projects->find($command->projectId);
        if (null === $project) {
            return;
        }

        $pullRequest = $this->em->wrapInTransaction(fn (): ?ForgePullRequest => $this->pick($project, $command->projectId));
        if (null === $pullRequest) {
            return;
        }
        $id = $pullRequest->id ?? throw new \LogicException('A stored pull request has an id.');
        $sha = $pullRequest->syncFromSha ?? throw new \LogicException('The pick carries its marker.');

        $updater = $this->updaters->for($pullRequest->forge);
        if (null === $updater) {
            $this->fail($id, $sha, 'no_updater');

            return;
        }

        try {
            $updater->update($pullRequest, $sha);
        } catch (PullRequestSyncFailed $e) {
            if ($e->permanent) {
                $this->fail($id, $sha, $e->cause);

                return;
            }

            $this->em->wrapInTransaction(function () use ($id, $sha): void {
                $row = $this->forgePullRequests->findForUpdate($id);
                if (null !== $row && $row->syncFromSha === $sha) {
                    $row->syncFromSha = null;
                    $row->syncRequestedAt = null;
                }
            });
            $this->logger->warning('board.pull_request_sync_retried', ['pullRequestId' => (string) $id, 'cause' => $e->cause, 'retryAfterSeconds' => $e->retryAfterSeconds]);

            if (null === $e->retryAfterSeconds) {
                throw $e;
            }

            // forceRetry false keeps the retry budget of the transport, and only the delay changes.
            throw new RecoverableMessageHandlingException($e->getMessage(), 0, $e, retryDelay: min($e->retryAfterSeconds, self::MAX_RETRY_DELAY_SECONDS) * 1000, forceRetry: false);
        }

        $this->recordOnCards($pullRequest, $command->projectId);
        $this->logger->info('board.pull_request_synced', [
            'pullRequestId' => (string) $id,
            'projectId' => (string) $command->projectId,
            'forge' => $pullRequest->forge,
            'repository' => $pullRequest->repository,
            'pullRequestNumber' => $pullRequest->number,
            'fromSha' => $sha,
        ]);
    }

    private function pick(Project $project, Uuid $projectId): ?ForgePullRequest
    {
        $settings = $this->boardAutomationSettings->findOneByProjectForUpdate($project);
        if (null === $settings || !$settings->enabled || !$settings->syncBehind) {
            return null;
        }

        $now = $this->clock->now();
        $line = new SyncLine($this->forgePullRequests->findOpenForProject($projectId), $now);

        // Each row is locked and read again, because a forge read may have moved its head since the line was read.
        foreach ($line->timedOut as $row) {
            $marker = $row->syncFromSha;
            $locked = $this->forgePullRequests->findForUpdate($row->id ?? throw new \LogicException('A stored pull request has an id.'));
            if (null !== $locked && null !== $marker && $locked->syncFromSha === $marker) {
                $locked->syncFailedReason = SyncLine::TIMEOUT;
                $locked->syncFromSha = null;
                $locked->syncRequestedAt = null;
                $this->em->flush();
                $this->logger->warning('board.pull_request_sync_timed_out', ['pullRequestId' => (string) $locked->id, 'fromSha' => $marker]);
            }
        }

        $next = $line->next;
        if (null === $next) {
            return null;
        }
        $sha = $next->headSha;
        $locked = $this->forgePullRequests->findForUpdate($next->id ?? throw new \LogicException('A stored pull request has an id.'));
        if (null === $locked || null === $sha || $locked->headSha !== $sha || PullRequestState::Open !== $locked->state
            || PullRequestMergeability::Behind !== $locked->mergeability || null !== $locked->syncFromSha || null !== $locked->syncFailedReason) {
            return null;
        }

        $locked->syncFromSha = $sha;
        $locked->syncRequestedAt = $now;
        $this->em->flush();

        return $locked;
    }

    /** Records the cause only while the head is the one Loupe asked to update, because a later head clears it anyway. */
    private function fail(Uuid $id, string $sha, string $cause): void
    {
        $recorded = $this->em->wrapInTransaction(function () use ($id, $sha, $cause): bool {
            $row = $this->forgePullRequests->findForUpdate($id);
            if (null === $row || $row->headSha !== $sha) {
                return false;
            }
            $row->syncFailedReason = $cause;
            $row->syncFromSha = null;
            $row->syncRequestedAt = null;

            return true;
        });
        $this->logger->warning('board.pull_request_sync_failed', ['pullRequestId' => (string) $id, 'fromSha' => $sha, 'cause' => $cause, 'recorded' => $recorded]);
    }

    private function recordOnCards(ForgePullRequest $pullRequest, Uuid $projectId): void
    {
        $forge = Forge::tryFrom($pullRequest->forge);
        if (null === $forge) {
            return;
        }

        /** @var array<string, Card> $cards */
        $cards = [];
        foreach ($this->cardPullRequests->findForPullRequest($projectId, $forge, $pullRequest->repository, $pullRequest->number) as $link) {
            if (!$link->card->column->terminal) {
                $cards[(string) $link->card->id] ??= $link->card;
            }
        }
        if ([] === $cards) {
            return;
        }

        $now = $this->clock->now();
        $this->em->wrapInTransaction(function () use ($cards, $pullRequest, $now): void {
            foreach ($cards as $card) {
                $automation = $this->cardAutomations->findOrCreateForUpdate($card);
                $automation->lastAction = CardAutomationAction::Synced;
                $automation->lastActionAt = $now;
                $this->cardEvents->record($card, CardEventKind::Synced, CardReporter::System, null, ['pullRequest' => $pullRequest->number], $now);
                $this->em->flush();
            }
        });

        foreach ($cards as $card) {
            $this->events->dispatch(new CardChanged(
                $projectId,
                $card->id ?? throw new \LogicException('A linked card has an id.'),
                CardChanged::UPDATED,
                false,
            ));
        }
    }
}
