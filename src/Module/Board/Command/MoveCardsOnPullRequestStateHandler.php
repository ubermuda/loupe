<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\AbandonedCardMoves;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Board\Service\CardEventCause;
use App\Module\Board\Service\LifecycleStages;
use App\Module\Board\Service\PullRequestMoveTriggers;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\PullRequestSnapshot;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Moves each card that links a pull request, as the system, when a read finds
 * its checks green or finds it merged or closed. A card whose pull requests are
 * all finished, one of them merged, goes to the first terminal column. A card
 * whose pull requests are all closed, none merged, goes to the Backlog after a
 * delay. Forge calls this inside the transaction that stores the new state, so
 * a throw here rolls that state back and the next read tries again.
 */
final readonly class MoveCardsOnPullRequestStateHandler
{
    public function __construct(
        private CardPullRequestRepository $cardPullRequests,
        private BoardColumnRepository $boardColumns,
        private BoardAutomation $boardAutomation,
        private ForgePullRequestRepository $forgePullRequests,
        private CardRepository $cards,
        private EntityManagerInterface $em,
        private LifecycleStages $stages,
        private UpdateCardHandler $updateCard,
        private AbandonedCardMoves $abandonedMoves,
    ) {
    }

    public function __invoke(MoveCardsOnPullRequestStateCommand $command): void
    {
        $previous = $command->previous;
        $current = $command->current;
        $finished = PullRequestMoveTriggers::finished($previous, $current);
        $green = PullRequestMoveTriggers::green($previous, $current);
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
        // A terminal review column would finish the card before any merge.
        $review = array_find($columns, static fn (BoardColumn $column): bool => $column->slug === $stage['to'] && !$column->terminal);

        $number = $pullRequest->number;
        $this->em->wrapInTransaction(function () use ($cards, $finished, $green, $terminal, $review, $stage, $current, $ownLinks, $project, $projectId, $number): void {
            $done = [];
            $abandoned = [];
            if ($finished) {
                // The lock UpdateCardHandler takes, held until Forge commits. A read of a
                // sibling pull request that finishes at the same time waits here, and then
                // sees that state, so one of the two reads always acts on the card.
                $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);
                [$done, $abandoned] = $this->finishedCards($projectId, $cards, $current, $ownLinks);
            }

            foreach ($cards as $card) {
                if (null !== $terminal && isset($done[(string) $card->id])) {
                    ($this->updateCard)(new UpdateCardCommand(card: $card, actor: CardReporter::System, column: $terminal, onlyFromOpenColumn: true, cause: CardEventCause::merged($number)));
                } elseif ($green && null !== $review && $card->column->slug === $stage['from'] && [] === $this->cards->openChildNumbers($card)) {
                    // Only a card with no open child moves, so an epic whose pull request is ready early waits.
                    ($this->updateCard)(new UpdateCardCommand(card: $card, actor: CardReporter::System, column: $review, onlyFromColumn: $card->column, cause: CardEventCause::checksPassed($number)));
                } elseif (isset($abandoned[(string) $card->id]) && !$card->column->backlog) {
                    $this->abandonedMoves->queue($card);
                }
            }
        });
    }

    /**
     * The cards whose pull requests are all merged or closed: first those with
     * at least one merged and no open child, then those with none merged. A
     * link Forge never read counts as open. The system skips the open-child
     * refusal, so this check stands in.
     *
     * @param list<Card>          $cards
     * @param array<string, true> $ownLinks the links to the pull request this read is about
     *
     * @return array{array<string, true>, array<string, true>} each keyed by card id
     */
    private function finishedCards(Uuid $projectId, array $cards, PullRequestSnapshot $current, array $ownLinks): array
    {
        $keys = [];
        foreach ($cards as $card) {
            foreach ($card->pullRequests as $link) {
                if (!isset($ownLinks[(string) $link->id]) && null !== $link->repository && null !== $link->number) {
                    $keys[] = ['forge' => $link->forge->value, 'repository' => $link->repository, 'number' => $link->number];
                }
            }
        }
        $states = $this->forgePullRequests->findCurrentStatesByKeys($projectId, $keys);

        $done = [];
        $abandoned = [];
        foreach ($cards as $card) {
            $merged = false;
            foreach ($card->pullRequests as $link) {
                $state = isset($ownLinks[(string) $link->id])
                    ? $current->state
                    : (null === $link->repository || null === $link->number ? null : $states[ForgePullRequestRepository::stateKey($link->forge->value, $link->repository, $link->number)] ?? null);
                if (null === $state || PullRequestState::Open === $state) {
                    continue 2;
                }
                $merged = $merged || PullRequestState::Merged === $state;
            }
            if (!$merged) {
                $abandoned[(string) $card->id] = true;
            } elseif ([] === $this->cards->openChildNumbers($card)) {
                $done[(string) $card->id] = true;
            }
        }

        return [$done, $abandoned];
    }
}
