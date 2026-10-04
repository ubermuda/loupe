<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Messenger\PostFixRunComment;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\PullRequestCommentRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Bridge\Event\WorkerRunQueued;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Forge\Service\PullRequestCommenters;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The run fixes a pull request of its card: the first open one the forge
 * tracks, else the first one the card links. The forge state gives the head
 * and the reason.
 */
#[AsEventListener]
final readonly class QueueFixRunCommentOnWorkerRunQueued
{
    public function __construct(
        private BoardAvailability $board,
        private ProjectRepository $projects,
        private BoardAutomation $boardAutomation,
        private PullRequestCommenters $commenters,
        private PullRequestCommentRepository $pullRequestComments,
        private CardPullRequestRepository $cardPullRequests,
        private ForgePullRequestRepository $forgePullRequests,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerRunQueued $event): void
    {
        // The bridge report has committed, so a failure here must not fail its request.
        try {
            $this->queue($event);
        } catch (\Throwable $e) {
            $this->logger->error('board.fix_run_comment_queue_failed', [
                'projectId' => (string) $event->projectId,
                'runId' => (string) $event->runId,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    private function queue(WorkerRunQueued $event): void
    {
        if (!$this->board->isEnabled()) {
            return;
        }

        $project = $this->projects->find($event->projectId);
        if (null === $project || !$this->boardAutomation->settingsOf($project)->commentOnFixQueued) {
            return;
        }

        $keys = array_values(array_filter(
            $this->cardPullRequests->findNumberedKeysOfCard($event->projectId, $event->cardId),
            fn (array $key): bool => null !== $this->commenters->for($key['forge']),
        ));
        if ([] === $keys) {
            return;
        }
        $tracked = self::firstOpen($keys, $this->forgePullRequests->findByKeys($event->projectId, $keys));
        $forge = $tracked->forge ?? $keys[0]['forge'];
        $repository = $tracked->repository ?? $keys[0]['repository'];
        $number = $tracked->number ?? $keys[0]['number'];
        $headSha = $tracked?->headSha;
        $reason = null === $tracked ? null : self::reasonOf($tracked);

        // Forge keeps its row through a repository rename, so the post finds the pull request by id.
        $forgePullRequestId = $tracked?->id;

        // One transaction, so a message that fails to queue leaves no pending row behind.
        // It is a DBAL one, because a failed ORM transaction closes the entity manager of the bridge request.
        $commentId = $this->em->getConnection()->transactional(function () use ($event, $forge, $repository, $number, $headSha, $reason, $forgePullRequestId) {
            $commentId = $this->pullRequestComments->insertIfMissing(
                $event->projectId,
                $event->runId,
                $event->cardId,
                $forge,
                $repository,
                $number,
                $headSha,
                $reason,
                null,
                $forgePullRequestId,
                $this->clock->now(),
            );
            if (null !== $commentId) {
                $this->bus->dispatch(new PostFixRunComment($commentId));
            }

            return $commentId;
        });
        if (null === $commentId) {
            return;
        }

        $this->logger->info('board.fix_run_comment_queued', [
            'commentId' => (string) $commentId,
            'projectId' => (string) $event->projectId,
            'cardId' => (string) $event->cardId,
            'runId' => (string) $event->runId,
            'forge' => $forge,
            'repository' => $repository,
            'pullRequestNumber' => $number,
        ]);
    }

    /**
     * @param non-empty-list<array{forge: string, repository: string, number: int}> $keys
     * @param list<ForgePullRequest>                                                $rows
     */
    private static function firstOpen(array $keys, array $rows): ?ForgePullRequest
    {
        foreach ($keys as $key) {
            foreach ($rows as $row) {
                if (PullRequestState::Open === $row->state && $row->forge === $key['forge']
                    && mb_strtolower($row->repository) === mb_strtolower($key['repository']) && $row->number === $key['number']) {
                    return $row;
                }
            }
        }

        return null;
    }

    /** The first reason a fix is due, in the order the board asks for fixes. */
    private static function reasonOf(ForgePullRequest $pullRequest): ?string
    {
        return match (true) {
            PullRequestMergeability::Conflicting === $pullRequest->mergeability => 'conflict',
            PullRequestChecks::Failed === $pullRequest->checks => 'checks-failed',
            PullRequestReview::ChangesRequested === $pullRequest->review => 'changes-requested',
            default => null,
        };
    }
}
