<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Bridge\Entity\ExperimentPin;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
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
        /** @var ExperimentPin|null */
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
}
