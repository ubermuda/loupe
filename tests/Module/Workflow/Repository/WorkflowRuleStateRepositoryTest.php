<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Repository;

use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

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
        self::assertSame((string) $card->id, (string) $states['start-design']->card->id);
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

    public function test_reset_clears_the_memory_of_a_rule(): void
    {
        $state = new WorkflowRuleState($this->createStub(Card::class), $this->createStub(Project::class), 'start-design');
        $state->truth = true;
        $state->attempts = 3;
        $state->fires = 2;
        $state->fingerprint = 'abc';
        $state->dueAt = new \DateTimeImmutable();
        $state->lastRefusal = 'no-capacity';

        $state->reset();

        self::assertFalse($state->truth);
        self::assertSame(0, $state->attempts);
        self::assertSame(0, $state->fires);
        self::assertNull($state->dueAt);
        self::assertNull($state->lastRefusal);
        self::assertSame('abc', $state->fingerprint);
    }

    private function card(Project $project): Card
    {
        $card = new Card($project, $this->column($project, 'next'), 'Card', '', ++$this->cardNumber);
        $this->em()->persist($card);
        $this->em()->flush();

        return $card;
    }

    private function state(Card $card, string $ruleId, ?\DateTimeImmutable $dueAt = null): void
    {
        $state = new WorkflowRuleState($card, $card->project, $ruleId);
        $state->dueAt = $dueAt;
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
