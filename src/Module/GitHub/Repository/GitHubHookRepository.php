<?php

declare(strict_types=1);

namespace App\Module\GitHub\Repository;

use App\Module\Account\Entity\User;
use App\Module\GitHub\Entity\GitHubHook;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<GitHubHook> */
final class GitHubHookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GitHubHook::class);
    }

    public function findOneByProject(Project $project): ?GitHubHook
    {
        return $this->findOneBy(['project' => $project]);
    }

    /** @return list<GitHubHook> */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('h')
            ->join('h.project', 'p')
            ->addSelect('p')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('h.createdAt', 'ASC')
            ->addOrderBy('h.id', 'ASC')
            ->getQuery()
            ->getResult());
    }
}
