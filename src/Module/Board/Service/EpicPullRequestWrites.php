<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\EventListener\ReconcileEpicOnCardChanged;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Forge\Service\PullRequestStateWriter;
use App\Module\Forge\Service\PullRequestStateWriters;
use App\Module\Forge\Service\PullRequestWriteFailed;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/**
 * Writes the state of each pull request an epic links. A permanent failure
 * skips to the next pull request. A transient one stops the loop, and the
 * retry writes every pull request again, which a writer allows.
 */
final readonly class EpicPullRequestWrites
{
    /** Guards against a nonsense header only. GitHub can ask for more than an hour. */
    private const int MAX_RETRY_DELAY_SECONDS = 86_400;

    public function __construct(
        private CardPullRequestRepository $cardPullRequests,
        private ForgePullRequestRepository $forgePullRequests,
        private PullRequestStateWriters $writers,
        private LifecycleStages $stages,
        private LoggerInterface $logger,
    ) {
    }

    /** The draft state an epic's pull request takes in that column, or null when the column sets none. */
    public function draftFor(BoardColumn $column): ?bool
    {
        return match (true) {
            $column->terminal => null,
            $column->slug === $this->stages->forPassedChecks()['to'] => false,
            ReconcileEpicOnCardChanged::REOPEN_SLUG === $column->slug => true,
            default => null,
        };
    }

    /**
     * @param string                                                   $action a short slug for the log, such as `close`
     * @param \Closure(PullRequestStateWriter, ForgePullRequest): void $write
     */
    public function apply(Card $card, string $action, \Closure $write): void
    {
        $keys = [];
        foreach ($this->cardPullRequests->findCurrentKeys($card) as $link) {
            if (null !== $link['repository'] && null !== $link['number']) {
                $keys[] = ['forge' => $link['forge'], 'repository' => $link['repository'], 'number' => $link['number']];
            }
        }

        $projectId = $card->project->id ?? throw new \LogicException('A stored card has a project id.');
        foreach ($this->forgePullRequests->findByKeys($projectId, $keys) as $pullRequest) {
            $writer = $this->writers->for($pullRequest->forge);
            if (null === $writer) {
                continue;
            }

            $context = [
                'cardId' => (string) $card->id,
                'projectId' => (string) $projectId,
                'action' => $action,
                'forge' => $pullRequest->forge,
                'repository' => $pullRequest->repository,
                'pullRequestNumber' => $pullRequest->number,
            ];
            try {
                $write($writer, $pullRequest);
            } catch (PullRequestWriteFailed $e) {
                if ($e->permanent) {
                    $this->logger->warning('board.epic_pull_request_write_failed', $context + ['cause' => $e->cause]);

                    continue;
                }

                $this->logger->warning('board.epic_pull_request_write_retried', $context + ['cause' => $e->cause, 'retryAfterSeconds' => $e->retryAfterSeconds]);
                if (null === $e->retryAfterSeconds) {
                    throw $e;
                }

                // forceRetry false keeps the retry budget of the transport, and only the delay changes.
                throw new RecoverableMessageHandlingException($e->getMessage(), 0, $e, retryDelay: min($e->retryAfterSeconds, self::MAX_RETRY_DELAY_SECONDS) * 1000, forceRetry: false);
            }

            $this->logger->info('board.epic_pull_request_written', $context);
        }
    }
}
