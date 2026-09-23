<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Exception\DomainErrors;
use App\Module\Board\Command\DeleteBoardColumnCommand;
use App\Module\Board\Command\DeleteBoardColumnHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Inbox\Command\AnswerInboxItemCommand;
use App\Module\Inbox\Command\AnswerInboxItemHandler;
use App\Module\Inbox\Command\AskInboxCommand;
use App\Module\Inbox\Command\AskInboxHandler;
use App\Module\Inbox\Command\AskInboxItem;
use App\Module\Inbox\Command\DeclineInboxItemCommand;
use App\Module\Inbox\Command\DeclineInboxItemHandler;
use App\Module\Inbox\Command\MarkInboxItemDoneCommand;
use App\Module\Inbox\Command\MarkInboxItemDoneHandler;
use App\Module\Inbox\Command\WithdrawInboxItemCommand;
use App\Module\Inbox\Command\WithdrawInboxItemHandler;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\InboxEventType;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Service\InboxItemCloser;
use App\Module\Project\Entity\Project;
use App\Outbox\AgentPush;
use App\Outbox\Command\DrainOutboxCommand;
use App\Outbox\Command\DrainOutboxHandler;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** Driven through the real handlers, so each closing path runs where production calls it. */
final class InboxAskCloserTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->project = $this->project($em, $this->owner($em, 'ask-closer'), 'ask-closer');
        $em->flush();
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);
    }

    public function test_answering_the_last_blocking_item_closes_the_ask_and_writes_one_event(): void
    {
        $first = $this->question(1);
        $second = $this->question(2);
        $todo = $this->todo(3);
        $ask = $this->askHolding([$first, $second, $todo]);

        $this->answer($first);
        self::assertNull($this->reloadAsk($ask)->closedAt);
        self::assertSame([], $this->askClosedEvents());

        $this->answer($this->reloadItem($second));

        self::assertNotNull($this->reloadAsk($ask)->closedAt);
        self::assertSame(InboxItemState::Open, $this->reloadItem($todo)->state);
        self::assertSame([[
            'type' => 'inbox.ask_closed',
            'projectId' => (string) $this->project->id,
            'subject' => ['type' => 'inbox-ask', 'id' => (string) $ask->id],
            'sessionId' => (string) $ask->sessionId,
            'bridgeId' => (string) $ask->bridgeId,
            'cardId' => null,
            'cardNumber' => null,
            'actor' => 'human',
        ]], $this->askClosedEvents());
    }

    /** @return iterable<string, array{string, InboxItemKind}> */
    public static function humanResponses(): iterable
    {
        yield 'an answer' => ['answer', InboxItemKind::Question];
        yield 'a decline' => ['decline', InboxItemKind::Question];
        // A blocking to-do, so the done path has an item that holds the ask.
        yield 'a done' => ['markDone', InboxItemKind::Todo];
    }

    #[DataProvider('humanResponses')]
    public function test_every_owner_response_closes_the_ask_as_the_human(string $respond, InboxItemKind $kind): void
    {
        $item = InboxItemKind::Question === $kind ? $this->question(1) : $this->todo(1, blocking: true);
        $ask = $this->askHolding([$item]);

        $this->{$respond}($item);

        self::assertNotNull($this->reloadAsk($ask)->closedAt);
        self::assertSame(['human'], array_column($this->askClosedEvents(), 'actor'));
    }

    public function test_a_withdraw_of_the_last_blocking_item_closes_the_ask_as_the_agent(): void
    {
        $item = $this->question(1);
        $ask = $this->askHolding([$item]);

        $this->withdraw($item);

        self::assertNotNull($this->reloadAsk($ask)->closedAt);
        self::assertSame(['agent'], array_column($this->askClosedEvents(), 'actor'));
    }

    public function test_one_answer_closes_every_ask_it_leaves_with_no_open_blocking_item(): void
    {
        $shared = $this->question(1);
        $other = $this->question(2);
        $onlyShared = $this->askHolding([$shared]);
        $alsoOther = $this->askHolding([$shared, $other]);
        $third = $this->askHolding([$shared]);

        $this->answer($shared);

        self::assertNotNull($this->reloadAsk($onlyShared)->closedAt);
        self::assertNotNull($this->reloadAsk($third)->closedAt);
        self::assertNull($this->reloadAsk($alsoOther)->closedAt);
        self::assertEqualsCanonicalizing(
            [(string) $onlyShared->id, (string) $third->id],
            array_map(static fn (array $event): string => $event['subject']['id'], $this->askClosedEvents()),
        );
    }

    public function test_an_ask_with_no_bridge_closes_and_writes_no_event(): void
    {
        $item = $this->question(1);
        $ask = $this->askHolding([$item], bridgeId: null);

        $this->answer($item);

        self::assertNotNull($this->reloadAsk($ask)->closedAt);
        self::assertSame([], $this->askClosedEvents());
    }

    public function test_the_card_comes_from_the_oldest_run_of_the_session(): void
    {
        $card = $this->card($this->em, $this->project, 7);
        $later = $this->card($this->em, $this->project, 8);
        $this->em->flush();
        $item = $this->question(1);
        $ask = $this->askHolding([$item]);
        $this->workerRun($ask->sessionId, $later, new \DateTimeImmutable('2026-09-01 11:00:00'));
        $this->workerRun($ask->sessionId, $card, new \DateTimeImmutable('2026-09-01 10:00:00'));
        $this->workerRun(Uuid::v4(), $later, new \DateTimeImmutable('2026-09-01 09:00:00'));

        $this->answer($item);

        $closed = $this->reloadAsk($ask);
        self::assertSame((string) $card->id, (string) $closed->card?->id);
        self::assertCount(1, $this->askClosedEvents());
        $event = $this->askClosedEvents()[0];
        self::assertSame((string) $card->id, $event['cardId']);
        self::assertSame(7, $event['cardNumber']);
    }

    public function test_the_card_is_null_when_the_run_names_a_deleted_card(): void
    {
        $item = $this->question(1);
        $ask = $this->askHolding([$item]);
        $gone = $this->card($this->em, $this->project, 9);
        $this->em->flush();
        $this->workerRun($ask->sessionId, $gone, new \DateTimeImmutable('2026-09-01 10:00:00'));
        $this->em->remove($gone);
        $this->em->flush();

        $this->answer($item);

        self::assertNull($this->reloadAsk($ask)->card);
        self::assertCount(1, $this->askClosedEvents());
        $event = $this->askClosedEvents()[0];
        self::assertNull($event['cardId']);
        self::assertNull($event['cardNumber']);
    }

    public function test_a_run_of_another_project_gives_no_card(): void
    {
        $elsewhere = $this->project($this->em, $this->owner($this->em, 'ask-closer-other'), 'ask-closer-other');
        $foreignCard = $this->card($this->em, $elsewhere, 4);
        $this->em->flush();
        $item = $this->question(1);
        $ask = $this->askHolding([$item]);
        $this->workerRun($ask->sessionId, $foreignCard, new \DateTimeImmutable('2026-09-01 10:00:00'), $elsewhere);

        $this->answer($item);

        self::assertCount(1, $this->askClosedEvents());
        self::assertNull($this->askClosedEvents()[0]['cardId']);
    }

    public function test_a_run_of_this_project_that_names_another_projects_card_gives_no_card(): void
    {
        $elsewhere = $this->project($this->em, $this->owner($this->em, 'ask-closer-foreign'), 'ask-closer-foreign');
        $foreignCard = $this->card($this->em, $elsewhere, 4);
        $this->em->flush();
        $item = $this->question(1);
        $ask = $this->askHolding([$item]);
        $this->workerRun($ask->sessionId, $foreignCard, new \DateTimeImmutable('2026-09-01 10:00:00'));

        $this->answer($item);

        self::assertNull($this->reloadAsk($ask)->card);
        self::assertCount(1, $this->askClosedEvents());
        self::assertNull($this->askClosedEvents()[0]['cardId']);
        self::assertNull($this->askClosedEvents()[0]['cardNumber']);
    }

    public function test_the_event_is_written_while_agent_push_is_off_and_the_drain_holds_it(): void
    {
        $this->switchFlag($this->em, AgentPush::FLAG, false);
        $item = $this->question(1);
        $this->askHolding([$item]);

        $this->answer($item);
        self::assertCount(1, $this->askClosedEvents());

        $drain = self::getContainer()->get(DrainOutboxHandler::class);
        self::assertInstanceOf(DrainOutboxHandler::class, $drain);
        $drain(new DrainOutboxCommand());

        self::assertSame(
            [['published_at' => null, 'next_attempt_at' => null, 'publish_attempts' => 0]],
            $this->em->getConnection()->fetchAllAssociative(
                'SELECT published_at, next_attempt_at, publish_attempts FROM outbox_events WHERE project_id = ? AND type = ?',
                [(string) $this->project->id, InboxEventType::ASK_CLOSED],
            ),
        );
    }

    public function test_the_same_final_answer_sent_twice_is_refused_and_writes_one_event(): void
    {
        $item = $this->question(1);
        $this->askHolding([$item]);
        $this->answer($item);

        try {
            $this->answer($this->reloadItem($item));
            self::fail('A second answer to an item a closed ask holds must be refused.');
        } catch (DomainErrors $e) {
            self::assertSame(['selectedOptions' => InboxItemCloser::ERROR_FINAL], $e->errors);
        }

        self::assertCount(1, $this->askClosedEvents());
    }

    public function test_a_card_move_that_makes_the_items_obsolete_commits_the_move_and_both_rows(): void
    {
        $card = $this->card($this->em, $this->project, 5);
        $first = $this->linkedQuestion(1, $card);
        $second = $this->linkedQuestion(2, $card);
        // Closes after the ask does, in the same move.
        $todo = new InboxItem(project: $this->managedProject(), number: 3, kind: InboxItemKind::Todo, title: 'Tidy up', blocking: false);
        $todo->cards->add(new InboxItemCard($todo, $card));
        $this->em->persist($todo);
        $this->em->flush();
        $ask = $this->askHolding([$first, $second, $todo]);
        $this->workerRun($ask->sessionId, $card, new \DateTimeImmutable('2026-09-01 10:00:00'));
        $this->em->clear();

        $managedCard = $this->em->find(Card::class, $card->id);
        self::assertInstanceOf(Card::class, $managedCard);
        $handler = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $handler);
        $handler(new UpdateCardCommand($managedCard, CardReporter::Human, column: $this->column($managedCard->project, 'done')));

        $this->em->clear();
        $moved = $this->em->find(Card::class, $card->id);
        self::assertInstanceOf(Card::class, $moved);
        self::assertTrue($moved->column->terminal);
        self::assertSame(InboxItemState::Obsolete, $this->reloadItem($first)->state);
        self::assertSame(InboxItemState::Obsolete, $this->reloadItem($second)->state);
        self::assertSame(InboxItemState::Obsolete, $this->reloadItem($todo)->state);
        self::assertNotNull($this->reloadAsk($ask)->closedAt);
        self::assertSame(1, $this->countEvents('board.card_moved'));
        $events = $this->askClosedEvents();
        self::assertCount(1, $events);
        self::assertSame('agent', $events[0]['actor']);
        self::assertSame((string) $card->id, $events[0]['cardId']);
    }

    public function test_a_column_delete_that_makes_the_item_obsolete_commits_the_delete_and_both_rows(): void
    {
        $card = new Card(project: $this->managedProject(), column: $this->column($this->managedProject(), 'in-progress'), title: 'Moves', body: 'Body', number: 6);
        $this->em->persist($card);
        $item = $this->linkedQuestion(1, $card);
        $this->em->flush();
        $ask = $this->askHolding([$item]);
        $this->workerRun($ask->sessionId, $card, new \DateTimeImmutable('2026-09-01 10:00:00'));

        $handler = self::getContainer()->get(DeleteBoardColumnHandler::class);
        self::assertInstanceOf(DeleteBoardColumnHandler::class, $handler);
        $project = $this->managedProject();
        $handler(new DeleteBoardColumnCommand($this->column($project, 'in-progress'), CardReporter::Human, $this->column($project, 'done')));

        self::assertSame(InboxItemState::Obsolete, $this->reloadItem($item)->state);
        self::assertNotNull($this->reloadAsk($ask)->closedAt);
        self::assertSame(1, $this->countEvents('board.column_deleted'));
        $events = $this->askClosedEvents();
        self::assertCount(1, $events);
        self::assertSame('agent', $events[0]['actor']);
        self::assertSame((string) $card->id, $events[0]['cardId']);
        self::assertSame(6, $events[0]['cardNumber']);
    }

    public function test_a_card_move_or_a_column_delete_closes_nothing_while_the_inbox_is_off(): void
    {
        $moved = $this->card($this->em, $this->project, 7);
        $bulk = new Card(project: $this->managedProject(), column: $this->column($this->managedProject(), 'in-progress'), title: 'Moves', body: 'Body', number: 8);
        $this->em->persist($bulk);
        $first = $this->linkedQuestion(1, $moved);
        $second = $this->linkedQuestion(2, $bulk);
        $this->em->flush();
        $firstAsk = $this->askHolding([$first]);
        $secondAsk = $this->askHolding([$second]);
        $this->switchFlag($this->em, InboxInstallFlags::FLAG_INBOX_ENABLED, false);

        $move = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $move);
        $managedCard = $this->em->find(Card::class, $moved->id);
        self::assertInstanceOf(Card::class, $managedCard);
        $move(new UpdateCardCommand($managedCard, CardReporter::Human, column: $this->column($managedCard->project, 'done')));
        $delete = self::getContainer()->get(DeleteBoardColumnHandler::class);
        self::assertInstanceOf(DeleteBoardColumnHandler::class, $delete);
        $project = $this->managedProject();
        $delete(new DeleteBoardColumnCommand($this->column($project, 'in-progress'), CardReporter::Human, $this->column($project, 'done')));

        self::assertSame(1, $this->countEvents('board.card_moved'));
        self::assertSame(1, $this->countEvents('board.column_deleted'));
        self::assertSame(InboxItemState::Open, $this->reloadItem($first)->state);
        self::assertSame(InboxItemState::Open, $this->reloadItem($second)->state);
        self::assertNull($this->reloadAsk($firstAsk)->closedAt);
        self::assertNull($this->reloadAsk($secondAsk)->closedAt);
        self::assertSame([], $this->askClosedEvents());
    }

    public function test_a_to_do_closed_after_the_question_of_its_ask_writes_no_second_event(): void
    {
        $question = $this->question(1);
        $todo = $this->todo(2);
        $ask = $this->askHolding([$question, $todo]);
        $this->answer($question);
        $closedAt = $this->reloadAsk($ask)->closedAt;
        self::assertNotNull($closedAt);
        self::assertCount(1, $this->askClosedEvents());

        $this->markDone($this->reloadItem($todo));

        self::assertSame(InboxItemState::Done, $this->reloadItem($todo)->state);
        self::assertEquals($closedAt, $this->reloadAsk($ask)->closedAt);
        self::assertCount(1, $this->askClosedEvents());
    }

    public function test_closing_an_item_that_does_not_block_never_closes_an_ask(): void
    {
        // An open ask with no open blocking item: the close of its to-do must not
        // be what closes it, because only a blocking item holds an ask back.
        $question = $this->item($this->em, $this->managedProject(), 1, 'Question 1');
        $question->state = InboxItemState::Answered;
        $question->closedAt = new \DateTimeImmutable('-1 hour');
        $this->em->flush();
        $todo = $this->todo(2);
        $ask = $this->askHolding([$question, $todo]);

        $this->withdraw($todo);

        self::assertSame(InboxItemState::Withdrawn, $this->reloadItem($todo)->state);
        self::assertNull($this->reloadAsk($ask)->closedAt);
        self::assertSame([], $this->askClosedEvents());
    }

    public function test_a_closed_ask_never_closes_again_or_writes_a_second_event(): void
    {
        $question = $this->question(1);
        $todo = $this->todo(2);
        $ask = $this->askHolding([$question, $todo]);
        $this->answer($question);
        $closedAt = $this->reloadAsk($ask)->closedAt;
        self::assertNotNull($closedAt);

        $this->markDone($this->reloadItem($todo));
        $this->withdrawQuietly($this->question(3), $ask);

        self::assertEquals($closedAt, $this->reloadAsk($ask)->closedAt);
        self::assertCount(1, $this->askClosedEvents());
    }

    public function test_an_ask_that_closes_at_once_for_want_of_a_blocking_item_writes_no_event(): void
    {
        $handler = self::getContainer()->get(AskInboxHandler::class);
        self::assertInstanceOf(AskInboxHandler::class, $handler);

        $view = $handler(new AskInboxCommand(
            project: $this->project,
            sessionId: Uuid::v4(),
            items: [new AskInboxItem(kind: InboxItemKind::Todo, title: 'Review pull request 482')],
            bridgeId: Uuid::v4(),
        ));

        self::assertNotNull($view->ask->closedAt);
        self::assertSame([], $this->askClosedEvents());

        $this->markDone($this->reloadItem($view->items[0]));
        self::assertSame([], $this->askClosedEvents());
    }

    private function answer(InboxItem $item): void
    {
        $handler = self::getContainer()->get(AnswerInboxItemHandler::class);
        self::assertInstanceOf(AnswerInboxItemHandler::class, $handler);
        $handler(new AnswerInboxItemCommand($item, '0', ''));
    }

    private function decline(InboxItem $item): void
    {
        $handler = self::getContainer()->get(DeclineInboxItemHandler::class);
        self::assertInstanceOf(DeclineInboxItemHandler::class, $handler);
        $handler(new DeclineInboxItemCommand($item, 'Not sure what this is'));
    }

    private function markDone(InboxItem $item): void
    {
        $handler = self::getContainer()->get(MarkInboxItemDoneHandler::class);
        self::assertInstanceOf(MarkInboxItemDoneHandler::class, $handler);
        $handler(new MarkInboxItemDoneCommand($item));
    }

    private function withdraw(InboxItem $item): void
    {
        $handler = self::getContainer()->get(WithdrawInboxItemHandler::class);
        self::assertInstanceOf(WithdrawInboxItemHandler::class, $handler);
        $handler(new WithdrawInboxItemCommand($item, 'No longer needed'));
    }

    /** Puts an open blocking item into an ask already closed, then withdraws it. */
    private function withdrawQuietly(InboxItem $item, InboxAsk $closedAsk): void
    {
        $this->em->clear();
        $ask = $this->em->find(InboxAsk::class, $closedAsk->id);
        $managed = $this->em->find(InboxItem::class, $item->id);
        self::assertInstanceOf(InboxAsk::class, $ask);
        self::assertInstanceOf(InboxItem::class, $managed);
        $ask->items->add(new InboxAskItem($ask, $managed));
        $this->em->flush();

        $this->withdraw($managed);
    }

    private function question(int $number): InboxItem
    {
        $item = $this->item($this->em, $this->managedProject(), $number, 'Question '.$number);
        $this->em->flush();

        return $item;
    }

    private function todo(int $number, bool $blocking = false): InboxItem
    {
        $item = new InboxItem(project: $this->managedProject(), number: $number, kind: InboxItemKind::Todo, title: 'To-do '.$number, blocking: $blocking);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    private function linkedQuestion(int $number, Card $card): InboxItem
    {
        $item = $this->item($this->em, $this->project, $number, 'Question '.$number);
        $item->cards->add(new InboxItemCard($item, $card));

        return $item;
    }

    /** @param list<InboxItem> $items */
    private function askHolding(array $items, ?Uuid $bridgeId = new Uuid('0192f3a1-4b2c-7d3e-8f10-a2b3c4d5e6f7')): InboxAsk
    {
        $ask = new InboxAsk(project: $this->managedProject(), sessionId: Uuid::v4(), bridgeId: $bridgeId);
        foreach ($items as $item) {
            $ask->items->add(new InboxAskItem($ask, $item));
        }
        $this->em->persist($ask);
        $this->em->flush();

        return $ask;
    }

    private function workerRun(Uuid $sessionId, Card $card, \DateTimeImmutable $startedAt, ?Project $project = null): void
    {
        $this->em->persist(new WorkerRun(
            project: $project ?? $this->managedProject(),
            bridgeId: Uuid::v7(),
            sessionId: $sessionId,
            cardId: $card->id ?? throw new \LogicException('The card has no id.'),
            cardNumber: $card->number,
            ruleName: 'plan',
            state: WorkerRunState::Succeeded,
            startedAt: $startedAt,
            endedAt: $startedAt->modify('+5 minutes'),
            exitCode: 0,
        ));
        $this->em->flush();
    }

    private function managedProject(): Project
    {
        $project = $this->em->find(Project::class, $this->project->id);
        self::assertInstanceOf(Project::class, $project);

        return $project;
    }

    private function reloadAsk(InboxAsk $ask): InboxAsk
    {
        $this->em->clear();
        $fresh = $this->em->find(InboxAsk::class, $ask->id);
        self::assertInstanceOf(InboxAsk::class, $fresh);

        return $fresh;
    }

    private function reloadItem(InboxItem $item): InboxItem
    {
        $this->em->clear();
        $fresh = $this->em->find(InboxItem::class, $item->id);
        self::assertInstanceOf(InboxItem::class, $fresh);

        return $fresh;
    }

    /** @return list<array<string, mixed>> the decoded payloads, oldest first */
    private function askClosedEvents(): array
    {
        /** @var list<string> $payloads */
        $payloads = $this->em->getConnection()->fetchFirstColumn(
            'SELECT payload FROM outbox_events WHERE project_id = ? AND type = ? ORDER BY sequence',
            [(string) $this->project->id, InboxEventType::ASK_CLOSED],
        );

        return array_map(static fn (string $payload): array => json_decode($payload, true, flags: \JSON_THROW_ON_ERROR), $payloads);
    }

    private function countEvents(string $type): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM outbox_events WHERE project_id = ? AND type = ?',
            [(string) $this->project->id, $type],
        );
    }
}
