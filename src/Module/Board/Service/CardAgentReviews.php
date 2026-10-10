<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;

/**
 * Reads the latest agent review of each pull request of many cards. The AgentReview module implements it.
 *
 * @phpstan-type AgentReviewFindingSummary array{path: ?string, startLine: ?int, endLine: ?int, severity: string, title: string, body: string, category: string}
 * @phpstan-type AgentReviewSummary array{reviewId: string, headSha: string, conclusion: string, summary: string, findings: list<AgentReviewFindingSummary>, createdAt: string, postedAt: ?string}
 */
interface CardAgentReviews
{
    /**
     * One read whatever the card count.
     *
     * @param list<Card> $cards
     *
     * @return array<string, AgentReviewSummary> keyed by the id of the card pull request link, with no entry for a link that has no review
     */
    public function forCards(array $cards): array;
}
