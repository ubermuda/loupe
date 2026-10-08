<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\ExperimentDefinition;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ExperimentDefinition>
 */
class ExperimentDefinitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExperimentDefinition::class);
    }

    /**
     * Writes past the identity map, so clear the entity manager before a read.
     *
     * @param list<array{name: string, weight: int}> $weights
     * @param list<string>|null                      $metrics
     */
    public function upsert(Project $project, string $experiment, array $weights, ?array $metrics, \DateTimeImmutable $reportedAt): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO bridge_experiment_definitions (id, project_id, experiment, weights, metrics, reported_at)'
            .' VALUES (:id, :project, :experiment, :weights, :metrics, :reportedAt)'
            .' ON CONFLICT (project_id, experiment) DO UPDATE SET weights = EXCLUDED.weights, metrics = EXCLUDED.metrics, reported_at = EXCLUDED.reported_at',
            [
                'id' => Uuid::v7(),
                'project' => $project->id,
                'experiment' => $experiment,
                'weights' => $weights,
                'metrics' => $metrics,
                'reportedAt' => $reportedAt,
            ],
            [
                'id' => UuidType::NAME,
                'project' => UuidType::NAME,
                'experiment' => Types::STRING,
                'weights' => Types::JSON,
                'metrics' => Types::JSON,
                'reportedAt' => Types::DATETIME_IMMUTABLE,
            ],
        );
    }

    /** @return list<ExperimentDefinition> */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('definition')
            ->join('definition.project', 'p')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('definition.reportedAt', 'ASC')
            ->addOrderBy('definition.id', 'ASC')
            ->getQuery()
            ->getResult());
    }
}
