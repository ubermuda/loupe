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
             last_refusal_at = NULL, work_request_id = NULL, repaired = false, ask_item_id = NULL, held_by_blocker_since = NULL, updated_at = :now
             WHERE card_id IN (:cardIds)',
            [
                'now' => $now,
                'cardIds' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $cardIds),
            ],
            ['now' => Types::DATETIME_IMMUTABLE, 'cardIds' => ArrayParameterType::STRING],
        );
    }

    /**
     * The cards among $cardIds that a move rule holds for an open blocker alone, with the blocker of the lowest number.
     * A card that has no open blocker now is left out, whatever the stamp says.
     *
     * @param list<string> $cardIds RFC 4122 ids
     *
     * @return list<array{card_id: string, since: string, number: int, title: string}>
     */
    public function findBlockerHolds(array $cardIds): array
    {
        if ([] === $cardIds) {
            return [];
        }

        /** @var list<array{card_id: string, since: string, number: int, title: string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT DISTINCT ON (st.card_id) st.card_id, st.held_by_blocker_since AS since, b.number, b.title
             FROM workflow_rule_states st
             JOIN board_card_links l ON l.target_card_id = st.card_id AND l.kind = \'blocks\'
             JOIN board_cards b ON b.id = l.source_card_id
             JOIN board_columns k ON k.id = b.column_id AND k.terminal = false
             WHERE st.card_id IN (:cards) AND st.held_by_blocker_since IS NOT NULL
             ORDER BY st.card_id, st.held_by_blocker_since, b.number',
            ['cards' => $cardIds],
            ['cards' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        return $rows;
    }

    public function findOneByAskItemId(Uuid $itemId): ?WorkflowRuleState
    {
        return $this->findOneBy(['askItemId' => $itemId]);
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
