<?php

declare(strict_types=1);

namespace App\Module\GitHub\Repository;

use App\Module\Account\Entity\User;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<GitHubInstallation> */
final class GitHubInstallationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GitHubInstallation::class);
    }

    public function findOneByInstallationId(int $installationId): ?GitHubInstallation
    {
        return $this->findOneBy(['installationId' => $installationId]);
    }

    /** @return list<GitHubInstallation> */
    public function findByProject(Project $project): array
    {
        return array_values($this->findBy(['project' => $project], ['createdAt' => 'ASC', 'id' => 'ASC']));
    }

    /** @return list<GitHubInstallation> */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('i')
            ->join('i.project', 'p')
            ->addSelect('p')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('i.createdAt', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult());
    }
}
