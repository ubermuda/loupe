<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Twig;

use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes a project's open site-review count to templates (the sidebar nav
 * pill). Lives in SiteReview because the count is a SiteReview concern and the
 * SiteReview → Project direction is the allowed one — keeping it here avoids a
 * Project ↔ SiteReview module cycle.
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
        ];
    }

    public function openReviewCount(Project $project): int
    {
        return $this->siteReviewComments->countOpenForProject($project);
    }
}
