<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\BulkMoveBacklogCardsCommand;
use App\Module\Board\Command\BulkMoveBacklogCardsHandler;
use App\Module\Board\Command\CardLinkInput;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\EpicChildrenOpen;
use App\Module\Board\Command\MoveBacklogCardCommand;
use App\Module\Board\Command\MoveBacklogCardHandler;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** The two writes of the Backlog page, each a shell over UpdateCardHandler. */
final class BacklogCardMovesTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $owner = new User(fullName: 'Riley', email: 'backlog-moves-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'board-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->flush();
        $this->actAs($owner);
    }

    public function test_a_move_puts_the_card_at_the_end_of_the_target_and_keeps_its_epic(): void
    {
        $epic = $this->card('Epic', 'next', 'epic');
        $this->card('Already next', 'next');
        $mover = $this->card('Mover', parent: $epic);

        $this->move($mover, 'next');

        $this->em->clear();
        $moved = $this->em->find(Card::class, $mover->id);
        self::assertInstanceOf(Card::class, $moved);
        self::assertSame('next', $moved->column->slug);
        self::assertSame(2, $moved->position);
        self::assertSame($epic->id?->toRfc4122(), $moved->parent?->id?->toRfc4122());
    }

    public function test_a_move_refuses_a_card_outside_the_backlog_and_a_move_into_it(): void
    {
        $onBoard = $this->card('On the board', 'next');
        $waiting = $this->card('Waiting');

        $this->expectDomainError(['card' => MoveBacklogCardHandler::NOT_IN_BACKLOG], fn () => $this->move($onBoard, 'in-progress'));
        $this->expectDomainError(['column' => MoveBacklogCardHandler::TARGET_IS_BACKLOG], fn () => $this->move($waiting, 'backlog'));
    }

    public function test_a_bulk_move_moves_every_card_in_backlog_order(): void
    {
        $first = $this->card('First');
        $this->card('Stays');
        $third = $this->card('Third');

        $moved = $this->bulkMove([(string) $third->id, (string) $first->id], 'next');

        self::assertSame(['First', 'Third'], array_map(static fn (Card $card): string => $card->title, $moved));
        self::assertSame(['Stays'], $this->backlogTitles());
        self::assertSame(['First', 'Third'], $this->titlesIn('next'));
    }

    public function test_a_bulk_move_refuses_a_card_of_another_project_or_outside_the_backlog(): void
    {
        $waiting = $this->card('Waiting');
        $onBoard = $this->card('On the board', 'next');

        $this->expectDomainError(['ids' => MoveBacklogCardHandler::NOT_IN_BACKLOG], fn () => $this->bulkMove([(string) $waiting->id, (string) $onBoard->id], 'in-progress'));
        $this->expectDomainError(['ids' => MoveBacklogCardHandler::NOT_IN_BACKLOG], fn () => $this->bulkMove([(string) $waiting->id, '01890a5d-ac96-774b-bcce-b302099a8057'], 'next'));
        $this->expectDomainError(['ids' => MoveBacklogCardHandler::NOT_IN_BACKLOG], fn () => $this->bulkMove(['not-a-card'], 'next'));
        $this->expectDomainError(['ids' => BulkMoveBacklogCardsHandler::NONE_CHOSEN], fn () => $this->bulkMove([], 'next'));
        $this->expectDomainError(['column' => MoveBacklogCardHandler::TARGET_IS_BACKLOG], fn () => $this->bulkMove([(string) $waiting->id], 'backlog'));
        self::assertSame(['Waiting'], $this->backlogTitles());
    }

    public function test_a_move_refuses_a_card_another_request_moved_out_of_the_backlog(): void
    {
        $stale = $this->card('Stale');
        $this->moveBehindTheEntityManager($stale, 'next');

        $this->expectDomainError(['column' => UpdateCardHandler::COLUMN_CHANGED], fn () => $this->move($stale, 'in-progress'));

        self::assertSame(['Stale'], $this->titlesIn('next'));
        self::assertSame([], $this->titlesIn('in-progress'));
    }

    public function test_a_bulk_move_rolls_back_when_another_request_moved_one_card_out_of_the_backlog(): void
    {
        $first = $this->card('First');
        $stale = $this->card('Stale');
        $this->moveBehindTheEntityManager($stale, 'next');

        $this->expectDomainError(['column' => UpdateCardHandler::COLUMN_CHANGED], fn () => $this->bulkMove([(string) $first->id, (string) $stale->id], 'in-progress'));

        self::assertSame(['First'], $this->backlogTitles());
        self::assertSame(['Stale'], $this->titlesIn('next'));
        self::assertSame([], $this->titlesIn('in-progress'));
    }

    public function test_a_bulk_move_keeps_the_order_another_request_ranked_before_the_lock(): void
    {
        $first = $this->card('First');
        $second = $this->card('Second');
        $this->em->getConnection()->executeStatement(
            'UPDATE board_cards SET position = ? WHERE id = ?',
            [$first->position + $second->position + 1, (string) $first->id],
        );

        $moved = $this->bulkMove([(string) $first->id, (string) $second->id], 'next');

        self::assertSame(['Second', 'First'], array_map(static fn (Card $card): string => $card->title, $moved));
        self::assertSame(['Second', 'First'], $this->titlesIn('next'));
    }

    public function test_a_bulk_move_refuses_more_than_one_page_of_cards(): void
    {
        $ids = [];
        for ($index = 0; $index <= BulkMoveBacklogCardsHandler::MAX_CARDS; ++$index) {
            $ids[] = (string) $this->card('Waiting '.$index)->id;
        }

        $this->expectDomainError(['ids' => BulkMoveBacklogCardsHandler::TOO_MANY], fn () => $this->bulkMove($ids, 'next'));
    }

    public function test_a_bulk_move_to_a_terminal_column_refuses_an_epic_with_open_children_before_any_write(): void
    {
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $plain = $this->card('Plain');
        $epic = $this->card('Epic', type: 'epic');
        $this->card('Open child', 'next', parent: $epic);
        $audit->forget();

        try {
            $this->bulkMove([(string) $plain->id, (string) $epic->id], 'done');
            self::fail('Expected EpicChildrenOpen.');
        } catch (EpicChildrenOpen $e) {
            self::assertSame([3], $e->numbers);
        }

        self::assertSame(['Plain', 'Epic'], $this->backlogTitles());
        self::assertSame([], $audit->operations());
    }

    public function test_a_bulk_move_takes_an_epic_to_a_terminal_column_with_its_open_children(): void
    {
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $epic = $this->card('Epic', type: 'epic');
        $child = $this->card('Child', parent: $epic);
        $audit->forget();

        $this->bulkMove([(string) $epic->id, (string) $child->id], 'done');

        self::assertSame([], $this->backlogTitles());
        $movedNumbers = array_map(static fn ($record): mixed => $record->context['cardNumber'] ?? null, $audit->records('board.card_moved'));
        sort($movedNumbers);
        self::assertSame([$epic->number, $child->number], $movedNumbers);
    }

    public function test_a_bulk_move_takes_an_epic_and_its_last_child_to_a_terminal_column_that_is_not_the_first(): void
    {
        $this->configureColumn($this->project, 'in-progress', terminal: true);
        $epic = $this->card('Epic', type: 'epic');
        $child = $this->card('Child', parent: $epic);

        $this->bulkMove([(string) $epic->id, (string) $child->id], 'done');

        self::assertSame('done', $epic->column->slug);
        self::assertSame('done', $child->column->slug);
    }

    public function test_a_bulk_move_orders_the_cards_for_a_column_another_request_made_terminal_before_the_lock(): void
    {
        $epic = $this->card('Epic', type: 'epic');
        $child = $this->card('Child', parent: $epic);
        $target = $this->column($this->project, 'in-progress');
        $this->em->getConnection()->executeStatement(
            'UPDATE board_columns SET terminal = true WHERE id = ?',
            [(string) $target->id],
        );
        self::assertFalse($target->terminal);

        $this->bulkMove([(string) $epic->id, (string) $child->id], 'in-progress');

        self::assertSame('in-progress', $epic->column->slug);
        self::assertSame('in-progress', $child->column->slug);
    }

    public function test_a_bulk_move_takes_a_child_that_an_earlier_card_of_the_batch_released(): void
    {
        $this->em->persist(new BoardColumn(project: $this->project, label: 'Implementation', slug: 'implementation', position: 4));
        $this->em->flush();
        $epic = $this->card('Epic', type: 'epic');
        $blocker = $this->card('Blocker');
        $child = $this->card('Child', parent: $epic, relatedCards: [new CardLinkInput((string) $blocker->id, CardLinkKind::BlockedBy)]);

        $this->bulkMove([(string) $blocker->id, (string) $child->id], 'done');

        self::assertSame('done', $blocker->column->slug);
        self::assertSame('done', $child->column->slug);
    }

    public function test_a_bulk_move_refuses_the_whole_move_when_one_card_is_not_writable(): void
    {
        $waiting = $this->card('Waiting');
        $stranger = new User(fullName: 'Sam', email: 'backlog-moves-stranger-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($stranger);
        $this->em->flush();
        $this->actAs($stranger);

        try {
            $this->bulkMove([(string) $waiting->id], 'next');
            self::fail('Expected AccessDeniedException.');
        } catch (AccessDeniedException) {
        }

        self::assertSame(['Waiting'], $this->backlogTitles());
    }

    /** Another request's move: the row changes, and the loaded card still reads the Backlog. */
    private function moveBehindTheEntityManager(Card $card, string $slug): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE board_cards SET column_id = ? WHERE id = ?',
            [(string) $this->column($this->project, $slug)->id, (string) $card->id],
        );
        self::assertTrue($card->column->backlog);
    }

    private function actAs(User $user): void
    {
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function move(Card $card, string $slug): void
    {
        $handler = self::getContainer()->get(MoveBacklogCardHandler::class);
        self::assertInstanceOf(MoveBacklogCardHandler::class, $handler);
        $handler(new MoveBacklogCardCommand($card, Actor::Human, $this->column($this->project, $slug)));
    }

    /**
     * @param list<string> $ids
     *
     * @return list<Card>
     */
    private function bulkMove(array $ids, string $slug): array
    {
        $handler = self::getContainer()->get(BulkMoveBacklogCardsHandler::class);
        self::assertInstanceOf(BulkMoveBacklogCardsHandler::class, $handler);

        return $handler(new BulkMoveBacklogCardsCommand(
            $this->column($this->project, 'backlog'),
            $ids,
            Actor::Human,
            $this->column($this->project, $slug),
        ));
    }

    /**
     * @param array<string, string> $errors
     * @param callable(): mixed     $action
     */
    private function expectDomainError(array $errors, callable $action): void
    {
        try {
            $action();
            self::fail('Expected DomainErrors.');
        } catch (DomainErrors $e) {
            self::assertSame($errors, $e->errors);
        }
    }

    /** @param list<CardLinkInput> $relatedCards */
    private function card(string $title, string $slug = 'backlog', string $type = 'feature', ?Card $parent = null, array $relatedCards = []): Card
    {
        $handler = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $handler);

        return $handler(new CreateCardCommand(
            project: $this->project,
            title: $title,
            body: '',
            type: $type,
            column: $this->column($this->project, $slug),
            relatedCards: $relatedCards,
            parentCardId: null === $parent ? null : (string) $parent->id,
        ));
    }

    /** @return list<string> */
    private function backlogTitles(): array
    {
        return $this->titlesIn('backlog');
    }

    /** @return list<string> */
    private function titlesIn(string $slug): array
    {
        $column = $this->column($this->project, $slug);
        self::assertInstanceOf(BoardColumn::class, $column);

        /* @var list<string> */
        return $this->em->getConnection()->fetchFirstColumn(
            'SELECT title FROM board_cards WHERE column_id = ? ORDER BY position, created_at, id',
            [(string) $column->id],
        );
    }
}
