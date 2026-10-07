<?php

declare(strict_types=1);

namespace App\Module\Insights\Repository;

use App\Module\Insights\Entity\InsightsProjectSettings;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InsightsProjectSettings> */
class InsightsProjectSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InsightsProjectSettings::class);
    }

    public function findForProject(Project $project): ?InsightsProjectSettings
    {
        return $this->findOneBy(['project' => $project]);
    }
}
