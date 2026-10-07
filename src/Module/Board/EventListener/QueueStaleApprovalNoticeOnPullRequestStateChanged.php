<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Messenger\PostPullRequestNotice;
use App\Module\Board\Repository\PullRequestNoticeRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Board\Service\StaleApprovalNoticeBody;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Event\PullRequestStateChanged;
use App\Module\Forge\Service\PullRequestCommenters;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/** A stale approval starts no merge worker, so the board tells the pull request once per head. */
#[AsEventListener]
final readonly class QueueStaleApprovalNoticeOnPullRequestStateChanged
{
    public function __construct(
        private BoardAutomation $boardAutomation,
        private PullRequestCommenters $commenters,
        private PullRequestNoticeRepository $pullRequestNotices,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PullRequestStateChanged $event): void
    {
        // A failure here must not roll back the state Forge stores.
        try {
            $this->queue($event);
        } catch (\Throwable $e) {
            $this->logger->error('board.pull_request_notice_queue_failed', [
                'forgePullRequestId' => (string) $event->pullRequest->id,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    private function queue(PullRequestStateChanged $event): void
    {
        $pullRequest = $event->pullRequest;
        $settings = $this->boardAutomation->settingsOf($pullRequest->project);
        if (!$settings->enabled || !$settings->commentOnStaleApproval) {
            return;
        }

        $head = $pullRequest->headSha;
        if (PullRequestState::Open !== $pullRequest->state || null === $head || $pullRequest->uncoveredSha !== $head || !$pullRequest->approvalIsStale()) {
            return;
        }
        if (null === $this->commenters->for($pullRequest->forge)) {
            return;
        }

        $projectId = $pullRequest->project->id ?? throw new \LogicException('A stored pull request has a project id.');
        $pullRequestId = $pullRequest->id ?? throw new \LogicException('A stored pull request has an id.');
        $key = StaleApprovalNoticeBody::key($head);

        // A DBAL transaction nests as a savepoint in the Forge one, so a failed insert leaves that one usable.
        $noticeId = $this->em->getConnection()->transactional(function () use ($projectId, $pullRequestId, $pullRequest, $key) {
            $noticeId = $this->pullRequestNotices->insertIfMissing($projectId, $pullRequestId, $pullRequest->forge, $pullRequest->repository, $pullRequest->number, $key, $this->clock->now());
            if (null !== $noticeId) {
                $this->bus->dispatch(new PostPullRequestNotice($noticeId));
            }

            return $noticeId;
        });
        if (null === $noticeId) {
            return;
        }

        $this->logger->info('board.pull_request_notice_queued', [
            'noticeId' => (string) $noticeId,
            'projectId' => (string) $projectId,
            'forgePullRequestId' => (string) $pullRequestId,
            'noticeKey' => $key,
        ]);
    }
}
