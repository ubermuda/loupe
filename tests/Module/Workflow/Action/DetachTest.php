<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Action;

use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Workflow\Action\ActionOutcome;
use App\Module\Workflow\Action\Detach;
use App\Module\Workflow\Template\ActionType;
use App\Tests\Module\Workflow\Fact\FactsMother;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DetachTest extends KernelTestCase
{
    use ActionScenario;

    public function test_it_removes_the_parent_of_the_card(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('detach');
        $parent = $this->card($project, 'next');
        $card = $this->card($project, 'next');
        $card->parent = $parent;
        $this->em()->flush();
        $cardId = $card->id;
        $this->em()->clear();
        $card = $this->em()->find(Card::class, $cardId) ?? throw new \LogicException('The card exists.');
        self::assertNotNull($card->parent);

        $outcome = $this->detach($card, true);

        self::assertEquals(ActionOutcome::done(), $outcome);
        $this->em()->clear();
        self::assertNull($this->em()->find(Card::class, $cardId)?->parent);
    }

    public function test_a_card_with_no_parent_is_done_and_stays_as_it_is(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('detach-none'), 'next');

        self::assertEquals(ActionOutcome::done(), $this->detach($card, false));
        self::assertNull($card->parent);
    }

    private function detach(Card $card, bool $isChild): ActionOutcome
    {
        $rule = $this->rule(ActionType::Detach, [], 'unplanned-child');

        return new Detach($this->service(UpdateCardHandler::class))->run($rule, $card, FactsMother::facts(card: FactsMother::card(isChild: $isChild)), $this->state($card, $rule->id));
    }
}
