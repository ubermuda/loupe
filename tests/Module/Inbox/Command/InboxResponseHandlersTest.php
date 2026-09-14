<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Inbox\Command\AnswerInboxItemCommand;
use App\Module\Inbox\Command\AnswerInboxItemHandler;
use App\Module\Inbox\Command\DeclineInboxItemCommand;
use App\Module\Inbox\Command\DeclineInboxItemHandler;
use App\Module\Inbox\Command\MarkInboxItemDoneCommand;
use App\Module\Inbox\Command\MarkInboxItemDoneHandler;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InboxResponseHandlersTest extends KernelTestCase
{
    use InboxScenario;

    private EntityManagerInterface $em;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->project = $this->inboxProject($em, $this->signedUpUser($em, 'inbox-handlers'));
    }

    public function test_an_answer_closes_the_question_with_sorted_distinct_options_and_text(): void
    {
        $item = $this->question($this->em, $this->project, 1, ['a', 'b', 'c'], multiple: true, freeText: true);

        $this->answer($item, ' 2, 0,2 ', '  Both, please.  ');

        $this->em->clear();
        $stored = $this->reload($item);
        self::assertSame(InboxItemState::Answered, $stored->state);
        self::assertSame([0, 2], $stored->selectedOptions);
        self::assertSame('Both, please.', $stored->answerText);
        self::assertNotNull($stored->closedAt);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function refusedAnswers(): iterable
    {
        yield 'an index past the options' => ['2', '', 'selectedOptions', 'inbox.answer.error.unknown_option'];
        yield 'a word for an index' => ['first', '', 'selectedOptions', 'inbox.answer.error.unknown_option'];
        yield 'two options on a single-choice question' => ['0,1', '', 'selectedOptions', 'inbox.answer.error.one_option'];
        yield 'text on a question that takes none' => ['0', 'extra', 'answerText', 'inbox.answer.error.no_free_text'];
        yield 'nothing at all' => ['', '   ', 'selectedOptions', 'inbox.answer.error.empty'];
    }

    #[DataProvider('refusedAnswers')]
    public function test_an_answer_the_question_does_not_take_is_refused(string $selected, string $text, string $field, string $key): void
    {
        $item = $this->question($this->em, $this->project, 1);

        $errors = $this->refusal(fn () => $this->answer($item, $selected, $text));

        self::assertSame([$field => $key], $errors);
        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    public function test_a_to_do_takes_no_answer_and_a_question_is_not_marked_done(): void
    {
        $todo = $this->todo($this->em, $this->project, 1);
        $question = $this->question($this->em, $this->project, 2);

        self::assertSame(['selectedOptions' => 'inbox.answer.error.not_a_question'], $this->refusal(fn () => $this->answer($todo, '0', '')));
        self::assertSame(['item' => 'inbox.done.error.not_a_todo'], $this->refusal(fn () => $this->markDone($question)));
    }

    public function test_an_answer_changes_while_no_closed_ask_holds_the_item_and_keeps_its_close_time(): void
    {
        $item = $this->question($this->em, $this->project, 1);
        $this->askHolding($this->em, $this->project, [$item]);
        $this->answered($this->em, $item);
        $closedAt = $item->closedAt;

        $this->answer($item, '1', '');

        $this->em->clear();
        $stored = $this->reload($item);
        self::assertSame([1], $stored->selectedOptions);
        // The column keeps whole seconds.
        self::assertSame($closedAt?->format('Y-m-d H:i:s'), $stored->closedAt?->format('Y-m-d H:i:s'));
    }

    public function test_an_answer_is_final_once_an_ask_that_holds_the_item_closes(): void
    {
        $item = $this->question($this->em, $this->project, 1);
        $this->askHolding($this->em, $this->project, [$item]);
        $this->askHolding($this->em, $this->project, [$item], closedAt: new \DateTimeImmutable());
        $this->answered($this->em, $item);

        self::assertSame(['selectedOptions' => 'inbox.item.error.final'], $this->refusal(fn () => $this->answer($item, '1', '')));
        self::assertSame(['closeNote' => 'inbox.item.error.final'], $this->refusal(fn () => $this->decline($item, 'never mind')));

        $this->em->clear();
        self::assertSame([0], $this->reload($item)->selectedOptions);
    }

    public function test_an_open_item_in_a_closed_ask_still_takes_its_first_response(): void
    {
        $todo = $this->todo($this->em, $this->project, 1);
        $this->askHolding($this->em, $this->project, [$todo], closedAt: new \DateTimeImmutable());

        $this->markDone($todo);

        $this->em->clear();
        self::assertSame(InboxItemState::Done, $this->reload($todo)->state);
    }

    public function test_an_item_the_agent_closed_takes_no_response(): void
    {
        $item = $this->todo($this->em, $this->project, 1);
        $this->answered($this->em, $item, InboxItemState::Withdrawn);

        self::assertSame(['item' => 'inbox.item.error.closed_by_agent'], $this->refusal(fn () => $this->markDone($item)));
    }

    public function test_a_decline_clears_an_earlier_answer_and_keeps_the_note(): void
    {
        $item = $this->question($this->em, $this->project, 1, freeText: true);
        $this->answer($item, '0', 'JSON');

        $this->decline($item, '  Not sure what you mean.  ');

        $this->em->clear();
        $stored = $this->reload($item);
        self::assertSame(InboxItemState::Declined, $stored->state);
        self::assertSame([], $stored->selectedOptions);
        self::assertNull($stored->answerText);
        self::assertSame('Not sure what you mean.', $stored->closeNote);
    }

    public function test_a_decline_from_a_stale_copy_clears_an_answer_another_request_stored(): void
    {
        $item = $this->question($this->em, $this->project, 1, freeText: true);
        // Another request answers and commits after this one loaded the item.
        $this->em->getConnection()->executeStatement(
            "UPDATE inbox_items SET state = 'answered', selected_options = '[1]', answer_text = 'CSV', closed_at = NOW() WHERE id = ?",
            [(string) $item->id],
        );

        $this->decline($item, 'Changed my mind.');

        $this->em->clear();
        $stored = $this->reload($item);
        self::assertSame(InboxItemState::Declined, $stored->state);
        self::assertSame([], $stored->selectedOptions);
        self::assertNull($stored->answerText);
        self::assertSame('Changed my mind.', $stored->closeNote);
    }

    private function answer(InboxItem $item, string $selected, string $text): void
    {
        $handler = self::getContainer()->get(AnswerInboxItemHandler::class);
        self::assertInstanceOf(AnswerInboxItemHandler::class, $handler);
        $handler(new AnswerInboxItemCommand($item, $selected, $text));
    }

    private function markDone(InboxItem $item): void
    {
        $handler = self::getContainer()->get(MarkInboxItemDoneHandler::class);
        self::assertInstanceOf(MarkInboxItemDoneHandler::class, $handler);
        $handler(new MarkInboxItemDoneCommand($item));
    }

    private function decline(InboxItem $item, string $note): void
    {
        $handler = self::getContainer()->get(DeclineInboxItemHandler::class);
        self::assertInstanceOf(DeclineInboxItemHandler::class, $handler);
        $handler(new DeclineInboxItemCommand($item, $note));
    }

    /**
     * @param \Closure(): void $action
     *
     * @return array<string, string>
     */
    private function refusal(\Closure $action): array
    {
        try {
            $action();
        } catch (DomainErrors $e) {
            return $e->errors;
        }

        self::fail('The handler accepted a response it should refuse.');
    }

    private function reload(InboxItem $item): InboxItem
    {
        $stored = $this->em->find(InboxItem::class, $item->id);
        self::assertInstanceOf(InboxItem::class, $stored);

        return $stored;
    }
}
