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

    /**
     * Selects no secret, so an unreadable encryption key cannot break the export.
     *
     * @return list<array{projectName: string, hookKey: string, lastAcceptedAt: ?\DateTimeImmutable, lastRefusedAt: ?\DateTimeImmutable, lastRefusedReason: ?string, createdAt: \DateTimeImmutable}>
     */
    public function findExportRowsByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('h')
            ->select('p.name AS projectName', 'h.hookKey', 'h.lastAcceptedAt', 'h.lastRefusedAt', 'h.lastRefusedReason', 'h.createdAt')
            ->join('h.project', 'p')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('h.createdAt', 'ASC')
            ->addOrderBy('h.id', 'ASC')
            ->getQuery()
            ->getResult());
    }
}
