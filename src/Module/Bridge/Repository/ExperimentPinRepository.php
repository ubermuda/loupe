<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\ExperimentPin;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ExperimentPin>
 */
class ExperimentPinRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExperimentPin::class);
    }

    /** Locked until the transaction ends, and read fresh even when the pin is already managed. */
    public function findOneLocked(Project $project, Uuid $cardId, string $experiment): ?ExperimentPin
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.project = :project')
            ->andWhere('p.cardId = :cardId')
            ->andWhere('p.experiment = :experiment')
            ->setParameter('project', $project)
            ->setParameter('cardId', $cardId, UuidType::NAME)
            ->setParameter('experiment', $experiment)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /** @return list<ExperimentPin> */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('pin')
            ->join('pin.project', 'p')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('pin.createdAt', 'ASC')
            ->addOrderBy('pin.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * One row per experiment and card of the project's pins.
     *
     * @return list<array{experiment: string, cardId: string}>
     */
    public function findExperimentCardRows(Project $project): array
    {
        /** @var list<array{experiment: string, card_id: string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT experiment, card_id FROM bridge_experiment_pins WHERE project_id = :project',
            ['project' => ($project->id ?? throw new \LogicException('Project has no id.'))->toRfc4122()],
        );

        return array_map(static fn (array $row): array => ['experiment' => $row['experiment'], 'cardId' => $row['card_id']], $rows);
    }

    /** @return list<ExperimentPin> */
    public function findOfExperiment(Project $project, string $experiment): array
    {
        return array_values($this->findBy(['project' => $project, 'experiment' => $experiment], ['createdAt' => 'ASC', 'id' => 'ASC']));
    }

    public function deleteUpdatedBefore(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('pin')
            ->delete()
            ->andWhere('pin.updatedAt < :cutoff')
            ->setParameter('cutoff', $cutoff, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->execute();
    }
}
