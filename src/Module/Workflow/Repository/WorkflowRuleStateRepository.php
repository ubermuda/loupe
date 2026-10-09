<?php

declare(strict_types=1);

namespace App\Module\Workflow\Repository;

use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Entity\WorkflowRuleState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
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

    /**
     * Forgets the truth, the retries and the work count of the rules of the cards, in one statement, so it joins a caller's transaction.
     * The fingerprint and the subject pull request stay, for the pauses that compare against them. A managed state is stale after it.
     * It clears the ask item and withdraws nothing. Its caller releases held cards, and the inbox withdrew their items when the holds began.
     *
     * @param non-empty-list<Uuid> $cardIds
     */
    public function resetForCards(array $cardIds, \DateTimeImmutable $now): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE workflow_rule_states SET truth = false, attempts = 0, fires = 0, due_at = NULL, last_refusal = NULL,
             last_refusal_at = NULL, work_request_id = NULL, repaired = false, ask_item_id = NULL, updated_at = :now
             WHERE card_id IN (:cardIds)',
            [
                'now' => $now,
                'cardIds' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $cardIds),
            ],
            ['now' => Types::DATETIME_IMMUTABLE, 'cardIds' => ArrayParameterType::STRING],
        );
    }

    /** One statement, so it joins a caller's transaction. */
    public function deleteForCard(Uuid $cardId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM workflow_rule_states WHERE card_id = :card',
            ['card' => $cardId->toRfc4122()],
        );
    }

    public function findOneByAskItemId(Uuid $itemId): ?WorkflowRuleState
    {
        return $this->findOneBy(['askItemId' => $itemId]);
    }

    /** @return array<string, WorkflowRuleState> keyed by rule id */
    public function findForCard(Card $card): array
    {
        $states = [];
        foreach ($this->findBy(['cardId' => $card->id]) as $state) {
            $states[$state->ruleId] = $state;
        }

        return $states;
    }

    /** @return list<WorkflowRuleState> */
    public function findRefusedInProject(Project $project, string $refusal): array
    {
        return $this->findBy(['project' => $project, 'lastRefusal' => $refusal]);
    }

    /**
     * The cards with a retry due at $now or before, the longest overdue first. A paused or held card
     * waits for its release, and its due time stays.
     *
     * @return list<string> RFC 4122 card ids
     */
    public function findDueCardIds(\DateTimeImmutable $now, int $limit): array
    {
        return array_map(strval(...), $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT card_id FROM workflow_rule_states state WHERE due_at <= :now
             AND NOT EXISTS (SELECT 1 FROM card_pauses pause WHERE pause.card_id = state.card_id AND pause.released_at IS NULL)
             AND NOT EXISTS (SELECT 1 FROM bridge_card_holds hold WHERE hold.project_id = state.project_id AND hold.card_id = state.card_id)
             GROUP BY card_id ORDER BY MIN(due_at), card_id LIMIT :limit',
            ['now' => $now, 'limit' => $limit],
            ['now' => Types::DATETIME_IMMUTABLE, 'limit' => Types::INTEGER],
        ));
    }
}
