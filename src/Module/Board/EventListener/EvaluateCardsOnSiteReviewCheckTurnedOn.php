<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\BoardAutomationSettingsSaved;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Workflow\Contract\CardEvaluations;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** No fact changes when the check turns on, so the open pull requests would carry no check until something else moved. */
#[AsEventListener]
final readonly class EvaluateCardsOnSiteReviewCheckTurnedOn
{
    public function __construct(
        private CardPullRequestRepository $cardPullRequests,
        private BoardAutomation $automation,
        private CardEvaluations $evaluations,
    ) {
    }

    public function __invoke(BoardAutomationSettingsSaved $event): void
    {
        if (!$event->siteReviewCheckTurnedOn || !$this->evaluations->isOn() || !$this->automation->settingsOf($event->project)->enabled) {
            return;
        }

        $cardIds = $this->cardPullRequests->findActiveCardIdsWithOpenGitHubPullRequest($event->project);
        if ([] !== $cardIds) {
            $this->evaluations->forCards($cardIds);
        }
    }
}
