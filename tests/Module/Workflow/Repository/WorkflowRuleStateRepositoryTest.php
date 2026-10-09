<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Repository;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Entity\CardPauseKind;
use App\Module\Bridge\Entity\CardHold;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Tests\Module\Workflow\WorkflowProjects;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WorkflowRuleStateRepositoryTest extends KernelTestCase
{
    use WorkflowProjects;

    private int $cardNumber = 0;

    public function test_find_for_card_keys_the_states_of_one_card_by_rule_id(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('rule-state-card');
        $card = $this->card($project);
        $other = $this->card($project);
        $this->state($card, 'start-design');
        $this->state($card, 'open-review');
        $this->state($other, 'start-design');
        $this->em()->clear();

        $states = $this->repository()->findForCard($this->em()->find(Card::class, $card->id) ?? throw new \LogicException('The card exists.'));

        self::assertSame(['open-review', 'start-design'], $this->sortedKeys($states));
        self::assertSame((string) $card->id, (string) $states['start-design']->cardId);
    }

    public function test_find_one_by_ask_item_id_gives_the_state_that_holds_the_item(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('rule-state-ask'));
        $itemId = Uuid::v7();
        $this->state($card, 'other');
        $this->state($card, 'ask', askItemId: $itemId);
        $this->em()->clear();

        self::assertSame('ask', $this->repository()->findOneByAskItemId($itemId)?->ruleId);
        self::assertNull($this->repository()->findOneByAskItemId(Uuid::v7()));
    }

    public function test_reset_for_cards_clears_the_ask_item_id_of_those_cards_only(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('rule-state-reset-ask');
        $card = $this->card($project);
        $other = $this->card($project);
        $this->state($card, 'ask', askItemId: Uuid::v7());
        $this->state($other, 'ask', askItemId: Uuid::v7());

        $this->repository()->resetForCards([$card->id ?? throw new \LogicException('A flushed card has an id.')], new \DateTimeImmutable());
        $this->em()->clear();

        self::assertNull($this->em()->getConnection()->fetchOne('SELECT ask_item_id FROM workflow_rule_states WHERE card_id = ?', [(string) $card->id]));
        self::assertNotNull($this->em()->getConnection()->fetchOne('SELECT ask_item_id FROM workflow_rule_states WHERE card_id = ?', [(string) $other->id]));
    }

    /** The test transaction holds the lock, so a second session cannot take it. */
    public function test_lock_card_holds_an_advisory_lock_on_the_card_until_the_transaction_ends(): void
    {
        self::bootKernel();
        $cardId = Uuid::v7();
        $this->repository()->lockCard($cardId);
        $other = DriverManager::getConnection($this->em()->getConnection()->getParams());

        try {
            self::assertNotSame($this->em()->getConnection()->fetchOne('SELECT pg_backend_pid()'), $other->fetchOne('SELECT pg_backend_pid()'));
            $tryLock = static fn (string $key): bool => (bool) $other->fetchOne('SELECT pg_try_advisory_xact_lock(hashtext(?))', [$key]);
            self::assertFalse($tryLock('workflow_card:'.$cardId->toRfc4122()));
            self::assertTrue($tryLock('workflow_card:'.Uuid::v7()->toRfc4122()));
        } finally {
            $other->close();
        }
    }

    public function test_find_due_card_ids_gives_each_due_card_once_most_overdue_first_up_to_the_limit(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('rule-state-due');
        $now = new \DateTimeImmutable('2026-10-02 12:00:00');
        $late = $this->card($project);
        $later = $this->card($project);
        $notYet = $this->card($project);
        $never = $this->card($project);
        $third = $this->card($project);
        $this->state($late, 'a', $now->modify('-1 hour'));
        $this->state($late, 'b', $now->modify('-1 minute'));
        $this->state($later, 'a', $now->modify('-2 hours'));
        $this->state($notYet, 'a', $now->modify('+1 minute'));
        $this->state($never, 'a');
        $this->state($third, 'a', $now);

        $repository = $this->repository();

        self::assertSame([(string) $later->id, (string) $late->id], $repository->findDueCardIds($now, 2));
        self::assertSame([(string) $later->id, (string) $late->id, (string) $third->id], $repository->findDueCardIds($now, 10));
    }

    public function test_find_due_card_ids_skips_a_paused_card_and_keeps_its_due_time_for_after_the_release(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('rule-state-due-paused');
        $now = new \DateTimeImmutable('2026-10-02 12:00:00');
        $paused = $this->card($project);
        $free = $this->card($project);
        $this->state($paused, 'a', $now->modify('-2 hours'));
        $this->state($free, 'a', $now->modify('-1 hour'));
        $pause = new CardPause($paused, $project, 'on-hold', 'hold', CardPauseKind::Rule, $now);
        $this->em()->persist($pause);
        $this->em()->flush();

        self::assertSame([(string) $free->id], $this->repository()->findDueCardIds($now, 10));

        $pause->release('until-met', $now);
        $this->em()->flush();

        self::assertSame([(string) $paused->id, (string) $free->id], $this->repository()->findDueCardIds($now, 10));
    }

    public function test_find_due_card_ids_skips_a_held_card(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('rule-state-due-held');
        $now = new \DateTimeImmutable('2026-10-02 12:00:00');
        $held = $this->card($project);
        $free = $this->card($project);
        $this->state($held, 'a', $now->modify('-2 hours'));
        $this->state($free, 'a', $now->modify('-1 hour'));
        self::assertSame([(string) $held->id, (string) $free->id], $this->repository()->findDueCardIds($now, 10));

        $this->em()->persist(new CardHold($project, $held->id ?? throw new \LogicException('A flushed card has an id.'), null, $now));
        $this->em()->flush();

        self::assertSame([(string) $free->id], $this->repository()->findDueCardIds($now, 10));
    }

    public function test_reset_clears_the_memory_of_a_rule(): void
    {
        $state = new WorkflowRuleState(Uuid::v7(), $this->createStub(Project::class), 'start-design');
        $state->truth = true;
        $state->attempts = 3;
        $state->fires = 2;
        $state->fingerprint = 'abc';
        $state->dueAt = new \DateTimeImmutable();
        $state->lastRefusal = 'no-capacity';
        $state->lastRefusalAt = new \DateTimeImmutable();

        $state->reset();

        self::assertFalse($state->truth);
        self::assertSame(0, $state->attempts);
        self::assertSame(0, $state->fires);
        self::assertNull($state->dueAt);
        self::assertNull($state->lastRefusal);
        self::assertNull($state->lastRefusalAt);
        self::assertSame('abc', $state->fingerprint);
    }

    private function card(Project $project): Card
    {
        $card = new Card($project, $this->column($project, 'next'), 'Card', '', ++$this->cardNumber);
        $this->em()->persist($card);
        $this->em()->flush();

        return $card;
    }

    private function state(Card $card, string $ruleId, ?\DateTimeImmutable $dueAt = null, ?Uuid $askItemId = null): void
    {
        $state = new WorkflowRuleState($card->id ?? throw new \LogicException('The card is persisted.'), $card->project, $ruleId);
        $state->dueAt = $dueAt;
        $state->askItemId = $askItemId;
        $this->em()->persist($state);
        $this->em()->flush();
    }

    private function repository(): WorkflowRuleStateRepository
    {
        $repository = self::getContainer()->get(WorkflowRuleStateRepository::class);
        self::assertInstanceOf(WorkflowRuleStateRepository::class, $repository);

        return $repository;
    }

    /**
     * @param array<string, WorkflowRuleState> $states
     *
     * @return list<string>
     */
    private function sortedKeys(array $states): array
    {
        $keys = array_keys($states);
        sort($keys);

        return $keys;
    }
}
