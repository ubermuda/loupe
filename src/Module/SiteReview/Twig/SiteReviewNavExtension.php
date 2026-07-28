<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Twig;

use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Feeds the app-shell site-review nav pill. The pill shows how many comments
 * have been submitted and tints amber while any of them are still pending, so
 * both figures come back from a single call — and a single query — rather than
 * one function per number.
 *
 * Lives in SiteReview because the counts are a SiteReview concern and the
 * SiteReview → Project direction is the allowed one, which keeps a
 * Project ↔ SiteReview module cycle from forming.
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
            new TwigFunction('project_site_review_counts', $this->siteReviewCounts(...)),
        ];
    }

    /**
     * @return array{total: int, pending: int} submitted total, and how many of
     *                                         those are still awaiting the agent
     */
    public function siteReviewCounts(Project $project): array
    {
        $counts = $this->siteReviewComments->submittedStatusCountsForProject($project);

        return [
            'total' => array_sum($counts),
            'pending' => $counts['pending'],
        ];
    }
}
