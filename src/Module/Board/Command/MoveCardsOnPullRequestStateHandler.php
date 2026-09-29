<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Board\Service\CardPullRequestStates;
use App\Module\Board\Service\LifecycleStages;
use App\Module\Board\Service\PullRequestStates;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\PullRequestSnapshot;

/**
 * Moves each card that links a pull request, as the system, when a read finds
 * its checks green or finds it merged or closed. A card whose pull requests are
 * all finished, one of them merged, goes to the first terminal column. Forge
 * calls this inside the transaction that stores the new state, so a throw here
 * rolls that state back and the next read tries again.
 */
final readonly class MoveCardsOnPullRequestStateHandler
{
    public function __construct(
        private CardPullRequestRepository $cardPullRequests,
        private BoardColumnRepository $boardColumns,
        private BoardAutomation $boardAutomation,
        private CardPullRequestStates $cardPullRequestStates,
        private LifecycleStages $stages,
        private UpdateCardHandler $updateCard,
    ) {
    }

    public function __invoke(MoveCardsOnPullRequestStateCommand $command): void
    {
        $previous = $command->previous;
        $current = $command->current;
        $finished = $previous->state !== $current->state && PullRequestState::Open !== $current->state;
        $green = PullRequestChecks::Passed === $current->checks
            && $current->checksConcludedSince($previous)
            && PullRequestState::Open === $current->state
            && !$current->draft;
        $pullRequest = $command->pullRequest;
        $forge = Forge::tryFrom($pullRequest->forge);
        if (null === $forge || (!$finished && !$green)) {
            return;
        }

        $project = $pullRequest->project;
        if (!$this->boardAutomation->settingsOf($project)->enabled) {
            return;
        }

        $projectId = $project->id ?? throw new \LogicException('A stored pull request has a project id.');
        $cards = [];
        $ownLinks = [];
        foreach ($this->cardPullRequests->findForPullRequest($projectId, $forge, $pullRequest->repository, $pullRequest->number) as $link) {
            $cards[(string) $link->card->id] ??= $link->card;
            $ownLinks[(string) $link->id] = true;
        }
        $cards = array_values(array_filter($cards, static fn (Card $card): bool => !$card->column->terminal));
        if ([] === $cards) {
            return;
        }

        $columns = $this->boardColumns->findForProject($project);
        $terminal = array_find($columns, static fn (BoardColumn $column): bool => $column->terminal);
        $stage = $this->stages->forPassedChecks();
        $review = array_find($columns, static fn (BoardColumn $column): bool => $column->slug === $stage['to']);
        $states = $finished ? $this->cardPullRequestStates->forCards($cards) : null;

        foreach ($cards as $card) {
            $target = null;
            if (null !== $states && null !== $terminal && $this->allFinishedOneMerged($card, $current, $ownLinks, $states)) {
                $target = $terminal;
            } elseif ($green && null !== $review && $card->column->slug === $stage['from']) {
                $target = $review;
            }
            if (null !== $target) {
                ($this->updateCard)(new UpdateCardCommand(card: $card, actor: CardReporter::System, column: $target));
            }
        }
    }

    /**
     * A link Forge never read counts as open, so it holds the card back.
     *
     * @param array<string, true> $ownLinks the links to the pull request this read is about
     */
    private function allFinishedOneMerged(Card $card, PullRequestSnapshot $current, array $ownLinks, PullRequestStates $states): bool
    {
        $merged = false;
        foreach ($card->pullRequests as $link) {
            $state = isset($ownLinks[(string) $link->id]) ? $current->state : $states->of($link)?->state;
            if (null === $state || PullRequestState::Open === $state) {
                return false;
            }
            $merged = $merged || PullRequestState::Merged === $state;
        }

        return $merged;
    }
}
