<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
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

    /** Locks the row until the caller's transaction ends, and re-reads a row this entity manager already holds. */
    public function findOneByProjectForUpdate(Project $project): ?BoardAutomationSettings
    {
        return $this->createQueryBuilder('settings')
            ->andWhere('settings.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }
}
