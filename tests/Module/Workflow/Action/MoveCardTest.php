<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Action;

use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Action\MoveCard;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use App\Tests\Module\Workflow\Fact\FactsMother;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MoveCardTest extends KernelTestCase
{
    use ActionScenario;

    public function test_it_moves_the_card_to_the_column_of_the_slot_as_the_system_with_the_rule_as_cause(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('move-slot');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'tech-design');
        $rule = $this->rule('move', ['to' => 'implementation'], 'tech-design-approved');

        $outcome = $this->runAction($this->action(), $rule, $card->snapshot(), FactsMother::facts(), $this->state($card, $rule->id));

        self::assertEquals(ActionOutcome::done(), $outcome);
        self::assertSame('in-progress', $card->column->slug);
        $moves = array_values(array_filter(
            $this->service(CardEventRepository::class)->findForCard($card),
            static fn ($event): bool => CardEventKind::Moved === $event->kind,
        ));
        self::assertCount(1, $moves);
        self::assertSame(Actor::System, $moves[0]->actorKind);
        self::assertSame(['type' => 'workflow-rule', 'rule' => 'tech-design-approved'], $moves[0]->detail['cause']);
    }

    public function test_it_resolves_the_backlog_and_the_first_terminal_column(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('move-flags');
        $card = $this->card($project, 'next');

        self::assertEquals(ActionOutcome::done(), $this->runAction($this->action(), $this->rule('move', ['to' => '@terminal']), $card->snapshot(), FactsMother::facts(), $this->state($card)));
        self::assertTrue($card->column->terminal);

        self::assertEquals(ActionOutcome::done(), $this->runAction($this->action(), $this->rule('move', ['to' => '@backlog']), $card->snapshot(), FactsMother::facts(), $this->state($card)));
        self::assertTrue($card->column->backlog);
    }

    public function test_a_card_already_in_the_target_column_is_done_and_writes_no_history(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('move-same');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'in-progress');

        $outcome = $this->runAction($this->action(), $this->rule('move', ['to' => 'implementation']), $card->snapshot(), FactsMother::facts(), $this->state($card));

        self::assertEquals(ActionOutcome::done(), $outcome);
        self::assertSame('in-progress', $card->column->slug);
        self::assertSame([], $this->service(CardEventRepository::class)->findForCard($card));
    }

    public function test_a_card_outside_the_source_slot_stays_where_it_is(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('move-from');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'next');
        $rule = $this->rule('move', ['to' => 'implementation', 'from' => '@backlog']);

        $outcome = $this->runAction($this->action(), $rule, $card->snapshot(), FactsMother::facts(card: FactsMother::card(slot: 'next')), $this->state($card));

        self::assertEquals(ActionOutcome::done(), $outcome);
        self::assertSame('next', $card->column->slug);
        self::assertSame([], $this->service(CardEventRepository::class)->findForCard($card));

        $card->column = $this->column($project, 'backlog');
        $this->em()->flush();
        $outcome = $this->runAction($this->action(), $rule, $card->snapshot(), FactsMother::facts(card: FactsMother::card(slot: '@backlog')), $this->state($card));

        self::assertEquals(ActionOutcome::done(), $outcome);
        self::assertSame('in-progress', $card->column->slug);
    }

    public function test_a_slot_with_no_linked_column_is_refused(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('move-unbound');
        $card = $this->card($project, 'tech-design');

        $outcome = $this->runAction($this->action(), $this->rule('move', ['to' => 'implementation']), $card->snapshot(), FactsMother::facts(), $this->state($card));

        self::assertEquals(ActionOutcome::refused('workflow-slot-missing'), $outcome);
        self::assertSame('tech-design', $card->column->slug);
    }

    private function action(): MoveCard
    {
        return new MoveCard(
            $this->service(CardRepository::class),
            $this->service(BoardColumnRepository::class),
            $this->service(WorkflowSlotLinkRepository::class),
            $this->service(UpdateCardHandler::class),
        );
    }
}
