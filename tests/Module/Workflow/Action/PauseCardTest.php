<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Action;

use App\Module\Project\Entity\Project;
use App\Module\Workflow\Action\ActionOutcomeKind;
use App\Module\Workflow\Action\PauseCard;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\ColumnRef;
use App\Module\Workflow\Contract\PauseKind;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Expression\AllOf;
use App\Module\Workflow\Template\ActionCall;
use App\Module\Workflow\Template\ActionType;
use App\Module\Workflow\Template\Rule;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class PauseCardTest extends TestCase
{
    public function test_it_answers_a_rule_pause_with_the_reason(): void
    {
        $rule = new Rule('hold-for-owner', 'in-review', new AllOf([]), new ActionCall(ActionType::Pause, ['reason' => 'owner_review'], new AllOf([])));
        $column = new ColumnRef(Uuid::v7(), false, false);
        $card = new CardSnapshot(Uuid::v7(), Uuid::v7(), 1, 'feature', $column, null, null, null);
        $state = new WorkflowRuleState(Uuid::v7(), $this->createStub(Project::class), $rule->id);

        $outcome = new PauseCard()->run($rule, $card, FactsMother::facts(), $state);

        self::assertSame(ActionOutcomeKind::Pause, $outcome->kind);
        self::assertSame(PauseKind::Rule, $outcome->pauseKind);
        self::assertSame('owner-review', $outcome->code);
    }
}
