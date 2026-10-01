<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardAutomationRepository;
use App\Module\Forge\Repository\ForgePullRequestRepository;

/**
 * Reads the stored pull request states and the automation rows of many cards
 * of one project, in one query for each whatever the card count.
 */
readonly class CardPullRequestStates
{
    public function __construct(
        private ForgePullRequestRepository $forgePullRequests,
        private CardAutomationRepository $cardAutomations,
    ) {
    }

    /**
     * @param list<Card> $cards    all of one project
     * @param ?SyncLine  $syncLine the sync line of that project, to add a sync status to each state
     */
    public function forCards(array $cards, ?SyncLine $syncLine = null): PullRequestStates
    {
        if ([] === $cards) {
            return new PullRequestStates();
        }

        $projectId = $cards[0]->project->id ?? throw new \LogicException('A card project is persisted.');
        $keys = [];
        $linksByKey = [];
        $cardIds = [];
        foreach ($cards as $card) {
            if (!$projectId->equals($card->project->id)) {
                throw new \LogicException('Every card belongs to the project.');
            }
            $cardIds[] = $card->id ?? throw new \LogicException('A card is persisted.');
            foreach ($card->pullRequests as $link) {
                if (null === $link->repository || null === $link->number) {
                    continue;
                }
                $key = self::key($link->forge->value, $link->repository, $link->number);
                $keys[$key] = ['forge' => $link->forge->value, 'repository' => $link->repository, 'number' => $link->number];
                $linksByKey[$key][] = $link;
            }
        }

        $byPullRequest = [];
        foreach ($this->forgePullRequests->findByKeys($projectId, array_values($keys)) as $row) {
            $view = PullRequestStateView::of($row, $syncLine?->statusOf($row));
            foreach ($linksByKey[self::key($row->forge, $row->repository, $row->number)] ?? [] as $link) {
                $byPullRequest[(string) $link->id] = $view;
            }
        }

        return new PullRequestStates($byPullRequest, $this->cardAutomations->findByCardIds($cardIds));
    }

    private static function key(string $forge, string $repository, int $number): string
    {
        return $forge.' '.mb_strtolower($repository).'#'.$number;
    }
}
