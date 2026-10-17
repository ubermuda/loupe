<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Mcp\InboxAskTool;
use App\Module\Inbox\Repository\InboxReviewRepository;
use App\Module\Inbox\Service\SessionPromptLimit;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

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

    public function test_the_tool_creates_an_explicit_document_review(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-ask-review');
        $document = new Document($project->owner, $project, 'Review the design');
        $document->addVersion('# Design', '<h1>Design</h1>');
        $this->em->persist($document);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->tool)(sessionId: (string) Uuid::v4(), items: [[
            'kind' => 'review',
            'title' => 'Review the design',
            'reviewDocumentId' => (string) $document->id,
        ]]);

        self::assertTrue($result['closed']);
        self::assertSame('review', $result['items'][0]['kind']);
        self::assertFalse($result['items'][0]['blocking']);
        $this->em->clear();
        $reviews = self::getContainer()->get(InboxReviewRepository::class);
        self::assertInstanceOf(InboxReviewRepository::class, $reviews);
        $review = $reviews->findOneBy(['item' => $result['items'][0]['itemId']]);
        self::assertNotNull($review);
        self::assertEquals($document->id, $review->document?->id);
        self::assertNull($review->verdict);
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
        $this->expectExceptionMessage('items[0].kind: unknown kind "task". Use one of: question, todo, review.');
        ($this->tool)(sessionId: (string) Uuid::v4(), items: [['kind' => 'task', 'title' => 'Do it']]);
    }

    public function test_an_agent_cannot_ask_a_wait_item(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-wait'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('items[0].kind: unknown kind "wait". Use one of: question, todo, review.');
        ($this->tool)(sessionId: (string) Uuid::v4(), items: [['kind' => 'wait', 'title' => 'Waiting', 'blocking' => true]]);
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

    public function test_suggested_actions_are_stored_and_read_back(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-ask-suggest');
        $card = $this->card($this->em, $project);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->askQuestion('Which column?', [
            'cardIds' => [(string) $card->id],
            'suggestedActions' => [
                ['action' => 'start-session', 'reason' => "Talk it\n  through", 'recommended' => true, 'cardId' => strtoupper((string) $card->id)],
                ['action' => 'open', 'reason' => 'See the card'],
            ],
        ]);

        $this->em->clear();
        $item = $this->em->find(InboxItem::class, Uuid::fromString($result['items'][0]['itemId']));
        self::assertInstanceOf(InboxItem::class, $item);
        self::assertSame([
            ['action' => 'start-session', 'reason' => 'Talk it through', 'recommended' => true, 'cardId' => (string) $card->id, 'command' => null, 'proposedEntry' => null, 'prompt' => null],
            ['action' => 'open', 'reason' => 'See the card', 'recommended' => false, 'cardId' => null, 'command' => null, 'proposedEntry' => null, 'prompt' => null],
        ], $item->suggestedActions);
    }

    public function test_a_start_session_prompt_is_stored_trimmed(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-prompt'));

        $result = $this->askTodo(null, ['action' => 'start-session', 'reason' => 'Talk', 'prompt' => "  Use the skill.\n"]);

        self::assertSame('Use the skill.', $this->storedSuggestions($result)[0]['prompt'] ?? null);
    }

    public function test_a_prompt_at_the_default_limit_is_accepted(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-prompt-limit'));

        $result = $this->askTodo(null, ['action' => 'start-session', 'reason' => 'Talk', 'prompt' => str_repeat('a', 2000)]);

        self::assertSame(2000, mb_strlen($this->storedSuggestions($result)[0]['prompt'] ?? ''));
    }

    public function test_the_flag_sets_the_longest_prompt(): void
    {
        $this->enableInbox();
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[SessionPromptLimit::FLAG]->value = 10;
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-prompt-flag'));

        $result = $this->askTodo(null, ['action' => 'start-session', 'reason' => 'Talk', 'prompt' => str_repeat('a', 10)]);
        self::assertSame(10, mb_strlen($this->storedSuggestions($result)[0]['prompt'] ?? ''));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('A suggestion prompt must be at most');
        $this->askTodo(null, ['action' => 'start-session', 'reason' => 'Talk', 'prompt' => str_repeat('a', 11)]);
    }

    public function test_an_item_without_suggestions_stores_none(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-no-suggest'));

        $result = $this->askQuestion('Which column?', ['suggestedActions' => []]);

        $this->em->clear();
        $item = $this->em->find(InboxItem::class, Uuid::fromString($result['items'][0]['itemId']));
        self::assertInstanceOf(InboxItem::class, $item);
        self::assertNull($item->suggestedActions);
    }

    /** @param array<string, mixed> $item */
    #[DataProvider('refusedSuggestions')]
    public function test_a_wrong_suggestion_is_refused(array $item, string $message): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-bad-suggest'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage($message);
        $this->askQuestion('Which column?', $item);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refusedSuggestions(): iterable
    {
        yield 'an unknown action' => [['suggestedActions' => [['action' => 'explode', 'reason' => 'Why not']]], 'items[0].suggestedActions: A suggested action must be one of: resume-run, retry-card, review, open, send-agent, start-session, run-command, dismiss.'];
        yield 'the owner-only add-entry action' => [['suggestedActions' => [['action' => 'add-entry', 'reason' => 'Why not']]], 'items[0].suggestedActions: A suggested action must be one of: resume-run, retry-card, review, open, send-agent, start-session, run-command, dismiss.'];
        yield 'a duplicate action' => [['suggestedActions' => [['action' => 'open', 'reason' => 'a'], ['action' => 'open', 'reason' => 'b']]], 'items[0].suggestedActions: Suggest each action once.'];
        yield 'two recommended actions' => [['suggestedActions' => [['action' => 'open', 'reason' => 'a', 'recommended' => true], ['action' => 'review', 'reason' => 'b', 'recommended' => true]]], 'items[0].suggestedActions: Mark at most one suggested action as recommended.'];
        yield 'a blank reason' => [['suggestedActions' => [['action' => 'open', 'reason' => '  ']]], 'items[0].suggestedActions: A suggested action needs a reason the owner reads.'];
        yield 'a long reason' => [['suggestedActions' => [['action' => 'open', 'reason' => str_repeat('a', 201)]]], 'items[0].suggestedActions: A suggestion reason must be at most 200 characters.'];
        yield 'a card the item does not link' => [['suggestedActions' => [['action' => 'open', 'reason' => 'a', 'cardId' => '0199a000-0000-7000-8000-000000000000']]], 'items[0].suggestedActions: The cardId of a suggested action must be one of the cardIds of the same item.'];
        yield 'four suggestions' => [['suggestedActions' => [['action' => 'open', 'reason' => 'a'], ['action' => 'review', 'reason' => 'a'], ['action' => 'dismiss', 'reason' => 'a'], ['action' => 'retry-card', 'reason' => 'a']]], 'items[0].suggestedActions: Suggest at most 3 actions on one item.'];
        yield 'an entry that is not an object' => [['suggestedActions' => ['open']], 'items[0].suggestedActions[0] must be an object.'];
        yield 'a prompt on another action' => [['suggestedActions' => [['action' => 'open', 'reason' => 'a', 'prompt' => 'Do it']]], 'items[0].suggestedActions: Only a start-session suggestion takes a prompt.'];
        yield 'a prompt over the default limit' => [['suggestedActions' => [['action' => 'start-session', 'reason' => 'a', 'prompt' => str_repeat('a', 2001)]]], 'items[0].suggestedActions: A suggestion prompt must be at most 2000 characters'];
        yield 'a prompt of spaces' => [['suggestedActions' => [['action' => 'start-session', 'reason' => 'a', 'prompt' => " \n "]]], 'items[0].suggestedActions: A suggestion prompt must not be blank.'];
        yield 'a prompt that is no string' => [['suggestedActions' => [['action' => 'start-session', 'reason' => 'a', 'prompt' => 5]]], 'items[0].suggestedActions[0].prompt must be a string.'];
        yield 'a reason that is not a string' => [['suggestedActions' => [['action' => 'open', 'reason' => 5]]], 'items[0].suggestedActions[0] needs a string action and a string reason.'];
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function oversizedItems(): iterable
    {
        yield 'too many options' => [['options' => array_map(static fn (int $n): string => 'option '.$n, range(1, 21))], 'items[0].options: A question takes at most 20 options.'];
        yield 'too many card ids' => [['cardIds' => array_fill(0, 21, (string) Uuid::v4())], 'items[0].cardIds: An item links at most 20 cards and 20 documents.'];
        yield 'too many document ids' => [['documentIds' => array_fill(0, 21, (string) Uuid::v4())], 'items[0].documentIds: An item links at most 20 cards and 20 documents.'];
        yield 'a body too long' => [['body' => str_repeat('a', 20001)], 'items[0].body: An item body must be at most 20000 characters.'];
        yield 'a title over the limit only with its spaces' => [['title' => ' '.str_repeat('a', 255)], 'items[0].title: An item title must be at most 255 characters.'];
        yield 'a body of spaces over the limit' => [['body' => str_repeat(' ', 20001)], 'items[0].body: An item body must be at most 20000 characters.'];
        yield 'an option too long' => [['options' => ['yes', str_repeat('a', 501)]], 'items[0].options: An option must be at most 500 characters.'];
        yield 'an option too long in multibyte text' => [['options' => ['yes', str_repeat('é', 501)]], 'items[0].options: An option must be at most 500 characters.'];
    }

    public function test_an_option_at_the_limit_is_accepted(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-option-limit'));

        $result = $this->askQuestion('Which column?', ['options' => ['yes', str_repeat('é', 500)]]);

        self::assertSame(1, $result['items'][0]['number']);
    }

    /** The schema measures the option as sent, so the handler does too, spaces included. */
    public function test_an_option_over_the_limit_only_with_its_spaces_is_refused(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-option-spaces'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('items[0].options: An option must be at most 500 characters.');
        $this->askQuestion('Which column?', ['options' => ['yes', ' '.str_repeat('a', 500)]]);
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

    /** The schema measures the context as sent, so the handler does too, spaces included. */
    public function test_a_context_over_the_limit_only_with_its_spaces_is_refused(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-context-spaces'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('context: The context of an ask must be at most 10000 characters');
        ($this->tool)(sessionId: (string) Uuid::v4(), items: [['kind' => 'question', 'title' => 'First', 'freeText' => true]], context: ' '.str_repeat('a', 10000));
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

    public function test_a_run_command_suggestion_on_a_to_do_is_stored_with_a_default_branch(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-ask-run-command');
        $bridge = $this->bridgeWithEntry($project->owner, $project);
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->askTodo($bridge, ['action' => 'run-command', 'reason' => 'Run the tests', 'command' => ['entry' => ' test ', 'params' => ['filter' => 'abc']]]);

        self::assertSame([[
            'action' => 'run-command',
            'reason' => 'Run the tests',
            'recommended' => false,
            'cardId' => null,
            'command' => ['entry' => 'test', 'branch' => 'main', 'params' => ['filter' => 'abc']],
            'proposedEntry' => null,
            'prompt' => null,
        ]], $this->storedSuggestions($result));
    }

    public function test_a_run_command_suggestion_keeps_its_proposed_entry_and_is_checked_by_it_without_a_bridge_entry(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-ask-run-command-proposed');
        $this->actAsMcpTokenBoundTo($project);
        $proposed = "action: command\nrun: [make, test]\nparams:\n  filter:\n    pattern: '[a-z]+'\n";

        $result = $this->askTodo(null, ['action' => 'run-command', 'reason' => 'Run', 'command' => ['entry' => 'lint', 'branch' => 'feature/x', 'params' => ['filter' => 'abc']], 'proposedEntry' => $proposed]);

        self::assertSame($proposed, $this->storedSuggestions($result)[0]['proposedEntry'] ?? null);
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('each value must match the pattern of its param whole');
        $this->askTodo(null, ['action' => 'run-command', 'reason' => 'Run', 'command' => ['entry' => 'lint', 'params' => ['filter' => 'ABC']], 'proposedEntry' => $proposed]);
    }

    public function test_a_run_command_suggestion_with_no_pattern_source_is_accepted_unchecked(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-ask-run-command-unchecked');
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->askTodo(null, ['action' => 'run-command', 'reason' => 'Run', 'command' => ['entry' => 'anything', 'params' => ['x' => 'any value']]]);

        self::assertSame(['x' => 'any value'], $this->storedSuggestions($result)[0]['command']['params'] ?? null);
    }

    public function test_a_bridge_of_another_owner_gives_no_patterns(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-ask-run-command-foreign');
        $other = $this->makeProject('inbox-ask-run-command-foreign-other');
        $foreign = $this->bridgeWithEntry($other->owner, $project);
        $this->actAsMcpTokenBoundTo($project);

        $result = $this->askTodo($foreign, ['action' => 'run-command', 'reason' => 'Run', 'command' => ['entry' => 'test', 'params' => ['filter' => 'NOT-LOWER-CASE']]]);

        self::assertCount(1, $this->storedSuggestions($result));
    }

    public function test_the_patterns_come_from_the_bridge_of_the_open_ask_of_the_session(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-ask-run-command-open-ask');
        $bridge = $this->bridgeWithEntry($project->owner, $project);
        $this->actAsMcpTokenBoundTo($project);
        $sessionId = (string) Uuid::v4();
        ($this->tool)(sessionId: $sessionId, items: [['kind' => 'question', 'title' => 'First', 'freeText' => true]], bridgeId: (string) $bridge->id);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('each value must match the pattern of its param whole');
        ($this->tool)(sessionId: $sessionId, items: [['kind' => 'todo', 'title' => 'Run it', 'suggestedActions' => [
            ['action' => 'run-command', 'reason' => 'Run', 'command' => ['entry' => 'test', 'params' => ['filter' => 'ABC']]],
        ]]]);
    }

    public function test_the_patterns_come_from_the_bridge_of_the_first_run_of_the_session(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-ask-run-command-first-run');
        $bridge = $this->bridgeWithEntry($project->owner, $project);
        $card = $this->card($this->em, $project);
        $sessionId = Uuid::v4();
        $this->em->persist(new WorkerRun(
            project: $project,
            bridgeId: $bridge->id,
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('The card has no id.'),
            cardNumber: $card->number,
            workKind: 'plan',
            state: WorkerRunState::Running,
            sessionId: $sessionId,
            startedAt: new \DateTimeImmutable(),
        ));
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('each value must match the pattern of its param whole');
        ($this->tool)(sessionId: (string) $sessionId, items: [['kind' => 'todo', 'title' => 'Run it', 'suggestedActions' => [
            ['action' => 'run-command', 'reason' => 'Run', 'command' => ['entry' => 'test', 'params' => ['filter' => 'ABC']]],
        ]]]);
    }

    /** @param array<string, mixed> $item */
    #[DataProvider('refusedRunCommands')]
    public function test_a_wrong_run_command_suggestion_is_refused(array $item, string $message): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-ask-run-command-bad');
        $bridge = $this->bridgeWithEntry($project->owner, $project);
        $this->actAsMcpTokenBoundTo($project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage($message);
        ($this->tool)(sessionId: (string) Uuid::v4(), items: [['kind' => 'todo', 'title' => 'Run it', ...$item]], bridgeId: (string) $bridge->id);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refusedRunCommands(): iterable
    {
        $suggest = static fn (array $extra): array => ['suggestedActions' => [['action' => 'run-command', 'reason' => 'Run', ...$extra]]];
        $command = ['entry' => 'test', 'params' => ['filter' => 'abc']];

        yield 'no command' => [$suggest([]), 'items[0].suggestedActions: A run-command suggestion needs a command with the name of the entry.'];
        yield 'a blank entry' => [$suggest(['command' => ['entry' => ' ']]), 'items[0].suggestedActions: A run-command suggestion needs a command with the name of the entry.'];
        yield 'a branch that is no git branch' => [$suggest(['command' => [...$command, 'branch' => 'a..b']]), 'items[0].suggestedActions: The branch of a command must be a git branch name of at most 200 characters.'];
        yield 'a value that fails its pattern' => [$suggest(['command' => ['entry' => 'test', 'params' => ['filter' => 'ABC']]]), 'items[0].suggestedActions: The params of a command must name each param of the entry once'];
        yield 'a param the entry does not declare' => [$suggest(['command' => ['entry' => 'test', 'params' => ['filter' => 'abc', 'other' => 'x']]]), 'items[0].suggestedActions: The params of a command must name each param of the entry once'];
        yield 'a declared param with no value' => [$suggest(['command' => ['entry' => 'test']]), 'items[0].suggestedActions: The params of a command must name each param of the entry once'];
        yield 'a proposed entry that is no YAML mapping' => [$suggest(['command' => $command, 'proposedEntry' => 'just text']), 'items[0].suggestedActions: proposedEntry must be YAML of at most 4000 characters'];
        yield 'a command on another action' => [['suggestedActions' => [['action' => 'open', 'reason' => 'a', 'command' => $command]]], 'items[0].suggestedActions: Only a run-command suggestion takes command and proposedEntry.'];
        yield 'a proposed entry on another action' => [['suggestedActions' => [['action' => 'open', 'reason' => 'a', 'proposedEntry' => 'action: command']]], 'items[0].suggestedActions: Only a run-command suggestion takes command and proposedEntry.'];
        yield 'a command that is no object' => [$suggest(['command' => 'test']), 'items[0].suggestedActions[0].command must be an object'];
        yield 'an entry that is no string' => [$suggest(['command' => ['entry' => 5]]), 'items[0].suggestedActions[0].command needs a string entry, and branch must be a string.'];
        yield 'params that are a list' => [$suggest(['command' => ['entry' => 'test', 'params' => ['abc']]]), 'items[0].suggestedActions[0].command.params must be an object'];
        yield 'a param value that is no string' => [$suggest(['command' => ['entry' => 'test', 'params' => ['filter' => 5]]]), 'items[0].suggestedActions[0].command.params.filter must be a string.'];
        yield 'a proposed entry that is no string' => [$suggest(['command' => $command, 'proposedEntry' => 5]), 'items[0].suggestedActions[0].proposedEntry must be a string of YAML.'];
    }

    public function test_a_question_cannot_suggest_run_command(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-ask-run-command-question'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('items[0].suggestedActions: Only a todo can suggest run-command.');
        $this->askQuestion('Which column?', ['suggestedActions' => [['action' => 'run-command', 'reason' => 'Run', 'command' => ['entry' => 'test']]]]);
    }

    public function test_a_review_cannot_suggest_run_command(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-ask-run-command-review');
        $document = new Document($project->owner, $project, 'Review the design');
        $document->addVersion('# Design', '<h1>Design</h1>');
        $this->em->persist($document);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('items[0].suggestedActions: Only a todo can suggest run-command.');
        ($this->tool)(sessionId: (string) Uuid::v4(), items: [[
            'kind' => 'review',
            'title' => 'Review the design',
            'reviewDocumentId' => (string) $document->id,
            'suggestedActions' => [['action' => 'run-command', 'reason' => 'Run', 'command' => ['entry' => 'test']]],
        ]]);
    }

    /**
     * @param array<string, mixed> $suggestion
     *
     * @return array{askId: string, extended: bool, closed: bool, items: list<array{itemId: string, number: int, kind: string, origin: string, title: string, state: string, blocking: bool, createdAt: string, updatedAt: string, closedAt: ?string}>}
     */
    private function askTodo(?Bridge $bridge, array $suggestion): array
    {
        return ($this->tool)(
            sessionId: (string) Uuid::v4(),
            items: [['kind' => 'todo', 'title' => 'Run it', 'suggestedActions' => [$suggestion]]],
            bridgeId: null === $bridge ? null : (string) $bridge->id,
        );
    }

    private function bridgeWithEntry(User $owner, Project $project): Bridge
    {
        $bridge = new Bridge($owner, Uuid::v4(), [(string) $project->id], 'b4e39aa7', new \DateTimeImmutable());
        $bridge->commandEntries = [['name' => 'test', 'description' => null, 'params' => [['name' => 'filter', 'pattern' => '[a-z]+', 'description' => null]], 'run' => ['make', 'test'], 'timeout' => '5m']];
        $this->em->persist($bridge);
        $this->em->flush();

        return $bridge;
    }

    /**
     * @param array{items: list<array{itemId: string}>} $result
     *
     * @return list<array<string, mixed>>
     */
    private function storedSuggestions(array $result): array
    {
        $this->em->clear();
        $item = $this->em->find(InboxItem::class, Uuid::fromString($result['items'][0]['itemId']));
        self::assertInstanceOf(InboxItem::class, $item);

        return $item->suggestedActions ?? [];
    }
}
