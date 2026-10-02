<?php

declare(strict_types=1);

namespace App\Module\Workflow\Repository;

use App\Module\Board\Entity\Card;
use App\Module\Workflow\Entity\WorkflowRuleState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<WorkflowRuleState> */
class WorkflowRuleStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowRuleState::class);
    }

    /** Serialises the evaluations of one card until the transaction ends. */
    public function lockCard(Uuid $cardId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtext(?))',
            ['workflow_card:'.$cardId->toRfc4122()],
        );
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
     * The cards with a retry due at $now or before, the longest overdue first. A paused card waits
     * for its release, and its due time stays.
     *
     * @return list<string> RFC 4122 card ids
     */
    public function findDueCardIds(\DateTimeImmutable $now, int $limit): array
    {
        return array_map(strval(...), $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT card_id FROM workflow_rule_states state WHERE due_at <= :now
             AND NOT EXISTS (SELECT 1 FROM card_pauses pause WHERE pause.card_id = state.card_id AND pause.released_at IS NULL)
             GROUP BY card_id ORDER BY MIN(due_at), card_id LIMIT :limit',
            ['now' => $now, 'limit' => $limit],
            ['now' => Types::DATETIME_IMMUTABLE, 'limit' => Types::INTEGER],
        ));
    }
}
