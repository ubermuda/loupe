<?php

declare(strict_types=1);

namespace App\Module\Forge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ForgeRepository> */
final class ForgeRepositoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForgeRepository::class);
    }

    public function findOneByForgeAndExternalId(string $forge, string $externalId): ?ForgeRepository
    {
        return $this->findOneBy(['forge' => $forge, 'externalId' => $externalId]);
    }

    /** @return list<ForgeRepository> */
    public function findByProject(Project $project): array
    {
        return array_values($this->findBy(['project' => $project], ['createdAt' => 'ASC', 'id' => 'ASC']));
    }

    /** @return list<ForgeRepository> */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('r')
            ->join('r.project', 'p')
            ->addSelect('p')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('r.createdAt', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /** Serialises concurrent claims of one repository until the transaction ends. */
    public function lockForClaim(string $forge, string $externalId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtext(:key))',
            ['key' => 'forge_repository:'.$forge.':'.$externalId],
        );
    }
}
