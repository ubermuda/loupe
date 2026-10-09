<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Event\BoardColumnDeleted;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\EventListener\ForgetWorkflowRowsOnBoardColumnDeleted;
use App\Module\Board\EventListener\ForgetWorkflowRowsOnCardDeleted;
use App\Module\Project\Entity\Project;
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
        $listener(new CardChanged($project->id ?? throw new \LogicException(), $gone, CardChanged::DELETED, false));

        self::assertSame([0, 0], $this->cardRowCounts($gone));
        self::assertSame([1, 1], $this->cardRowCounts($kept));
    }

    public function test_a_card_that_only_changed_keeps_its_rows(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('forget-card-updated');
        $this->bindLifecycle($project);
        $card = $this->cardWithRows($project, 1);

        $listener = self::getContainer()->get(ForgetWorkflowRowsOnCardDeleted::class);
        self::assertInstanceOf(ForgetWorkflowRowsOnCardDeleted::class, $listener);
        $listener(new CardChanged($project->id ?? throw new \LogicException(), $card, CardChanged::UPDATED, true));

        self::assertSame([1, 1], $this->cardRowCounts($card));
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
        $listener(new BoardColumnDeleted($project, $columnId, 'tech-design', null, [], CardReporter::Human, false, false));

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
