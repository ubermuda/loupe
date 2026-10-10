<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<BoardAutomationSettings> */
class BoardAutomationSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BoardAutomationSettings::class);
    }

    public function findOneByProject(Project $project): ?BoardAutomationSettings
    {
        return $this->findOneBy(['project' => $project]);
    }
}
