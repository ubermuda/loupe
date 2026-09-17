<?php

declare(strict_types=1);

namespace App\Search;

use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.search_provider')]
interface SearchProviderInterface
{
    public const int PAGE_SIZE = 10;

    public function search(Project $project, string $query, int $page): SearchResults;
}
