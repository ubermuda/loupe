<?php

declare(strict_types=1);

namespace App\Module\Workflow\Repository;

use App\Module\Workflow\Entity\WorkflowBinding;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<WorkflowBinding> */
class WorkflowBindingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowBinding::class);
    }

    public function findOneByProjectId(Uuid $projectId): ?WorkflowBinding
    {
        return $this->findOneBy(['project' => $projectId]);
    }

    /**
     * The cards of every bound project that sit in a column that is not terminal and that nobody holds.
     *
     * @return list<string> RFC 4122 card ids
     */
    public function findOpenBoundCardIds(): array
    {
        return array_map(strval(...), $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT c.id FROM board_cards c
             JOIN board_columns col ON col.id = c.column_id
             JOIN workflow_bindings b ON b.project_id = c.project_id
             WHERE col.terminal = false
             AND NOT EXISTS (SELECT 1 FROM bridge_card_holds hold WHERE hold.project_id = c.project_id AND hold.card_id = c.id)
             ORDER BY c.id',
        ));
    }
}
