<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Twig;

use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes a project's site-review comment counts to the app-shell nav pill: the
 * submitted total it displays, and the open count that tints it. Lives in
 * SiteReview because both are SiteReview concerns and the SiteReview → Project
 * direction is the allowed one — keeping it here avoids a Project ↔ SiteReview
 * module cycle.
 */
final class SiteReviewNavExtension extends AbstractExtension
{
    public function __construct(
        private readonly SiteReviewCommentRepository $siteReviewComments,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('project_open_review_count', $this->openReviewCount(...)),
            new TwigFunction('project_submitted_review_count', $this->submittedReviewCount(...)),
        ];
    }

    public function openReviewCount(Project $project): int
    {
        return $this->siteReviewComments->countOpenForProject($project);
    }

    public function submittedReviewCount(Project $project): int
    {
        return $this->siteReviewComments->countSubmittedForProject($project);
    }
}
