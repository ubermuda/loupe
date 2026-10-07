<?php

declare(strict_types=1);

namespace App\Module\Insights\Repository;

use App\Module\Account\Entity\User;
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

    /** @return list<InsightsProjectSettings> */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('s')
            ->join('s.project', 'p')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult());
    }
}
