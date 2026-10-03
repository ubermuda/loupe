<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardAutomation;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestState;

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

    /**
     * An open pull request counts only once it was read, like the card page that shows it as not reported before.
     * A finished card gets no automation, so its block shows nothing.
     *
     * @return list<CardBadge>
     */
    public function badgesOf(Card $card): array
    {
        $found = [];
        foreach ($card->pullRequests as $link) {
            $state = $this->of($link);
            if (null === $state || null === $state->refreshedAt || PullRequestState::Open !== $state->state) {
                continue;
            }
            if (PullRequestChecks::Failed === $state->checks) {
                $found[CardBadge::ChecksFailed->value] = true;
            }
            if (PullRequestMergeability::Conflicting === $state->mergeability) {
                $found[CardBadge::Conflict->value] = true;
            }
        }

        return array_values(array_filter(CardBadge::cases(), static fn (CardBadge $badge): bool => isset($found[$badge->value])));
    }
}
