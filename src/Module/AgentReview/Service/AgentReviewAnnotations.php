<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Service;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\AgentReview\Entity\AgentReviewSeverity;
use App\Module\Forge\Service\PullRequestCheckAnnotation;
use App\Module\Forge\Service\PullRequestCheckAnnotationLevel;

/** How the findings of a review show as line notes on its check. A note needs a message, so an empty body repeats the title. */
final readonly class AgentReviewAnnotations
{
    /** @return list<PullRequestCheckAnnotation> */
    public function of(AgentReview $review): array
    {
        return array_map(static fn (AgentReviewFinding $finding): PullRequestCheckAnnotation => new PullRequestCheckAnnotation(
            $finding->path,
            $finding->startLine,
            $finding->endLine,
            match ($finding->severity) {
                AgentReviewSeverity::Important => PullRequestCheckAnnotationLevel::Failure,
                AgentReviewSeverity::Nit => PullRequestCheckAnnotationLevel::Warning,
                AgentReviewSeverity::PreExisting => PullRequestCheckAnnotationLevel::Notice,
            },
            $finding->title,
            '' === $finding->body ? $finding->title : $finding->body,
        ), $review->findings());
    }
}
