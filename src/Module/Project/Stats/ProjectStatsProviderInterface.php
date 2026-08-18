<?php

declare(strict_types=1);

namespace App\Module\Project\Stats;

use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One implementation per module that counts something about a project, mirroring
 * UserDataExporterInterface: the module owning the rows owns the query, and
 * Project only collects. Without it the projects list has to import Review's and
 * SiteReview's repositories — the two modules that already depend on Project.
 *
 * Takes the whole page at once, because per-project counting is what made this
 * list forty queries in the first place.
 */
#[AutoconfigureTag('app.project_stats_provider')]
interface ProjectStatsProviderInterface
{
    /**
     * @param list<Project> $projects
     *
     * @return array<string, ProjectStats> keyed by project id; a project this
     *                                     provider counts nothing for may be absent
     */
    public function statsFor(array $projects): array;
}
