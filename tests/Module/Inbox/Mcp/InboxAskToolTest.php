<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Mcp\InboxAskTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_two_spellings_of_one_id_link_the_record_once(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-ask-link-case');
        $card = $this->card($this->em, $project);
        $document = $this->document($this->em, $project);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->askQuestion('Which column?', [
            'cardIds' => [(string) $card->id, strtoupper((string) $card->id)],
            'documentIds' => [(string) $document->id, strtoupper((string) $document->id)],
        ]);

        $this->em->clear();
        $item = $this->em->find(InboxItem::class, Uuid::fromString($result['items'][0]['itemId']));
        self::assertInstanceOf(InboxItem::class, $item);
        self::assertCount(1, $item->cards);
        self::assertCount(1, $item->documents);
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

    public function test_an_upper_case_session_id_extends_the_same_ask(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-session-case'));
        $sessionId = (string) Uuid::v4();

        $first = $this->askQuestion('First', sessionId: $sessionId);
        $second = $this->askQuestion('Second', sessionId: strtoupper($sessionId));

        self::assertTrue($second['extended']);
        self::assertSame($first['askId'], $second['askId']);
    }

    public function test_a_later_call_fills_in_the_bridge_an_ask_had_none_for(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-bridge-fill'));
        $sessionId = (string) Uuid::v4();
        $bridgeId = (string) Uuid::v4();

        $first = $this->askQuestion('First', sessionId: $sessionId);
        ($this->tool)(sessionId: $sessionId, items: [['kind' => 'question', 'title' => 'Second', 'freeText' => true]], bridgeId: $bridgeId);

        $this->em->clear();
        $ask = $this->em->find(InboxAsk::class, Uuid::fromString($first['askId']));
        self::assertInstanceOf(InboxAsk::class, $ask);
        self::assertSame($bridgeId, (string) $ask->bridgeId);
    }

    public function test_a_different_bridge_for_an_open_ask_is_refused(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-bridge-mismatch'));
        $sessionId = (string) Uuid::v4();
        ($this->tool)(sessionId: $sessionId, items: [['kind' => 'question', 'title' => 'First', 'freeText' => true]], bridgeId: (string) Uuid::v4());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('bridgeId: This session\'s open ask already names another bridge.');
        ($this->tool)(sessionId: $sessionId, items: [['kind' => 'question', 'title' => 'Second', 'freeText' => true]], bridgeId: (string) Uuid::v4());
    }

    public function test_the_same_bridge_or_none_extends_an_ask_that_names_one(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-bridge-same'));
        $sessionId = (string) Uuid::v4();
        $bridgeId = (string) Uuid::v4();
        $first = ($this->tool)(sessionId: $sessionId, items: [['kind' => 'question', 'title' => 'First', 'freeText' => true]], bridgeId: $bridgeId);

        $same = ($this->tool)(sessionId: $sessionId, items: [['kind' => 'question', 'title' => 'Second', 'freeText' => true]], bridgeId: strtoupper($bridgeId));
        $none = $this->askQuestion('Third', sessionId: $sessionId);

        self::assertSame($first['askId'], $same['askId']);
        self::assertSame($first['askId'], $none['askId']);
    }

    public function test_a_title_on_two_lines_is_refused(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-title-lines'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('items[0].title: An item title must be one line.');
        $this->askQuestion("Which column?\nAnd why?");
    }

    public function test_duplicate_options_are_refused_after_trimming(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-options-duplicate'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('items[0].options: Each option must differ from the others.');
        $this->askQuestion('Which column?', ['options' => ['next', ' next ']]);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function oversizedItems(): iterable
    {
        yield 'too many options' => [['options' => array_map(static fn (int $n): string => 'option '.$n, range(1, 21))], 'items[0].options: A question takes at most 20 options.'];
        yield 'too many card ids' => [['cardIds' => array_fill(0, 21, (string) Uuid::v4())], 'items[0].cardIds: An item links at most 20 cards and 20 documents.'];
        yield 'too many document ids' => [['documentIds' => array_fill(0, 21, (string) Uuid::v4())], 'items[0].documentIds: An item links at most 20 cards and 20 documents.'];
        yield 'a body too long' => [['body' => str_repeat('a', 20001)], 'items[0].body: An item body must be at most 20000 characters.'];
        yield 'an option too long' => [['options' => ['yes', str_repeat('a', 501)]], 'items[0].options: An option must be at most 500 characters.'];
        yield 'an option too long in multibyte text' => [['options' => ['yes', str_repeat('é', 501)]], 'items[0].options: An option must be at most 500 characters.'];
    }

    public function test_an_option_at_the_limit_is_accepted_after_trimming(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-option-limit'));

        $result = $this->askQuestion('Which column?', ['options' => ['yes', ' '.str_repeat('é', 500).' ']]);

        self::assertSame(1, $result['items'][0]['number']);
    }

    /** @param array<string, mixed> $item */
    #[DataProvider('oversizedItems')]
    public function test_an_item_over_a_limit_is_refused(array $item, string $message): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-limits'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage($message);
        $this->askQuestion('Which column?', $item);
    }

    public function test_a_body_at_the_limit_is_accepted(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-body-limit'));

        $result = $this->askQuestion('Which column?', ['body' => str_repeat('a', 20000)]);

        self::assertSame(1, $result['items'][0]['number']);
    }

    public function test_more_than_twenty_items_are_refused(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-too-many'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('items: Pass at most 20 items in one call.');
        ($this->tool)(sessionId: (string) Uuid::v4(), items: array_fill(0, 21, ['kind' => 'todo', 'title' => 'Review']));
    }

    public function test_a_context_that_grows_past_the_limit_is_refused(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-context-limit'));
        $sessionId = (string) Uuid::v4();
        ($this->tool)(sessionId: $sessionId, items: [['kind' => 'question', 'title' => 'First', 'freeText' => true]], context: str_repeat('a', 6000));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('context: The context of an ask must be at most 10000 characters');
        ($this->tool)(sessionId: $sessionId, items: [['kind' => 'question', 'title' => 'Second', 'freeText' => true]], context: str_repeat('b', 4000));
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
