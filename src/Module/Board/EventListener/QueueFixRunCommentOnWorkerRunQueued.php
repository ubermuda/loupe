<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Messenger\PostFixRunComment;
use App\Module\Board\Repository\CardAutomationRepository;
use App\Module\Board\Repository\PullRequestCommentRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Bridge\Event\WorkerRunQueued;
use App\Module\Forge\Service\PullRequestCommenters;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsEventListener]
final readonly class QueueFixRunCommentOnWorkerRunQueued
{
    public function __construct(
        private BoardAvailability $board,
        private ProjectRepository $projects,
        private BoardAutomation $boardAutomation,
        private PullRequestCommenters $commenters,
        private PullRequestCommentRepository $pullRequestComments,
        private CardAutomationRepository $cardAutomations,
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

        $forge = $event->forge;
        $repository = $event->repository;
        $number = $event->pullRequestNumber;
        if (null === $forge || null === $repository || null === $number || null === $this->commenters->for($forge)) {
            return;
        }

        $fixRound = $this->cardAutomations->findByCardIds([$event->cardId])[(string) $event->cardId]->fixRounds ?? null;

        // One transaction, so a message that fails to queue leaves no pending row behind.
        // It is a DBAL one, because a failed ORM transaction closes the entity manager of the bridge request.
        $commentId = $this->em->getConnection()->transactional(function () use ($event, $forge, $repository, $number, $fixRound) {
            $commentId = $this->pullRequestComments->insertIfMissing(
                $event->projectId,
                $event->runId,
                $event->cardId,
                $forge,
                $repository,
                $number,
                $event->headSha,
                $event->reason,
                $fixRound,
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
}
