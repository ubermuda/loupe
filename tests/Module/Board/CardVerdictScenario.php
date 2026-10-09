<?php

declare(strict_types=1);

namespace App\Tests\Module\Board;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Entity\Forge;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;

/**
 * Cards, linked pull requests and notes for the widget verdict tests.
 *
 * Requires an `$em` EntityManagerInterface property and BoardColumnFixtures on
 * the using class.
 */
trait CardVerdictScenario
{
    private function card(Project $project, string $column = 'in-progress', int $number = 1): Card
    {
        $card = new Card($project, $this->column($project, $column), 'Footer overlaps the launcher', '', $number);
        $this->em->persist($card);

        return $card;
    }

    /** A pull request that the card links and the last forge read found in that state. */
    private function linkedPullRequest(Card $card, int $number, PullRequestState $state = PullRequestState::Open, string $forge = 'github', ?string $authorId = null, bool $authorRead = true): ForgePullRequest
    {
        $this->em->persist(new CardPullRequest($card, 'https://github.com/Acme/Widgets/pull/'.$number, Forge::GitHub, 'Acme/Widgets', $number));
        $row = new ForgePullRequest($card->project, $forge, 'acme/widgets', $number);
        $row->state = $state;
        $row->authorId = $authorId;
        $row->authorRead = $authorRead;
        $this->em->persist($row);

        return $row;
    }

    private function note(Card $card, string $body, SiteReviewCommentStatus $status = SiteReviewCommentStatus::Pending, int $position = 0): SiteReviewComment
    {
        $comment = new SiteReviewComment($card->project, $position, $body, 'https://app.example/page');
        $comment->addAnchor('.hero', 'Hero');
        $comment->status = $status;
        $this->em->persist($comment);
        $this->em->persist(new CardSiteReviewComment($card, $comment));

        return $comment;
    }
}
