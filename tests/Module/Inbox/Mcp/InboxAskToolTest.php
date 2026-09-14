<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Mcp\InboxAskTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class InboxAskToolTest extends KernelTestCase
{
    use InboxToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private InboxAskTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->tool = $this->askTool();
    }

    public function test_the_tool_refuses_while_the_flag_is_off(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-flag-off'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The inbox is switched off on this instance.');
        $this->askQuestion('Which column?');
    }

    public function test_an_ask_opens_with_numbered_items_and_the_default_blocking_of_each_kind(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-ask');
        $this->actAsMcpTokenBoundTo($project);
        $sessionId = (string) Uuid::v4();
        $bridgeId = (string) Uuid::v4();

        $result = ($this->tool)(
            sessionId: $sessionId,
            items: [
                ['kind' => 'question', 'title' => '  Which column?  ', 'options' => ['next', 'done'], 'body' => 'The detail'],
                ['kind' => 'todo', 'title' => 'Review pull request 482'],
            ],
            bridgeId: $bridgeId,
            context: 'Working on card 33',
        );

        self::assertFalse($result['extended']);
        self::assertFalse($result['closed']);
        self::assertSame([1, 2], array_column($result['items'], 'number'));
        self::assertSame(['question', 'todo'], array_column($result['items'], 'kind'));
        self::assertSame([true, false], array_column($result['items'], 'blocking'));
        self::assertSame('Which column?', $result['items'][0]['title']);
        self::assertSame(['open', 'open'], array_column($result['items'], 'state'));

        $this->em->clear();
        $ask = $this->em->find(InboxAsk::class, Uuid::fromString($result['askId']));
        self::assertInstanceOf(InboxAsk::class, $ask);
        self::assertSame($sessionId, (string) $ask->sessionId);
        self::assertSame($bridgeId, (string) $ask->bridgeId);
        self::assertSame('Working on card 33', $ask->context);
        self::assertCount(2, $ask->items);
        self::assertNull($ask->closedAt);
    }

    public function test_a_second_call_from_the_session_extends_its_open_ask(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-extend'));
        $sessionId = (string) Uuid::v4();

        $first = ($this->tool)(sessionId: $sessionId, items: [['kind' => 'question', 'title' => 'First', 'freeText' => true]], context: 'Before the migration');
        $second = ($this->tool)(sessionId: $sessionId, items: [['kind' => 'question', 'title' => 'Second', 'freeText' => true]], context: 'One more');

        self::assertTrue($second['extended']);
        self::assertSame($first['askId'], $second['askId']);
        self::assertSame(2, $second['items'][0]['number']);

        $this->em->clear();
        $ask = $this->em->find(InboxAsk::class, Uuid::fromString($first['askId']));
        self::assertInstanceOf(InboxAsk::class, $ask);
        self::assertCount(2, $ask->items);
        self::assertSame("Before the migration\n\nOne more", $ask->context);
    }

    public function test_an_ask_with_no_blocking_item_closes_at_once_and_its_items_stay_open(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-no-blocking'));
        $sessionId = (string) Uuid::v4();

        $result = ($this->tool)(sessionId: $sessionId, items: [
            ['kind' => 'todo', 'title' => 'Review pull request 482'],
            ['kind' => 'question', 'title' => 'Nice to know', 'freeText' => true, 'blocking' => false],
        ]);

        self::assertTrue($result['closed']);
        self::assertSame(['open', 'open'], array_column($result['items'], 'state'));

        // The closed ask frees the session, so its next call opens a new ask.
        $next = $this->askQuestion('Which column?', sessionId: $sessionId);
        self::assertFalse($next['extended']);
        self::assertNotSame($result['askId'], $next['askId']);
    }

    public function test_a_to_do_that_blocks_keeps_its_ask_open(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-blocking-todo'));

        $result = ($this->tool)(sessionId: (string) Uuid::v4(), items: [['kind' => 'todo', 'title' => 'Merge first', 'blocking' => true]]);

        self::assertFalse($result['closed']);
        self::assertTrue($result['items'][0]['blocking']);
    }

    public function test_cards_and_documents_of_the_project_are_linked(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-ask-links');
        $card = $this->card($this->em, $project);
        $document = $this->document($this->em, $project);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->askQuestion('Which column?', ['cardIds' => [(string) $card->id], 'documentIds' => [(string) $document->id]]);

        $this->em->clear();
        $item = $this->em->find(InboxItem::class, Uuid::fromString($result['items'][0]['itemId']));
        self::assertInstanceOf(InboxItem::class, $item);
        self::assertSame([(string) $card->id], array_map(static fn ($link): string => (string) $link->card->id, $item->cards->toArray()));
        self::assertSame([(string) $document->id], array_map(static fn ($link): string => (string) $link->document->id, $item->documents->toArray()));
    }

    public function test_a_card_of_another_project_is_refused(): void
    {
        $this->enableInbox();
        $theirs = $this->makeProject('inbox-ask-their-card');
        $card = $this->card($this->em, $theirs);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-my-card'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('items[0].cardIds: Every card id must name a card of this project.');
        $this->askQuestion('Which column?', ['cardIds' => [(string) $card->id]]);
    }

    public function test_a_document_of_another_project_is_refused(): void
    {
        $this->enableInbox();
        $theirs = $this->makeProject('inbox-ask-their-document');
        $document = $this->document($this->em, $theirs);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-my-document'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('items[0].documentIds: Every document id must name a document of this project.');
        $this->askQuestion('Which column?', ['documentIds' => [(string) $document->id]]);
    }

    public function test_a_session_with_an_open_ask_in_another_project_is_refused(): void
    {
        $this->enableInbox();
        $sessionId = (string) Uuid::v4();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-first-project'));
        $this->askQuestion('Which column?', sessionId: $sessionId);

        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-second-project'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('sessionId: This session already has an open ask in another project.');
        $this->askQuestion('Which column?', sessionId: $sessionId);
    }

    public function test_a_question_the_owner_cannot_answer_is_refused(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-unanswerable'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('items[0].options: A question needs options, freeText, or both');
        ($this->tool)(sessionId: (string) Uuid::v4(), items: [['kind' => 'question', 'title' => 'Well?']]);
    }

    public function test_a_to_do_with_options_is_refused(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-todo-options'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('items[0].options: A todo is marked done or declined');
        ($this->tool)(sessionId: (string) Uuid::v4(), items: [['kind' => 'todo', 'title' => 'Review', 'options' => ['a']]]);
    }

    public function test_a_blank_title_is_reported_as_a_sentence(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-blank'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('items[0].title: An item title must not be blank.');
        $this->askQuestion('   ');
    }

    public function test_an_unknown_kind_names_the_ones_that_work(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-kind'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('items[0].kind: unknown kind "task". Use one of: question, todo.');
        ($this->tool)(sessionId: (string) Uuid::v4(), items: [['kind' => 'task', 'title' => 'Do it']]);
    }

    public function test_a_malformed_session_id_is_reported_rather_than_fatal(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-session'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"not-a-uuid" is not a valid session ID.');
        $this->askQuestion('Which column?', sessionId: 'not-a-uuid');
    }

    public function test_an_empty_list_of_items_is_refused(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-empty'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('items: Pass at least one item.');
        ($this->tool)(sessionId: (string) Uuid::v4(), items: []);
    }
}
