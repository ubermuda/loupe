<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Twig;

use App\LoopStage;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes a project's open site-review count and derived loop stage to templates
 * (the sidebar nav pill and the site-review loop ribbon). Lives in SiteReview
 * because both are SiteReview concerns and the SiteReview → Project direction is
 * the allowed one — keeping it here avoids a Project ↔ SiteReview module cycle.
 * Importing the root-namespace App\LoopStage introduces no cross-module edge.
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
            new TwigFunction('site_review_loop_stage', $this->loopStage(...)),
        ];
    }

    public function openReviewCount(Project $project): int
    {
        return $this->siteReviewComments->countOpenForProject($project);
    }

    /**
     * Derives the site-review loop stage from the project's submitted-review
     * comments: any Pending → In review; else any Addressed → Revise; else any
     * Resolved → Approved; no comments at all → Proposed.
     */
    public function loopStage(Project $project): LoopStage
    {
        $counts = $this->siteReviewComments->statusCountsForProject($project);

        return match (true) {
            $counts['pending'] > 0 => LoopStage::InReview,
            $counts['addressed'] > 0 => LoopStage::Revise,
            $counts['resolved'] > 0 => LoopStage::Approved,
            default => LoopStage::Proposed,
        };
    }
}
