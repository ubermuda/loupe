<?php

declare(strict_types=1);

namespace App\Module\Insights\Repository;

use App\Module\Insights\Entity\Analysis;
use App\Module\Insights\Entity\Proposal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Proposal> */
class ProposalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Proposal::class);
    }

    /** @return list<Proposal> */
    public function findByAnalysis(Analysis $analysis): array
    {
        return array_values($this->findBy(['analysis' => $analysis], ['position' => 'ASC']));
    }

    /** Null for a malformed id and for a proposal of another project alike. */
    /**
     * The proposals of each analysis in one query, keyed by analysis id, each list in position order.
     *
     * @param list<Analysis> $analyses
     *
     * @return array<string, list<Proposal>>
     */
    public function findByAnalyses(array $analyses): array
    {
        if ([] === $analyses) {
            return [];
        }
        /** @var list<Proposal> $proposals */
        $proposals = $this->createQueryBuilder('p')
            ->andWhere('p.analysis IN (:analyses)')
            ->setParameter('analyses', $analyses)
            ->orderBy('p.position', 'ASC')
            ->getQuery()
            ->getResult();
        $grouped = [];
        foreach ($proposals as $proposal) {
            $grouped[(string) $proposal->analysis->id][] = $proposal;
        }

        return $grouped;
    }

    public function findOneByIdAndProjectId(string $proposalId, string $projectId): ?Proposal
    {
        if (!Uuid::isValid($proposalId) || !Uuid::isValid($projectId)) {
            return null;
        }

        return $this->createQueryBuilder('p')
            ->join('p.analysis', 'a')
            ->andWhere('p.id = :proposalId')
            ->andWhere('a.project = :projectId')
            ->setParameter('proposalId', Uuid::fromString($proposalId), UuidType::NAME)
            ->setParameter('projectId', Uuid::fromString($projectId), UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Locked until the transaction ends, and read fresh even when the proposal is already managed. */
    public function findOneLocked(Uuid $id): ?Proposal
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.id = :id')
            ->setParameter('id', $id, UuidType::NAME)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }
}
