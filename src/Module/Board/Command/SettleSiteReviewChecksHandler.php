<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Service\BoardAutomation;
use App\Module\Board\Service\SiteReviewCheckPublisher;
use App\Module\Project\Repository\ProjectRepository;
use Psr\Log\LoggerInterface;

/** Clears the failed site review checks of a project whose check was switched off. A switch back on before the run leaves them. */
final readonly class SettleSiteReviewChecksHandler
{
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
        if (null === $project || $this->boardAutomation->settingsOf($project)->siteReviewCheck) {
            return;
        }

        $failure = $this->checkPublisher->settle($project);
        if (null !== $failure) {
            $this->logger->warning('board.site_review_check_settle_failed', ['projectId' => $command->projectId->toRfc4122(), 'cause' => $failure]);
        }
    }
}
