<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Action;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Action\ActionOutcome;
use App\Module\Workflow\Action\EvaluateChildren;
use App\Module\Workflow\Contract\CardEvaluations;
use App\Module\Workflow\Template\ActionType;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class EvaluateChildrenTest extends KernelTestCase
{
    use ActionScenario;

    public function test_it_asks_to_evaluate_every_child_of_the_card(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('evaluate-children');
        $epic = $this->card($project, 'in-progress');
        $epic->type = 'epic';
        $waiting = $this->card($project, 'backlog');
        $waiting->parent = $epic;
        $done = $this->card($project, 'done');
        $done->parent = $epic;
        $this->card($project, 'backlog');
        $this->em()->flush();

        $evaluations = $this->createMock(CardEvaluations::class);
        $evaluations->expects($this->once())->method('forCards')->with(self::callback(
            static fn (array $ids): bool => self::ids([$waiting->id, $done->id]) === self::ids($ids),
        ));

        self::assertEquals(ActionOutcome::done(), $this->evaluateChildren($evaluations, $epic));
    }

    public function test_a_card_with_no_child_asks_for_nothing(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('evaluate-no-children'), 'in-progress');

        $evaluations = $this->createMock(CardEvaluations::class);
        $evaluations->expects($this->never())->method('forCards');

        self::assertEquals(ActionOutcome::done(), $this->evaluateChildren($evaluations, $card));
    }

    private function evaluateChildren(CardEvaluations&MockObject $evaluations, Card $card): ActionOutcome
    {
        $action = new EvaluateChildren($this->service(CardRepository::class), $evaluations);

        return $action->run($this->rule(ActionType::Evaluate, ['cards' => 'children']), $card, FactsMother::facts(), $this->state($card));
    }

    /**
     * @param array<mixed> $ids
     *
     * @return list<string>
     */
    private static function ids(array $ids): array
    {
        $strings = array_map(static fn (mixed $id): string => $id instanceof Uuid ? $id->toRfc4122() : (\is_string($id) ? $id : ''), $ids);
        sort($strings);

        return $strings;
    }
}
