<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Service\SiteReviewCheckPublisher;
use App\Module\Project\Repository\ProjectRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/** Turns the failed check of a pull request that no card links any more into a neutral one. */
final readonly class NeutralizeSiteReviewCheckHandler
{
    private const int MAX_RETRY_DELAY_SECONDS = 3600;

    public function __construct(
        private ProjectRepository $projects,
        private SiteReviewCheckPublisher $checkPublisher,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(NeutralizeSiteReviewCheckCommand $command): void
    {
        $project = $this->projects->find($command->projectId);
        if (null === $project) {
            return;
        }

        $failure = $this->checkPublisher->neutralizeUnlinked($project, $command->forge, $command->repository, $command->number, $command->headSha, $command->runId);
        if (null === $failure) {
            return;
        }

        $this->logger->warning('board.site_review_check_neutralize_failed', ['projectId' => $command->projectId->toRfc4122(), 'cause' => $failure->cause, 'permanent' => $failure->permanent]);
        if ($failure->permanent) {
            return;
        }

        throw new RecoverableMessageHandlingException($failure->getMessage(), 0, $failure, retryDelay: null === $failure->retryAfterSeconds ? null : min($failure->retryAfterSeconds, self::MAX_RETRY_DELAY_SECONDS) * 1000, forceRetry: false);
    }
}
