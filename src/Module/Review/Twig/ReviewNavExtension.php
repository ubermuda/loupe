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
        ];
    }

    public function documentCount(Project $project): int
    {
        return $this->documents->countByProject($project);
    }
}
