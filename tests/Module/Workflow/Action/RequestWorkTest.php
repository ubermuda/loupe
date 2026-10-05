<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Action;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardPauseKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Workflow\Action\ActionOutcome;
use App\Module\Workflow\Action\RequestWork;
use App\Module\Workflow\Action\WorkRequestOpener;
use App\Module\Workflow\Contract\ChecksState;
use App\Module\Workflow\Service\CardPullRequests;
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

    public function test_a_live_request_of_the_kind_is_already_live_and_opens_no_second_one(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('request-live'), 'next');
        $rule = $this->rule(ActionType::Request, ['kind' => 'fix']);

        $this->action()->run($rule, $card, FactsMother::facts(), $this->state($card));
        $outcome = $this->action()->run($rule, $card, FactsMother::facts(), $this->state($card));

        self::assertEquals(ActionOutcome::alreadyLive(), $outcome);
        self::assertNotEquals(ActionOutcome::done(), $outcome);
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

    /** @return iterable<string, array{bool, ChecksState, bool, string}> */
    public static function fixReasons(): iterable
    {
        yield 'conflict first' => [true, ChecksState::Failed, true, 'conflict'];
        yield 'failed checks' => [false, ChecksState::Failed, true, 'checks-failed'];
        yield 'changes requested' => [false, ChecksState::Passed, true, 'changes-requested'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fixReasons')]
    public function test_a_fix_request_records_a_fix_requested_card_event(bool $conflicting, ChecksState $checks, bool $changesRequested, string $reason): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('request-fix-event'), 'in-progress');
        $pullRequest = $this->pullRequest($card);
        $rule = $this->rule(ActionType::Request, ['kind' => 'fix', 'limit' => 3]);
        $facts = FactsMother::facts(pullRequest: FactsMother::pullRequest(checks: $checks, conflicting: $conflicting, changesRequested: $changesRequested));

        $this->action()->run($rule, $card, $facts, $this->state($card));
        $this->em()->flush();

        self::assertSame([['reason' => $reason, 'pullRequest' => $pullRequest->number]], $this->fixEvents($card));
    }

    public function test_a_live_fix_request_and_another_kind_record_no_fix_event(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('request-fix-none'), 'in-progress');
        $this->pullRequest($card);
        $facts = FactsMother::facts(pullRequest: FactsMother::pullRequest(checks: ChecksState::Failed));

        $this->action()->run($this->rule(ActionType::Request, ['kind' => 'implement']), $card, $facts, $this->state($card));
        $this->action()->run($this->rule(ActionType::Request, ['kind' => 'fix']), $card, $facts, $this->state($card));
        $this->em()->flush();
        $this->action()->run($this->rule(ActionType::Request, ['kind' => 'fix']), $card, $facts, $this->state($card));
        $this->em()->flush();

        self::assertCount(1, $this->fixEvents($card));
    }

    /** @return list<array<mixed>> the detail of each fix-requested event of the card */
    private function fixEvents(Card $card): array
    {
        $rows = $this->service(CardEventRepository::class)->findKindsOfCards($card->project, [$card->id ?? throw new \LogicException('A flushed card has an id.')], [CardEventKind::FixRequested]);

        return array_values(array_map(static fn (array $row): array => $row['detail'], array_filter($rows, static fn (array $row): bool => CardEventKind::FixRequested === $row['kind'])));
    }

    private function action(): RequestWork
    {
        return new RequestWork(new WorkRequestOpener($this->openWorkRequestHandler()), $this->service(CardPullRequests::class), $this->service(CardEventRepository::class));
    }
}
