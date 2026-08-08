<?php

declare(strict_types=1);

namespace App\Module\Review\Twig;

use App\Module\Project\Entity\Project;
use App\Module\Review\Repository\DocumentRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes a project's document count to the app-shell nav pill. Mirrors
 * SiteReviewNavExtension: the count is a Review concern, and Review → Project is
 * the allowed dependency direction.
 *
 * The pill counts what the documents list shows, so archived documents are left
 * out of both or the number contradicts the rows it sits above.
 */
final class ReviewNavExtension extends AbstractExtension
{
    public function __construct(
        private readonly DocumentRepository $documents,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('project_document_count', $this->documentCount(...)),
            new TwigFunction('project_document_counts', $this->documentCounts(...)),
        ];
    }

    public function documentCount(Project $project): int
    {
        return $this->documents->countActiveByProject($project);
    }

    /**
     * @param list<Project> $projects
     *
     * @return array<string, int> project id => count
     */
    public function documentCounts(array $projects): array
    {
        return $this->documents->countActiveByProjects($projects);
    }
}
