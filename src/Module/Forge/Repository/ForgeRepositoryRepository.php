<?php

declare(strict_types=1);

namespace App\Module\Forge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<ForgeRepository> */
final class ForgeRepositoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForgeRepository::class);
    }

    public function findOneForProject(Project $project, string $forge, string $externalId): ?ForgeRepository
    {
        return $this->findOneBy(['project' => $project, 'forge' => $forge, 'externalId' => $externalId]);
    }

    /** At most one row, because claim() refuses a second installation row under its lock. */
    public function findInstallationRow(string $forge, string $externalId): ?ForgeRepository
    {
        return $this->findOneBy(['forge' => $forge, 'externalId' => $externalId, 'source' => ForgeRepositorySource::Installation]);
    }

    /** @return list<ForgeRepository> */
    public function findByInstallation(string $forge, string $sourceRef): array
    {
        return array_values($this->findBy(['forge' => $forge, 'source' => ForgeRepositorySource::Installation, 'sourceRef' => $sourceRef]));
    }

    public function findOneByIdAndProjectId(string $id, string $projectId): ?ForgeRepository
    {
        if (!Uuid::isValid($id) || !Uuid::isValid($projectId)) {
            return null;
        }

        return $this->createQueryBuilder('r')
            ->andWhere('r.id = :id')
            ->andWhere('r.project = :projectId')
            ->setParameter('id', Uuid::fromString($id), UuidType::NAME)
            ->setParameter('projectId', Uuid::fromString($projectId), UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
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
