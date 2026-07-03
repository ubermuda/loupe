<?php

declare(strict_types=1);

namespace App\Module\Project\Twig;

use App\Module\Project\Entity\Project;
use App\Module\Project\Service\CurrentProjectProvider;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the app-shell context to templates: the request's current project
 * (drives the switcher + scoped nav) and its open site-review count (the nav
 * pill). Both delegate to services; no logic lives here.
 */
final class ProjectNavExtension extends AbstractExtension
{
    public function __construct(
        private readonly CurrentProjectProvider $currentProjectProvider,
        private readonly SiteReviewCommentRepository $siteReviewComments,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('current_project', $this->currentProject(...)),
            new TwigFunction('project_open_review_count', $this->openReviewCount(...)),
        ];
    }

    public function currentProject(): ?Project
    {
        return $this->currentProjectProvider->current();
    }

    public function openReviewCount(Project $project): int
    {
        return $this->siteReviewComments->countOpenForProject($project);
    }
}
