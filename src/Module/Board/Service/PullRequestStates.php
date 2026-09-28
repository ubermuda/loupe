<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardAutomation;
use App\Module\Board\Entity\CardPullRequest;

/** The stored pull request states and the automation rows of a set of cards. */
final readonly class PullRequestStates
{
    /**
     * @param array<string, PullRequestStateView> $byPullRequest    keyed by card pull request id
     * @param array<string, CardAutomation>       $automationByCard keyed by card id
     */
    public function __construct(
        public array $byPullRequest = [],
        public array $automationByCard = [],
    ) {
    }

    public function of(CardPullRequest $link): ?PullRequestStateView
    {
        return $this->byPullRequest[(string) $link->id] ?? null;
    }

    public function automationOf(Card $card): ?CardAutomation
    {
        return $this->automationByCard[(string) $card->id] ?? null;
    }
}
