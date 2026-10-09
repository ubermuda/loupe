<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Service\BoardAutomation;
use App\Module\Board\Service\SiteReviewCheckPublisher;
use App\Module\Project\Repository\ProjectRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/** Clears the failed site review checks of a project whose check was switched off. A switch back on before the run leaves them. */
final readonly class SettleSiteReviewChecksHandler
{
    private const int MAX_RETRY_DELAY_SECONDS = 3600;

    public function __construct(
        private ProjectRepository $projects,
        private BoardAutomation $boardAutomation,
        private SiteReviewCheckPublisher $checkPublisher,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SettleSiteReviewChecksCommand $command): void
    {
        $project = $this->projects->find($command->projectId);
        if (null === $project || $this->boardAutomation->settingsOf($project)->keepsSiteReviewCheck()) {
            return;
        }

        $failure = $this->checkPublisher->settle($project);
        if (null === $failure) {
            return;
        }

        $this->logger->warning('board.site_review_check_settle_failed', ['projectId' => $command->projectId->toRfc4122(), 'cause' => $failure->cause, 'permanent' => $failure->permanent]);
        if ($failure->permanent) {
            return;
        }

        // A retry writes only the runs that are still failed. forceRetry false keeps the retry budget of the transport.
        throw new RecoverableMessageHandlingException($failure->getMessage(), 0, $failure, retryDelay: null === $failure->retryAfterSeconds ? null : min($failure->retryAfterSeconds, self::MAX_RETRY_DELAY_SECONDS) * 1000, forceRetry: false);
    }
}
