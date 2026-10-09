<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Command\DeleteCardCommand;
use App\Module\Board\Command\DeleteCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Event\BoardColumnDeleted;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Event\CardDeleted;
use App\Module\Board\EventListener\ForgetWorkflowRowsOnBoardColumnDeleted;
use App\Module\Board\EventListener\ForgetWorkflowRowsOnCardDeleted;
use App\Module\Board\EventListener\SweepWorkflowRowsOnCardDeleted;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Entity\WorkflowPendingBaseline;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ForgetWorkflowRowsTest extends KernelTestCase
{
    use WorkflowProjects;

    public function test_a_deleted_card_takes_its_rule_states_and_baseline_and_spares_another_card(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('forget-card');
        $this->bindLifecycle($project);
        $gone = $this->cardWithRows($project, 1);
        $kept = $this->cardWithRows($project, 2);
        self::assertSame([1, 1], $this->cardRowCounts($gone));

        $listener = self::getContainer()->get(ForgetWorkflowRowsOnCardDeleted::class);
        self::assertInstanceOf(ForgetWorkflowRowsOnCardDeleted::class, $listener);
        $listener(new CardDeleted($project->id ?? throw new \LogicException(), $gone));

        self::assertSame([0, 0], $this->cardRowCounts($gone));
        self::assertSame([1, 1], $this->cardRowCounts($kept));
    }

    public function test_a_sweep_after_the_delete_removes_a_row_an_evaluation_wrote_late(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('sweep-card');
        $this->bindLifecycle($project);
        $late = $this->cardWithRows($project, 1);
        $kept = $this->cardWithRows($project, 2);

        $listener = self::getContainer()->get(SweepWorkflowRowsOnCardDeleted::class);
        self::assertInstanceOf(SweepWorkflowRowsOnCardDeleted::class, $listener);
        $listener(new CardChanged($project->id ?? throw new \LogicException(), $late, CardChanged::DELETED, false));

        self::assertSame([0, 0], $this->cardRowCounts($late));
        self::assertSame([1, 1], $this->cardRowCounts($kept));
    }

    public function test_deleting_a_card_through_its_handler_removes_its_workflow_rows(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('forget-card-handler');
        $this->bindLifecycle($project);
        $goneId = $this->cardWithRows($project, 1);
        $kept = $this->cardWithRows($project, 2);
        $gone = $this->em()->find(Card::class, $goneId) ?? throw new \LogicException();
        $delete = self::getContainer()->get(DeleteCardHandler::class);
        self::assertInstanceOf(DeleteCardHandler::class, $delete);

        $delete(new DeleteCardCommand($gone, Actor::Human));

        self::assertSame([0, 0], $this->cardRowCounts($goneId));
        self::assertSame([1, 1], $this->cardRowCounts($kept));
    }

    public function test_a_deleted_column_leaves_its_slot_unlinked_and_spares_the_other_slots(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('forget-column');
        $this->bindLifecycle($project);
        $column = $this->column($project, 'tech-design');
        $columnId = (string) $column->id;

        $listener = self::getContainer()->get(ForgetWorkflowRowsOnBoardColumnDeleted::class);
        self::assertInstanceOf(ForgetWorkflowRowsOnBoardColumnDeleted::class, $listener);
        $listener(new BoardColumnDeleted($project, $columnId, 'tech-design', null, [], Actor::Human, false, false));

        $connection = $this->em()->getConnection();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM workflow_slot_links WHERE project_id = :id AND slot_key = :slot AND column_id IS NULL', ['id' => (string) $project->id, 'slot' => 'tech-design']));
        self::assertSame(4, (int) $connection->fetchOne('SELECT COUNT(*) FROM workflow_slot_links WHERE project_id = :id AND column_id IS NOT NULL', ['id' => (string) $project->id]));
    }

    private function cardWithRows(Project $project, int $number): Uuid
    {
        $card = new Card($project, $this->column($project, 'next'), 'Card', '', $number);
        $this->em()->persist($card);
        $this->em()->flush();
        $id = $card->id ?? throw new \LogicException('The card is persisted.');
        $this->em()->persist(new WorkflowRuleState($id, $project, 'start-design'));
        $this->em()->persist(new WorkflowPendingBaseline($id, $project));
        $this->em()->flush();

        return $id;
    }

    /** @return array{int, int} the rule state and pending baseline rows of the card */
    private function cardRowCounts(Uuid $cardId): array
    {
        $connection = $this->em()->getConnection();
        $id = ['id' => $cardId->toRfc4122()];

        return [
            (int) $connection->fetchOne('SELECT COUNT(*) FROM workflow_rule_states WHERE card_id = :id', $id),
            (int) $connection->fetchOne('SELECT COUNT(*) FROM workflow_pending_baselines WHERE card_id = :id', $id),
        ];
    }
}
