<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Messenger\PostPullRequestNotice;
use App\Module\Board\Repository\PullRequestNoticeRepository;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Service\PullRequestCommenters;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/** A stale approval starts no merge worker, so the board tells the pull request once per head. */
final readonly class StaleApprovalNoticeQueue
{
    public function __construct(
        private CardPullRequests $cardPullRequests,
        private PullRequestCommenters $commenters,
        private PullRequestNoticeRepository $pullRequestNotices,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /** @return list<ForgePullRequest> the open pull requests of the card whose approval misses the head and that have no notice for that head */
    public function unnoticed(Card $card): array
    {
        return array_values(array_filter($this->cardPullRequests->forCard($card), function (ForgePullRequest $pullRequest): bool {
            $head = $pullRequest->headSha;
            if (PullRequestState::Open !== $pullRequest->state || null === $head || $pullRequest->uncoveredSha !== $head || !$pullRequest->approvalIsStale()) {
                return false;
            }
            if (null === $this->commenters->for($pullRequest->forge)) {
                return false;
            }

            $projectId = $pullRequest->project->id ?? throw new \LogicException('A stored pull request has a project id.');

            return !$this->pullRequestNotices->hasKey($projectId, $pullRequest->forge, $pullRequest->repository, $pullRequest->number, StaleApprovalNoticeBody::key($head));
        }));
    }

    /** Answers whether it stored a notice. One savepoint holds the reads too, so a failed statement leaves the transaction of the caller usable. */
    public function queue(Card $card): bool
    {
        return $this->em->getConnection()->transactional(fn (): bool => $this->queueAll($card));
    }

    private function queueAll(Card $card): bool
    {
        $queued = false;
        foreach ($this->unnoticed($card) as $pullRequest) {
            $queued = $this->queuePullRequest($pullRequest) || $queued;
        }

        return $queued;
    }

    private function queuePullRequest(ForgePullRequest $pullRequest): bool
    {
        $projectId = $pullRequest->project->id ?? throw new \LogicException('A stored pull request has a project id.');
        $pullRequestId = $pullRequest->id ?? throw new \LogicException('A stored pull request has an id.');
        $key = StaleApprovalNoticeBody::key($pullRequest->headSha ?? throw new \LogicException('A stale approval has a head.'));

        // A DBAL transaction nests as a savepoint in the transaction of the evaluation.
        $noticeId = $this->em->getConnection()->transactional(function () use ($projectId, $pullRequestId, $pullRequest, $key) {
            $noticeId = $this->pullRequestNotices->insertIfMissing($projectId, $pullRequestId, $pullRequest->forge, $pullRequest->repository, $pullRequest->number, $key, $this->clock->now());
            if (null !== $noticeId) {
                $this->bus->dispatch(new PostPullRequestNotice($noticeId));
            }

            return $noticeId;
        });
        if (null === $noticeId) {
            return false;
        }

        $this->logger->info('board.pull_request_notice_queued', [
            'noticeId' => (string) $noticeId,
            'projectId' => (string) $projectId,
            'forgePullRequestId' => (string) $pullRequestId,
            'noticeKey' => $key,
        ]);

        return true;
    }
}
