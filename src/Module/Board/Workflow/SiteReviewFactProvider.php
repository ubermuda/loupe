<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardVerdictDeliveryRepository;
use App\Module\Board\Repository\CardVerdictRepository;
use App\Module\Board\Repository\SiteReviewCheckStateRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Workflow\Contract\FactProvider;
use Symfony\Component\Uid\Uuid;

final readonly class SiteReviewFactProvider implements FactProvider
{
    public function __construct(
        private CardRepository $cards,
        private CardVerdictRepository $cardVerdicts,
        private CardVerdictDeliveryRepository $cardVerdictDeliveries,
        private CardPullRequestRepository $cardPullRequests,
        private SiteReviewCheckStateRepository $siteReviewCheckStates,
        private BoardAutomation $boardAutomation,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return SiteReviewFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function build(Uuid $cardId): object
    {
        $card = $this->cards->find($cardId) ?? throw new \LogicException('The card of the facts exists.');

        $pullRequests = $this->cardPullRequests->findOpenGitHubForCard($card);
        $checks = [];
        if ([] !== $pullRequests) {
            $noteIds = [];
            foreach ($this->cardVerdicts->findForCard($card) as $verdict) {
                foreach ($verdict->notes as $note) {
                    $noteIds[$note['id']] = $note['id'];
                }
            }
            $noteCount = $this->cardVerdicts->countPendingNotes(array_values($noteIds));
            $wanted = 0 < $noteCount ? CheckWanted::FAILURE : CheckWanted::SUCCESS;

            foreach ($pullRequests as $pullRequest) {
                if (null === $pullRequest->headSha) {
                    continue;
                }
                $posted = $this->siteReviewCheckStates->findOneByPullRequest($pullRequest);
                $checks[(string) $pullRequest->id] = new CheckWanted(
                    $pullRequest->headSha,
                    $wanted,
                    $noteCount,
                    $posted?->headSha,
                    $posted?->conclusion,
                    $posted?->checkRunId,
                );
            }
        }

        return new SiteReviewFacts(
            $this->cardVerdictDeliveries->findPendingIdsForCard($card),
            $checks,
            $this->boardAutomation->settingsOf($card->project)->siteReviewCheck,
        );
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        if (!$facts instanceof SiteReviewFacts) {
            throw new \LogicException('The provider fingerprints its own facts.');
        }

        return [
            $facts->pendingDeliveryIds,
            array_map(static fn (CheckWanted $check): array => [$check->headSha, $check->wantedConclusion], $facts->checks),
        ];
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.board';
    }
}
