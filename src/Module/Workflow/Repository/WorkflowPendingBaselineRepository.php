<?php

declare(strict_types=1);

namespace App\Module\Workflow\Repository;

use App\Module\Workflow\Entity\WorkflowPendingBaseline;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<WorkflowPendingBaseline> */
class WorkflowPendingBaselineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowPendingBaseline::class);
    }

    /**
     * One statement, so it joins a caller's transaction. It skips an id with no card row,
     * because a failed insert would abort that transaction.
     *
     * @param non-empty-list<Uuid> $cardIds
     */
    public function markCards(Uuid $projectId, array $cardIds): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO workflow_pending_baselines (id, card_id, project_id, created_at)
             SELECT gen_random_uuid(), c.id, c.project_id, LOCALTIMESTAMP(0) FROM board_cards c
             WHERE c.project_id = :project AND c.id IN (:cardIds)
             ON CONFLICT (card_id) DO NOTHING',
            [
                'project' => $projectId->toRfc4122(),
                'cardIds' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $cardIds),
            ],
            ['cardIds' => ArrayParameterType::STRING],
        );
    }

    /** Marks every card of the project, in one statement. */
    public function markProject(Uuid $projectId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO workflow_pending_baselines (id, card_id, project_id, created_at)
             SELECT gen_random_uuid(), c.id, c.project_id, LOCALTIMESTAMP(0) FROM board_cards c
             WHERE c.project_id = :project
             ON CONFLICT (card_id) DO NOTHING',
            ['project' => $projectId->toRfc4122()],
        );
    }

    public function isMarked(Uuid $cardId): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM workflow_pending_baselines WHERE card_id = :card',
            ['card' => $cardId->toRfc4122()],
        );
    }

    /** Deletes the mark of the card. Answers whether it had one. */
    public function consume(Uuid $cardId): bool
    {
        return 0 < $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM workflow_pending_baselines WHERE card_id = :card',
            ['card' => $cardId->toRfc4122()],
        );
    }
}
