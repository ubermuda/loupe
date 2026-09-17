<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\AddBoardColumnCommand;
use App\Module\Board\Command\AddBoardColumnHandler;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\DeleteBoardColumnCommand;
use App\Module\Board\Command\DeleteBoardColumnHandler;
use App\Module\Board\Command\PreviewBoardColumnRenameCommand;
use App\Module\Board\Command\PreviewBoardColumnRenameHandler;
use App\Module\Board\Command\RenameBoardColumnCommand;
use App\Module\Board\Command\RenameBoardColumnHandler;
use App\Module\Board\Command\ReorderBoardColumnsCommand;
use App\Module\Board\Command\ReorderBoardColumnsHandler;
use App\Module\Board\Command\SetBoardColumnTerminalCommand;
use App\Module\Board\Command\SetBoardColumnTerminalHandler;
use App\Module\Board\Command\SetDefaultBoardColumnCommand;
use App\Module\Board\Command\SetDefaultBoardColumnHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Service\BoardColumns;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BoardColumnHandlersTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private RecordingAuditor $audit;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        // Before the handlers are fetched: the container hands the replacement
        // only to what it builds afterwards.
        $this->audit = RecordingAuditor::installedIn(self::getContainer());

        $owner = new User(fullName: 'Riley', email: 'board-columns-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'board-columns-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->flush();
    }

    public function test_an_added_column_goes_after_the_last_and_takes_the_slug_of_its_label(): void
    {
        $column = $this->handler(AddBoardColumnHandler::class)(new AddBoardColumnCommand($this->project, "  Won't do  "));

        self::assertSame("Won't do", $column->label);
        self::assertSame('won-t-do', $column->slug);
        self::assertSame(4, $column->position);
        self::assertFalse($column->terminal);
        self::assertFalse($column->isDefault);
        self::assertSame(['backlog', 'next', 'in-progress', 'done', 'won-t-do'], $this->slugs());

        self::assertSame(
            ['columnId' => (string) $column->id, 'projectId' => (string) $this->project->id, 'slug' => 'won-t-do'],
            $this->audit->record('board.column_added')->context,
        );
    }

    public function test_an_added_column_whose_label_has_no_slug_is_refused(): void
    {
        $this->assertRefused(['label' => BoardColumns::SLUG_EMPTY], fn () => $this->handler(AddBoardColumnHandler::class)(new AddBoardColumnCommand($this->project, '🚀')));
        self::assertSame(['backlog', 'next', 'in-progress', 'done'], $this->slugs());
    }

    public function test_an_added_column_whose_slug_the_board_has_is_refused(): void
    {
        $this->assertRefused(['label' => BoardColumns::SLUG_TAKEN], fn () => $this->handler(AddBoardColumnHandler::class)(new AddBoardColumnCommand($this->project, 'Done!')));
        self::assertSame(['backlog', 'next', 'in-progress', 'done'], $this->slugs());
    }

    public function test_a_label_that_is_a_translation_key_is_refused(): void
    {
        $this->assertRefused(['label' => BoardColumns::LABEL_RESERVED], fn () => $this->handler(AddBoardColumnHandler::class)(new AddBoardColumnCommand($this->project, 'board.page.description')));
        $this->assertRefused(['label' => BoardColumns::LABEL_RESERVED], fn () => $this->handler(RenameBoardColumnHandler::class)(new RenameBoardColumnCommand($this->column($this->project, 'next'), CardReporter::Human, 'board.card.status.done', $this->column($this->project, 'next')->label)));
        self::assertSame(BoardColumns::LABEL_RESERVED, $this->handler(PreviewBoardColumnRenameHandler::class)(new PreviewBoardColumnRenameCommand($this->column($this->project, 'next'), 'board.page.description'))->refusal);
    }

    public function test_an_added_column_with_an_over_long_label_is_refused(): void
    {
        $this->assertRefused(['label' => 'board.column.error.label_too_long'], fn () => $this->handler(AddBoardColumnHandler::class)(new AddBoardColumnCommand($this->project, str_repeat('a', 101))));
    }

    public function test_a_rename_writes_literal_text_and_the_slug_follows(): void
    {
        $next = $this->column($this->project, 'next');

        $this->handler(RenameBoardColumnHandler::class)(new RenameBoardColumnCommand($next, CardReporter::Human, 'Up next', $next->label));

        $this->em->clear();
        $renamed = $this->em->find(BoardColumn::class, $next->id);
        self::assertInstanceOf(BoardColumn::class, $renamed);
        self::assertSame('Up next', $renamed->label);
        self::assertSame('up-next', $renamed->slug);
        self::assertSame(
            ['columnId' => (string) $next->id, 'projectId' => (string) $this->project->id, 'fromSlug' => 'next', 'toSlug' => 'up-next'],
            $this->audit->record('board.column_renamed')->context,
        );
    }

    public function test_a_rename_onto_another_columns_slug_is_refused_and_changes_nothing(): void
    {
        $next = $this->column($this->project, 'next');

        $this->assertRefused(['label' => BoardColumns::SLUG_TAKEN], fn () => $this->handler(RenameBoardColumnHandler::class)(new RenameBoardColumnCommand($next, CardReporter::Human, 'In progress', $next->label)));

        $this->em->clear();
        self::assertSame(['backlog', 'next', 'in-progress', 'done'], $this->slugs());
        self::assertSame([], $this->audit->records('board.column_renamed'));
    }

    public function test_a_rename_to_a_label_with_no_slug_is_refused(): void
    {
        $this->assertRefused(['label' => BoardColumns::SLUG_EMPTY], fn () => $this->handler(RenameBoardColumnHandler::class)(new RenameBoardColumnCommand($this->column($this->project, 'next'), CardReporter::Human, '!!!', $this->column($this->project, 'next')->label)));
    }

    public function test_the_preview_shows_the_slug_a_rename_would_write_and_the_rule_it_would_break(): void
    {
        $preview = $this->handler(PreviewBoardColumnRenameHandler::class);
        $next = $this->column($this->project, 'next');

        $fine = $preview(new PreviewBoardColumnRenameCommand($next, '完了'));
        self::assertSame('wan-le', $fine->slug);
        self::assertNull($fine->refusal);

        $taken = $preview(new PreviewBoardColumnRenameCommand($next, 'Backlog'));
        self::assertSame('backlog', $taken->slug);
        self::assertSame(BoardColumns::SLUG_TAKEN, $taken->refusal);
    }

    public function test_a_reorder_writes_the_order_given(): void
    {
        $expected = implode(',', array_map(fn (string $slug): string => (string) $this->column($this->project, $slug)->id, $this->slugs()));
        $order = array_map(fn (string $slug): string => (string) $this->column($this->project, $slug)->id, ['done', 'backlog', 'in-progress', 'next']);

        $this->handler(ReorderBoardColumnsHandler::class)(new ReorderBoardColumnsCommand($this->project, implode(',', $order), $expected));

        $this->em->clear();
        self::assertSame(['done', 'backlog', 'in-progress', 'next'], $this->slugs());
        self::assertSame('done,backlog,in-progress,next', $this->audit->record('board.columns_reordered')->context['order']);
    }

    public function test_a_reorder_that_misses_a_column_is_refused(): void
    {
        $expected = implode(',', array_map(fn (string $slug): string => (string) $this->column($this->project, $slug)->id, $this->slugs()));
        $order = array_map(fn (string $slug): string => (string) $this->column($this->project, $slug)->id, ['done', 'backlog', 'next']);

        $this->assertRefused(['order' => ReorderBoardColumnsHandler::ORDER_STALE], fn () => $this->handler(ReorderBoardColumnsHandler::class)(new ReorderBoardColumnsCommand($this->project, implode(',', $order), $expected)));
        self::assertSame(['backlog', 'next', 'in-progress', 'done'], $this->slugs());
    }

    public function test_a_reorder_that_names_a_column_twice_is_refused(): void
    {
        $expected = implode(',', array_map(fn (string $slug): string => (string) $this->column($this->project, $slug)->id, $this->slugs()));
        $ids = array_map(fn (string $slug): string => (string) $this->column($this->project, $slug)->id, ['backlog', 'next', 'next', 'done']);

        $this->assertRefused(['order' => ReorderBoardColumnsHandler::ORDER_STALE], fn () => $this->handler(ReorderBoardColumnsHandler::class)(new ReorderBoardColumnsCommand($this->project, implode(',', $ids), $expected)));
    }

    public function test_a_column_turned_terminal_stamps_the_cards_it_holds(): void
    {
        $card = $this->card('Waiting', 'next');
        $cardId = $card->id;
        $this->em->getConnection()->executeStatement("UPDATE board_cards SET updated_at = '2020-01-01 00:00:00' WHERE id = :id", ['id' => (string) $cardId]);

        $this->handler(SetBoardColumnTerminalHandler::class)(new SetBoardColumnTerminalCommand($this->column($this->project, 'next'), true));

        $this->em->clear();
        $stamped = $this->em->find(Card::class, $cardId);
        self::assertInstanceOf(Card::class, $stamped);
        self::assertTrue($stamped->column->terminal);
        self::assertNotNull($stamped->completedAt);
        self::assertGreaterThan(new \DateTimeImmutable('2021-01-01'), $stamped->updatedAt);
        self::assertTrue($this->audit->record('board.column_terminal_set')->context['terminal']);
    }

    public function test_the_last_terminal_column_keeps_its_flag(): void
    {
        $this->assertRefused(['terminal' => BoardColumns::NO_TERMINAL], fn () => $this->handler(SetBoardColumnTerminalHandler::class)(new SetBoardColumnTerminalCommand($this->column($this->project, 'done'), false)));
    }

    public function test_the_default_column_cannot_turn_terminal(): void
    {
        $this->assertRefused(['terminal' => BoardColumns::DEFAULT_TERMINAL], fn () => $this->handler(SetBoardColumnTerminalHandler::class)(new SetBoardColumnTerminalCommand($this->column($this->project, 'backlog'), true)));
    }

    public function test_a_column_that_stops_being_terminal_ranks_its_cards_and_clears_their_completion(): void
    {
        $first = $this->card('Finished first', 'done', CardPriority::High);
        $second = $this->card('Finished second', 'done', CardPriority::High);
        $ids = [$first->id, $second->id];
        $handler = $this->handler(SetBoardColumnTerminalHandler::class);
        $handler(new SetBoardColumnTerminalCommand($this->column($this->project, 'next'), true));

        $handler(new SetBoardColumnTerminalCommand($this->column($this->project, 'done'), false));

        $this->em->clear();
        $positions = [];
        foreach ($ids as $id) {
            $card = $this->em->find(Card::class, $id);
            self::assertInstanceOf(Card::class, $card);
            self::assertNull($card->completedAt);
            $positions[] = $card->position;
        }
        self::assertSame([0, 1], $positions);
    }

    public function test_a_new_default_takes_the_flag_from_the_old_one(): void
    {
        $next = $this->column($this->project, 'next');
        $backlog = $this->column($this->project, 'backlog');

        $this->handler(SetDefaultBoardColumnHandler::class)(new SetDefaultBoardColumnCommand($next, (string) $this->column($this->project, 'backlog')->id));

        $this->em->clear();
        $defaults = array_values(array_filter($this->board(), static fn (BoardColumn $column): bool => $column->isDefault));
        self::assertCount(1, $defaults);
        self::assertSame('next', $defaults[0]->slug);
        self::assertSame((string) $backlog->id, $this->audit->record('board.column_default_set')->context['previousColumnId']);
    }

    public function test_a_terminal_column_cannot_become_the_default(): void
    {
        $this->assertRefused(['default' => BoardColumns::DEFAULT_TERMINAL], fn () => $this->handler(SetDefaultBoardColumnHandler::class)(new SetDefaultBoardColumnCommand($this->column($this->project, 'done'), (string) $this->column($this->project, 'backlog')->id)));
    }

    public function test_an_empty_column_deletes_at_once_and_the_rest_close_up(): void
    {
        $deleted = $this->handler(DeleteBoardColumnHandler::class)(new DeleteBoardColumnCommand($this->column($this->project, 'next'), CardReporter::Human));

        self::assertSame('next', $deleted->slug);
        self::assertNull($deleted->targetSlug);
        self::assertSame([], $deleted->movedCardIds);
        $this->em->clear();
        self::assertSame(['backlog', 'in-progress', 'done'], $this->slugs());
        self::assertSame([0, 1, 2], array_map(static fn (BoardColumn $column): int => $column->position, $this->board()));
        self::assertSame([], $this->audit->records('board.card_moved'));
        self::assertSame(0, $this->audit->record('board.column_deleted')->context['movedCards']);
    }

    public function test_the_default_column_cannot_be_deleted(): void
    {
        $this->assertRefused(['column' => BoardColumns::NO_SINGLE_DEFAULT], fn () => $this->handler(DeleteBoardColumnHandler::class)(new DeleteBoardColumnCommand($this->column($this->project, 'backlog'), CardReporter::Human)));
        self::assertSame(['backlog', 'next', 'in-progress', 'done'], $this->slugs());
    }

    public function test_the_last_terminal_column_cannot_be_deleted(): void
    {
        $this->assertRefused(['column' => BoardColumns::NO_TERMINAL], fn () => $this->handler(DeleteBoardColumnHandler::class)(new DeleteBoardColumnCommand($this->column($this->project, 'done'), CardReporter::Human, $this->column($this->project, 'next'))));
    }

    public function test_a_column_with_cards_needs_a_target(): void
    {
        $this->card('Stuck', 'next');

        $this->assertRefused(['target' => DeleteBoardColumnHandler::TARGET_REQUIRED], fn () => $this->handler(DeleteBoardColumnHandler::class)(new DeleteBoardColumnCommand($this->column($this->project, 'next'), CardReporter::Human)));
        self::assertSame(['backlog', 'next', 'in-progress', 'done'], $this->slugs());
    }

    public function test_a_column_cannot_move_its_cards_into_itself(): void
    {
        $this->card('Stuck', 'next');
        $next = $this->column($this->project, 'next');

        $this->assertRefused(['target' => DeleteBoardColumnHandler::TARGET_INVALID], fn () => $this->handler(DeleteBoardColumnHandler::class)(new DeleteBoardColumnCommand($next, CardReporter::Human, $next)));
    }

    public function test_a_delete_moves_every_card_to_the_target_with_one_audit_record_each_and_one_outbox_row(): void
    {
        $this->card('Already there', 'in-progress', CardPriority::High);
        $first = $this->card('First', 'next', CardPriority::High);
        $second = $this->card('Second', 'next', CardPriority::High);
        $low = $this->card('Low', 'next', CardPriority::Low);
        $ids = [(string) $first->id, (string) $second->id, (string) $low->id];
        $next = $this->column($this->project, 'next');
        $nextId = (string) $next->id;
        $target = $this->column($this->project, 'in-progress');
        $this->audit->forget();

        $deleted = $this->handler(DeleteBoardColumnHandler::class)(new DeleteBoardColumnCommand($next, CardReporter::Human, $target));

        self::assertSame($ids, $deleted->movedCardIds);
        self::assertSame('in-progress', $deleted->targetSlug);

        $this->em->clear();
        self::assertNull($this->em->find(BoardColumn::class, $nextId));
        $placed = [];
        foreach ($ids as $id) {
            $card = $this->em->find(Card::class, $id);
            self::assertInstanceOf(Card::class, $card);
            self::assertSame('in-progress', $card->column->slug);
            $placed[] = [$card->priority, $card->position];
        }
        // Appended after the card the target held, in the order the column showed them.
        self::assertSame([[CardPriority::High, 1], [CardPriority::High, 2], [CardPriority::Low, 0]], $placed);

        $moves = $this->audit->records('board.card_moved');
        self::assertSame($ids, array_map(static fn ($record): string => (string) $record->context['cardId'], $moves));
        foreach ($moves as $move) {
            self::assertSame('next', $move->context['fromStatus']);
            self::assertSame($nextId, $move->context['fromColumnId']);
            self::assertSame('in-progress', $move->context['toStatus']);
        }
        self::assertSame(3, $this->audit->record('board.column_deleted')->context['movedCards']);

        // One row for the delete and none per card, so the bulk move starts no agents.
        self::assertSame(['board.column_deleted'], $this->em->getConnection()->fetchFirstColumn(
            'SELECT type FROM outbox_events WHERE project_id = :id',
            ['id' => (string) $this->project->id],
        ));
    }

    public function test_cards_moved_into_a_terminal_target_are_stamped_complete(): void
    {
        $card = $this->card('Wrapping up', 'next');
        $cardId = $card->id;

        $this->handler(DeleteBoardColumnHandler::class)(new DeleteBoardColumnCommand($this->column($this->project, 'next'), CardReporter::Human, $this->column($this->project, 'done')));

        $this->em->clear();
        $moved = $this->em->find(Card::class, $cardId);
        self::assertInstanceOf(Card::class, $moved);
        self::assertSame('done', $moved->column->slug);
        self::assertNotNull($moved->completedAt);
    }

    public function test_a_loaded_card_reads_its_new_column_rank_and_completion_after_the_bulk_move(): void
    {
        $this->card('First', 'next');
        $second = $this->card('Second', 'next');
        self::assertSame(1, $second->position);
        self::assertNull($second->completedAt);

        $this->handler(DeleteBoardColumnHandler::class)(new DeleteBoardColumnCommand($this->column($this->project, 'next'), CardReporter::Human, $this->column($this->project, 'done')));

        self::assertSame('done', $second->column->slug);
        self::assertSame(0, $second->position);
        self::assertNotNull($second->completedAt);
    }

    public function test_a_card_moved_into_a_column_deleted_since_it_was_loaded_is_refused(): void
    {
        $card = $this->card('Waiting', 'backlog');
        $next = $this->column($this->project, 'next');
        $this->em->getConnection()->executeStatement('DELETE FROM board_columns WHERE id = :id', ['id' => (string) $next->id]);

        $this->assertRefused(['column' => UpdateCardHandler::COLUMN_GONE], fn () => $this->handler(UpdateCardHandler::class)(new UpdateCardCommand(card: $card, actor: CardReporter::Human, column: $next)));
    }

    public function test_a_card_created_in_a_column_deleted_since_it_was_loaded_is_refused(): void
    {
        $next = $this->column($this->project, 'next');
        $this->em->getConnection()->executeStatement('DELETE FROM board_columns WHERE id = :id', ['id' => (string) $next->id]);

        $this->assertRefused(['column' => UpdateCardHandler::COLUMN_GONE], fn () => $this->card('Lost', 'next'));
    }

    public function test_a_card_moved_into_a_column_made_terminal_since_it_was_loaded_is_stamped(): void
    {
        $card = $this->card('Nearly', 'backlog');
        $cardId = $card->id;
        $next = $this->column($this->project, 'next');
        $this->em->getConnection()->executeStatement('UPDATE board_columns SET terminal = true WHERE id = :id', ['id' => (string) $next->id]);

        $this->handler(UpdateCardHandler::class)(new UpdateCardCommand(card: $card, actor: CardReporter::Human, column: $next));

        $this->em->clear();
        $moved = $this->em->find(Card::class, $cardId);
        self::assertInstanceOf(Card::class, $moved);
        self::assertSame('next', $moved->column->slug);
        self::assertNotNull($moved->completedAt);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function handler(string $class): object
    {
        $handler = self::getContainer()->get($class);
        self::assertInstanceOf($class, $handler);

        return $handler;
    }

    /** @param array<string, string> $expected */
    private function assertRefused(array $expected, callable $call): void
    {
        try {
            $call();
            self::fail('The change was not refused.');
        } catch (DomainErrors $e) {
            self::assertSame($expected, $e->errors);
        }
    }

    private function card(string $title, string $column, CardPriority $priority = CardPriority::Medium): Card
    {
        return $this->handler(CreateCardHandler::class)(new CreateCardCommand(
            project: $this->project,
            title: $title,
            body: '',
            type: CardType::Feature,
            priority: $priority,
            column: $this->column($this->project, $column),
            reporter: CardReporter::Human,
        ));
    }

    /** @return list<BoardColumn> */
    private function board(): array
    {
        $repository = $this->handler(BoardColumnRepository::class);
        $project = $this->em->find(Project::class, $this->project->id);
        self::assertInstanceOf(Project::class, $project);

        return $repository->findForProject($project);
    }

    /** @return list<string> */
    private function slugs(): array
    {
        return array_map(static fn (BoardColumn $column): string => $column->slug, $this->board());
    }
}
