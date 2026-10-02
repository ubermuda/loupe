<?php

declare(strict_types=1);

namespace App\Module\Workflow\Repository;

use App\Module\Board\Entity\Card;
use App\Module\Workflow\Entity\WorkflowRuleState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WorkflowRuleState> */
class WorkflowRuleStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowRuleState::class);
    }

    /** @return array<string, WorkflowRuleState> keyed by rule id */
    public function findForCard(Card $card): array
    {
        $states = [];
        foreach ($this->findBy(['card' => $card]) as $state) {
            $states[$state->ruleId] = $state;
        }

        return $states;
    }

    /**
     * The cards with a retry due at $now or before, the longest overdue first.
     *
     * @return list<string> RFC 4122 card ids
     */
    public function findDueCardIds(\DateTimeImmutable $now, int $limit): array
    {
        return array_map(strval(...), $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT card_id FROM workflow_rule_states WHERE due_at <= :now
             GROUP BY card_id ORDER BY MIN(due_at), card_id LIMIT :limit',
            ['now' => $now, 'limit' => $limit],
            ['now' => Types::DATETIME_IMMUTABLE, 'limit' => Types::INTEGER],
        ));
    }
}
