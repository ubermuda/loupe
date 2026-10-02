<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Action;

use App\Module\Board\Entity\CardPauseKind;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Workflow\Action\ActionOutcome;
use App\Module\Workflow\Action\RequestWork;
use App\Module\Workflow\Action\WorkRequestOpener;
use App\Module\Workflow\Template\ActionType;
use App\Tests\Module\Workflow\Fact\FactsMother;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RequestWorkTest extends KernelTestCase
{
    use ActionScenario;

    public function test_it_opens_a_work_request_of_the_kind_and_capability_for_the_rule(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('request-open'), 'next');
        $rule = $this->rule(ActionType::Request, ['kind' => 'product-design', 'capability' => 'interactive'], 'start-product-design');

        $outcome = $this->action()->run($rule, $card, FactsMother::facts(), $this->state($card, $rule->id));

        self::assertEquals(ActionOutcome::done(), $outcome);
        $live = $this->service(WorkRequestRepository::class)->findLiveForCard($card->id ?? throw new \LogicException('A flushed card has an id.'));
        self::assertCount(1, $live);
        self::assertSame(['product-design', 'interactive', 'start-product-design', $card->number], [$live[0]->kind, $live[0]->capability, $live[0]->ruleId, $live[0]->cardNumber]);
    }

    public function test_a_live_request_of_the_kind_is_done_and_opens_no_second_one(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('request-live'), 'next');
        $rule = $this->rule(ActionType::Request, ['kind' => 'fix']);

        $this->action()->run($rule, $card, FactsMother::facts(), $this->state($card));
        $outcome = $this->action()->run($rule, $card, FactsMother::facts(), $this->state($card));

        self::assertEquals(ActionOutcome::done(), $outcome);
        self::assertCount(1, $this->service(WorkRequestRepository::class)->findLiveForCard($card->id ?? throw new \LogicException('A flushed card has an id.')));
    }

    public function test_an_invalid_kind_is_refused(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('request-invalid'), 'next');

        $outcome = $this->action()->run($this->rule(ActionType::Request, ['kind' => 'Not A Kind']), $card, FactsMother::facts(), $this->state($card));

        self::assertEquals(ActionOutcome::refused('invalid-work-request'), $outcome);
    }

    public function test_a_rule_that_fired_its_limit_pauses_the_card_and_opens_nothing(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('request-limit'), 'next');
        $rule = $this->rule(ActionType::Request, ['kind' => 'fix', 'limit' => 3]);
        $state = $this->state($card);
        $state->fires = 2;

        self::assertEquals(ActionOutcome::done(), $this->action()->run($rule, $card, FactsMother::facts(), $state));

        $this->em()->getConnection()->executeStatement('DELETE FROM work_requests');
        $state->fires = 3;
        $outcome = $this->action()->run($rule, $card, FactsMother::facts(), $state);

        self::assertEquals(ActionOutcome::pause(CardPauseKind::WorkLimit, 'work-limit-reached'), $outcome);
        self::assertSame([], $this->service(WorkRequestRepository::class)->findLiveForCard($card->id ?? throw new \LogicException('A flushed card has an id.')));
    }

    private function action(): RequestWork
    {
        return new RequestWork(new WorkRequestOpener($this->openWorkRequestHandler()));
    }
}
