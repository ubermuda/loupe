<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\PullRequestCommentState;
use App\Module\Board\Entity\PullRequestNotice;
use App\Module\Board\Repository\PullRequestNoticeRepository;
use App\Module\Board\Service\StaleApprovalNoticeBody;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Forge\Service\PullRequestCommenters;
use App\Module\Forge\Service\PullRequestCommentFailed;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/**
 * Posts one pull request notice. A transient failure counts the attempt and
 * rethrows, so Messenger retries it. A permanent failure marks the row failed
 * and returns, so nothing retries it.
 */
final readonly class DeliverPullRequestNoticeHandler
{
    /** Guards against a nonsense header only. GitHub can ask for more than an hour. */
    private const int MAX_RETRY_DELAY_SECONDS = 86_400;

    /** The forge clock can run behind this one. */
    private const string LOOKUP_CLOCK_MARGIN = '-1 hour';

    private const string LOOKUP_INCOMPLETE = 'lookup_incomplete';

    public function __construct(
        private PullRequestNoticeRepository $pullRequestNotices,
        private ForgePullRequestRepository $forgePullRequests,
        private PullRequestCommenters $commenters,
        private StaleApprovalNoticeBody $body,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DeliverPullRequestNoticeCommand $command): void
    {
        $notice = $this->pullRequestNotices->find($command->noticeId);
        if (null === $notice || PullRequestCommentState::Posted === $notice->state) {
            return;
        }

        // Only `messenger:failed:retry` of a transient failure brings a failed row back, so it tries again.
        if (PullRequestCommentState::Failed === $notice->state) {
            $notice->state = PullRequestCommentState::Pending;
            $notice->failedAt = null;
        }

        $pullRequest = $this->forgePullRequests->find($notice->forgePullRequestId);
        if (null === $pullRequest) {
            $this->fail($notice, 'unknown_pull_request');

            return;
        }

        $commenter = $this->commenters->for($pullRequest->forge);
        if (null === $commenter) {
            $this->fail($notice, 'no_commenter');

            return;
        }

        // A lost flush, or a pull request unlinked and linked again under a new row, can leave the comment already posted.
        // The count is stored before the forge call, so a try that dies after the post still counts.
        $firstTry = 0 === $notice->attempts;
        ++$notice->attempts;
        $this->em->flush();
        $since = min($pullRequest->approvedAt ?? $notice->createdAt, $notice->createdAt);
        try {
            try {
                $found = $commenter->hasComment(
                    $pullRequest,
                    StaleApprovalNoticeBody::marker($notice->noticeKey),
                    $since->modify(self::LOOKUP_CLOCK_MARGIN),
                );
            } catch (PullRequestCommentFailed $e) {
                // A busy pull request outgrows the search. Only a retry risks its own duplicate, so a first try still posts.
                if (!$firstTry || self::LOOKUP_INCOMPLETE !== $e->cause) {
                    throw $e;
                }
                $found = false;
            }
            // After the lookup, so a retry of a notice that did post is not marked outdated.
            if (!$found && !StaleApprovalNoticeBody::stillHolds($notice, $pullRequest)) {
                $this->fail($notice, 'outdated');

                return;
            }
            if (!$found) {
                $commenter->comment($pullRequest, $this->body->of($notice));
            }
        } catch (PullRequestCommentFailed $e) {
            if ($e->permanent) {
                $this->fail($notice, $e->cause);

                return;
            }

            $notice->cause = $e->cause;
            $this->em->flush();
            $this->logger->warning('board.pull_request_notice_retried', $this->context($notice) + ['retryAfterSeconds' => $e->retryAfterSeconds]);

            if (null === $e->retryAfterSeconds) {
                throw $e;
            }

            // forceRetry false keeps the retry budget of the transport, and only the delay changes.
            throw new RecoverableMessageHandlingException($e->getMessage(), 0, $e, retryDelay: min($e->retryAfterSeconds, self::MAX_RETRY_DELAY_SECONDS) * 1000, forceRetry: false);
        }

        $notice->state = PullRequestCommentState::Posted;
        $notice->postedAt = $this->clock->now();
        $notice->cause = null;
        $this->em->flush();
        $this->logger->info('board.pull_request_notice_posted', $this->context($notice) + ['foundOnRetry' => $found]);
    }

    private function fail(PullRequestNotice $notice, string $cause): void
    {
        $notice->state = PullRequestCommentState::Failed;
        $notice->cause = $cause;
        $notice->failedAt = $this->clock->now();
        $this->em->flush();
        $this->logger->warning('board.pull_request_notice_failed', $this->context($notice));
    }

    /** @return array<string, mixed> */
    private function context(PullRequestNotice $notice): array
    {
        return [
            'noticeId' => (string) $notice->id,
            'projectId' => (string) $notice->project->id,
            'forgePullRequestId' => (string) $notice->forgePullRequestId,
            'noticeKey' => $notice->noticeKey,
            'attempts' => $notice->attempts,
            'cause' => $notice->cause,
        ];
    }
}
