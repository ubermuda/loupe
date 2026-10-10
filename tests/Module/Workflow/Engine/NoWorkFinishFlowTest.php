<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Engine;

use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Bridge\Command\SettleWorkRequestCommand;
use App\Module\Bridge\Command\SettleWorkRequestHandler;
use App\Module\Bridge\Command\WithdrawWorkRequestCommand;
use App\Module\Bridge\Command\WithdrawWorkRequestHandler;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\Service\WorkRequestAnnouncer;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\WorkSubject\WorkSubjectHandlers;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Engine\Engine;
use App\Outbox\OutboxWriter;
use App\Tests\Module\Workflow\Action\ActionScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;

/** The shipped Lifecycle rules, the real engine and the real settle, from an implement run with no code to the terminal column. */
final class NoWorkFinishFlowTest extends KernelTestCase
{
    use ActionScenario;

    #[DataProvider('reasons')]
    public function test_an_implement_run_that_finishes_with_no_code_moves_the_card_to_the_terminal_column(string $reason): void
    {
        $card = $this->implementingCard();

        $this->settle($this->openImplement($card), $reason, '2026-10-02 12:30:00');
        $this->evaluate($card, '2026-10-02 12:31:00');

        $card = $this->fresh($card);
        self::assertTrue($card->column->terminal);
        $moves = array_values(array_filter(
            $this->service(CardEventRepository::class)->findForCard($card),
            static fn ($event): bool => CardEventKind::Moved === $event->kind,
        ));
        self::assertCount(1, $moves);
        self::assertSame(Actor::System, $moves[0]->actorKind);
        self::assertEquals(['type' => 'workflow-rule', 'rule' => $reason], $moves[0]->detail['cause']);
    }

    /** @return iterable<string, array{string}> */
    public static function reasons(): iterable
    {
        yield 'nothing to build' => ['nothing-to-build'];
        yield 'delivered without code' => ['delivered-without-code'];
    }

    public function test_a_card_that_a_person_moves_back_opens_a_new_implement_request_and_stays(): void
    {
        $card = $this->movedBack();

        self::assertSame('in-progress', $card->column->slug);
        self::assertSame([WorkRequestState::Done, WorkRequestState::Open], array_map(static fn (WorkRequest $request): WorkRequestState => $request->state, $this->implements($card)));
    }

    #[DataProvider('withdrawals')]
    public function test_a_card_that_came_back_stays_when_its_new_implement_request_is_withdrawn(WorkRequestState $withdrawal): void
    {
        $card = $this->movedBack();
        $request = $this->implements($card)[1];

        self::assertTrue($this->service(WithdrawWorkRequestHandler::class)(new WithdrawWorkRequestCommand($request->id ?? throw new \LogicException('A flushed request has an id.'), $withdrawal)));
        $this->evaluate($card, '2026-10-02 13:10:00');
        $this->evaluate($card, '2026-10-02 13:11:00');

        self::assertSame('in-progress', $this->fresh($card)->column->slug);
    }

    /** @return iterable<string, array{WorkRequestState}> */
    public static function withdrawals(): iterable
    {
        yield 'cancelled' => [WorkRequestState::Cancelled];
        yield 'expired' => [WorkRequestState::Expired];
    }

    #[DataProvider('ordinaryReasons')]
    public function test_an_implement_run_that_finishes_for_another_reason_leaves_the_card(?string $reason): void
    {
        $card = $this->implementingCard();

        $this->settle($this->openImplement($card), $reason, '2026-10-02 12:30:00');
        $this->evaluate($card, '2026-10-02 12:31:00');

        $card = $this->fresh($card);
        self::assertSame('in-progress', $card->column->slug);
    }

    /** @return iterable<string, array{?string}> */
    public static function ordinaryReasons(): iterable
    {
        yield 'done' => ['done'];
        yield 'no reason' => [null];
    }

    private function implementingCard(): Card
    {
        self::bootKernel();
        $project = $this->workflowProject('no-work-finish');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'in-progress');
        $this->evaluate($card, '2026-10-02 12:00:00');

        return $card;
    }

    /** A card whose implement run found nothing to build, which a person then moved back to implementation. */
    private function movedBack(): Card
    {
        $card = $this->implementingCard();
        $this->settle($this->openImplement($card), 'nothing-to-build', '2026-10-02 12:30:00');
        $this->evaluate($card, '2026-10-02 12:31:00');
        $this->evaluate($card, '2026-10-02 12:32:00');
        $card = $this->fresh($card);
        self::assertTrue($card->column->terminal);

        $this->service(UpdateCardHandler::class)(new UpdateCardCommand(card: $card, actor: Actor::Human, column: $this->column($card->project, 'in-progress'), unmanageBy: $card->project->owner));
        $this->service(CardHolds::class)->release($card->project, [$this->idOf($card)]);
        $this->evaluate($card, '2026-10-02 13:00:00');
        $this->evaluate($card, '2026-10-02 13:01:00');

        return $this->fresh($card);
    }

    /** @return list<WorkRequest> */
    private function implements(Card $card): array
    {
        return array_values(array_filter($this->requests($card), static fn (WorkRequest $request): bool => 'implement' === $request->kind));
    }

    /** The implement request the engine opened, claimed by a bridge. */
    private function openImplement(Card $card): WorkRequest
    {
        $implements = $this->implements($card);
        self::assertCount(1, $implements);
        $request = $implements[0];
        self::assertSame(WorkRequestState::Open, $request->state);
        $request->state = WorkRequestState::Claimed;
        $request->bridgeId = Uuid::v4();
        $request->claimToken = Uuid::v4();
        $request->leaseUntil = new \DateTimeImmutable('2026-10-02 14:00:00');
        $this->em()->flush();

        return $request;
    }

    private function settle(WorkRequest $request, ?string $reason, string $at): void
    {
        $handler = new SettleWorkRequestHandler(
            $this->service(WorkRequestRepository::class),
            $this->service(OutboxWriter::class),
            $this->em(),
            new MockClock($at),
            $this->service(Auditor::class),
            $this->service(WorkRequestAnnouncer::class),
            new WorkSubjectHandlers([]),
        );

        $result = $handler(new SettleWorkRequestCommand(
            owner: $request->project->owner,
            bridgeId: $request->bridgeId ?? throw new \LogicException('A claimed request has a bridge.'),
            workRequestId: $request->id ?? throw new \LogicException('A flushed request has an id.'),
            claimToken: $request->claimToken ?? throw new \LogicException('A claimed request has a token.'),
            state: WorkRequestState::Done,
            reason: $reason,
        ));
        self::assertTrue($result->settled);
    }

    private function evaluate(Card $card, string $at): void
    {
        $this->service(Engine::class)->evaluate($this->idOf($card), new \DateTimeImmutable($at));
    }

    /** @return list<WorkRequest> */
    private function requests(Card $card): array
    {
        return array_values($this->service(WorkRequestRepository::class)->findBy(['subjectId' => $card->id], ['createdAt' => 'ASC', 'id' => 'ASC']));
    }

    private function fresh(Card $card): Card
    {
        $this->em()->clear();

        return $this->em()->find(Card::class, $this->idOf($card)) ?? throw new \LogicException('The card stays stored.');
    }

    private function idOf(Card $card): Uuid
    {
        return $card->id ?? throw new \LogicException('A flushed card has an id.');
    }
}
