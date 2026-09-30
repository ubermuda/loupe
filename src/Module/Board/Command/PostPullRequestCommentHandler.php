<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\PullRequestComment;
use App\Module\Board\Entity\PullRequestCommentState;
use App\Module\Board\Repository\PullRequestCommentRepository;
use App\Module\Board\Service\FixRunCommentBody;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Forge\Service\PullRequestCommenters;
use App\Module\Forge\Service\PullRequestCommentFailed;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/**
 * Posts one fix run comment. A transient failure counts the attempt and
 * rethrows, so Messenger retries it. The bus has no transaction middleware,
 * so the count persists. A permanent failure, such as a missing permission,
 * marks the row failed and returns. Its message never reaches the failure
 * transport, so nothing retries it. The next queued fix run posts a new comment.
 */
final readonly class PostPullRequestCommentHandler
{
    /** Guards against a nonsense header only. GitHub can ask for more than an hour. */
    private const int MAX_RETRY_DELAY_SECONDS = 86_400;

    /** The forge clock can run behind this one. */
    private const string LOOKUP_CLOCK_MARGIN = '-1 hour';

    public function __construct(
        private PullRequestCommentRepository $pullRequestComments,
        private ForgePullRequestRepository $forgePullRequests,
        private PullRequestCommenters $commenters,
        private FixRunCommentBody $body,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PostPullRequestCommentCommand $command): void
    {
        $comment = $this->pullRequestComments->find($command->commentId);
        if (null === $comment || PullRequestCommentState::Posted === $comment->state) {
            return;
        }

        // Only `messenger:failed:retry` of a transient failure brings a failed row back, so it tries again.
        if (PullRequestCommentState::Failed === $comment->state) {
            $comment->state = PullRequestCommentState::Pending;
            $comment->failedAt = null;
        }

        $pullRequest = $this->forgePullRequests->findByKeys(
            $comment->project->id ?? throw new \LogicException('A persisted project has an id.'),
            [['forge' => $comment->forge, 'repository' => $comment->repository, 'number' => $comment->number]],
        )[0] ?? null;
        if (null === $pullRequest) {
            $this->fail($comment, 'unknown_pull_request');

            return;
        }

        $commenter = $this->commenters->for($comment->forge);
        if (null === $commenter) {
            $this->fail($comment, 'no_commenter');

            return;
        }

        // An earlier try may have posted and then lost its flush, so a retry first looks for its marker.
        // The count is stored before the forge call, so a try that dies after the post still counts.
        $retry = $comment->attempts > 0;
        ++$comment->attempts;
        $this->em->flush();
        try {
            $found = $retry && $commenter->hasComment(
                $pullRequest,
                FixRunCommentBody::marker($comment->runId),
                $comment->createdAt->modify(self::LOOKUP_CLOCK_MARGIN),
            );
            if (!$found) {
                $commenter->comment($pullRequest, $this->body->of($comment, $pullRequest));
            }
        } catch (PullRequestCommentFailed $e) {
            if ($e->permanent) {
                $this->fail($comment, $e->cause);

                return;
            }

            $comment->cause = $e->cause;
            $this->em->flush();
            $this->logger->warning('board.fix_run_comment_retried', $this->context($comment) + ['retryAfterSeconds' => $e->retryAfterSeconds]);

            if (null === $e->retryAfterSeconds) {
                throw $e;
            }

            // forceRetry false keeps the retry budget of the transport, and only the delay changes.
            throw new RecoverableMessageHandlingException($e->getMessage(), 0, $e, retryDelay: min($e->retryAfterSeconds, self::MAX_RETRY_DELAY_SECONDS) * 1000, forceRetry: false);
        }

        $comment->state = PullRequestCommentState::Posted;
        $comment->postedAt = $this->clock->now();
        $comment->cause = null;
        $this->em->flush();
        $this->logger->info('board.fix_run_comment_posted', $this->context($comment) + ['foundOnRetry' => $found]);
    }

    private function fail(PullRequestComment $comment, string $cause): void
    {
        $comment->state = PullRequestCommentState::Failed;
        $comment->cause = $cause;
        $comment->failedAt = $this->clock->now();
        $this->em->flush();
        $this->logger->warning('board.fix_run_comment_failed', $this->context($comment));
    }

    /** @return array<string, mixed> */
    private function context(PullRequestComment $comment): array
    {
        return [
            'commentId' => (string) $comment->id,
            'projectId' => (string) $comment->project->id,
            'cardId' => (string) $comment->cardId,
            'runId' => (string) $comment->runId,
            'forge' => $comment->forge,
            'repository' => $comment->repository,
            'pullRequestNumber' => $comment->number,
            'attempts' => $comment->attempts,
            'cause' => $comment->cause,
        ];
    }
}
