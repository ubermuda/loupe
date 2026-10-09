<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Service\SiteReviewCheckPublisher;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Contract\CardEvaluations;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/** Turns the failed check of a pull request that no card links any more into a neutral one. */
final readonly class NeutralizeSiteReviewCheckHandler
{
    private const int MAX_RETRY_DELAY_SECONDS = 3600;

    public function __construct(
        private ProjectRepository $projects,
        private CardPullRequestRepository $cardPullRequests,
        private SiteReviewCheckPublisher $checkPublisher,
        private CardEvaluations $evaluations,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(NeutralizeSiteReviewCheckCommand $command): void
    {
        $project = $this->projects->find($command->projectId);
        if (null === $project) {
            return;
        }

        // A card that links the pull request again owns its check, and its evaluation writes the check it wants.
        $forge = Forge::from($command->forge);
        if ([] !== $this->cardPullRequests->findForPullRequest($command->projectId, $forge, $command->repository, $command->number)) {
            return;
        }

        $failure = $this->checkPublisher->neutralizeUnlinked($project, $command->forge, $command->repository, $command->number, $command->headSha, $command->runId);
        if (null === $failure) {
            $this->evaluateCardsLinkedSince($command, $forge);

            return;
        }

        $this->logger->warning('board.site_review_check_neutralize_failed', ['projectId' => $command->projectId->toRfc4122(), 'cause' => $failure->cause, 'permanent' => $failure->permanent]);
        if ($failure->permanent) {
            return;
        }

        throw new RecoverableMessageHandlingException($failure->getMessage(), 0, $failure, retryDelay: null === $failure->retryAfterSeconds ? null : min($failure->retryAfterSeconds, self::MAX_RETRY_DELAY_SECONDS) * 1000, forceRetry: false);
    }

    /** A card can link the pull request between the first read and the write, so its check is evaluated again after the write. */
    private function evaluateCardsLinkedSince(NeutralizeSiteReviewCheckCommand $command, Forge $forge): void
    {
        $cardIds = [];
        foreach ($this->cardPullRequests->findForPullRequest($command->projectId, $forge, $command->repository, $command->number) as $link) {
            if (null !== $link->card->id) {
                $cardIds[] = $link->card->id;
            }
        }
        if ([] !== $cardIds) {
            $this->evaluations->forCards($cardIds);
        }
    }
}
