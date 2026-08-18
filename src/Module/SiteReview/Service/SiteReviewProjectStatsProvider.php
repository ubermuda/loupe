<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Service;

use App\Module\Project\Stats\ProjectStats;
use App\Module\Project\Stats\ProjectStatsProviderInterface;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;

/** SiteReview's contribution: how many widget comments each project has, and how many are still pending. */
final readonly class SiteReviewProjectStatsProvider implements ProjectStatsProviderInterface
{
    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
    ) {
    }

    #[\Override]
    public function statsFor(array $projects): array
    {
        return array_map(
            static fn (array $counts): ProjectStats => new ProjectStats(
                commentCount: array_sum($counts),
                openCommentCount: $counts['pending'],
            ),
            $this->siteReviewComments->statusCountsForProjects($projects),
        );
    }
}
