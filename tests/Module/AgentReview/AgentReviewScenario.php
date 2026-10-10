<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\AgentReview\Entity\AgentReviewSeverity;
use App\Module\Board\Entity\Card;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;

/** Persists and does not flush, except makeProject(), so a test decides when the rows commit. */
trait AgentReviewScenario
{
    use BoardToolScenario;

    private function card(Project $project, int $number = 1): Card
    {
        $card = new Card($project, $this->column($project, 'backlog'), 'Ship it', '', $number);
        $this->em->persist($card);

        return $card;
    }

    private function pullRequest(Project $project, int $number = 7): ForgePullRequest
    {
        $pullRequest = new ForgePullRequest($project, 'github', 'acme/widgets', $number);
        $pullRequest->headSha = str_repeat('a', 40);
        $this->em->persist($pullRequest);

        return $pullRequest;
    }

    private function review(Card $card, ForgePullRequest $pullRequest, ?\DateTimeImmutable $postedAt = null): AgentReview
    {
        $review = new AgentReview(
            project: $card->project,
            card: $card,
            pullRequest: $pullRequest,
            headSha: $pullRequest->headSha ?? str_repeat('b', 40),
            summary: 'One important finding.',
            conclusion: AgentReviewConclusion::Failure,
            findings: [new AgentReviewFinding('src/Foo.php', 3, 5, AgentReviewSeverity::Important, 'Null read', 'The value can be null here.')],
        );
        $review->postedAt = $postedAt;
        $this->em->persist($review);

        return $review;
    }
}
