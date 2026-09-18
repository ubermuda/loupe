<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\ListCardsCommand;
use App\Module\Board\Command\ListCardsHandler;
use App\Module\Board\Command\MoveCardCommand;
use App\Module\Board\Command\MoveCardHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** A card's place is a column row of its own board. */
final class CardColumnWriteTest extends KernelTestCase
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

        $owner = new User(fullName: 'Riley', email: 'board-column-write-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = $this->board($owner);
    }

    public function test_a_card_created_with_no_column_lands_in_the_default_column(): void
    {
        // The default is moved off the first column, so the test cannot pass on "first by position".
        $this->column($this->project, 'backlog')->isDefault = false;
        $this->column($this->project, 'next')->isDefault = true;
        $this->em->flush();

        $card = $this->create(null);

        self::assertSame('next', $this->stored($card));
    }

    public function test_a_card_moves_into_a_column_whose_slug_is_longer_than_twenty_characters(): void
    {
        $slug = 'waiting-for-the-customer-to-answer';
        self::assertGreaterThan(20, \strlen($slug));
        $this->em->persist(new BoardColumn(project: $this->project, label: 'Waiting for the customer to answer', slug: $slug, position: 4, terminal: false));
        $this->em->flush();
        $card = $this->create($this->column($this->project, 'in-progress'));

        $this->move($card, $slug);

        self::assertSame($slug, $this->stored($card));
    }

    public function test_a_move_between_two_terminal_columns_keeps_the_first_completion(): void
    {
        $this->em->persist(new BoardColumn(project: $this->project, label: 'Won’t do', slug: 'wont-do', position: 4, terminal: true));
        $this->em->flush();
        $card = $this->create(null);

        $this->move($card, 'done');
        $completedAt = $this->storedCompletedAt($card);
        self::assertNotNull($completedAt);
        $this->move($card, 'wont-do');
        self::assertSame($completedAt, $this->storedCompletedAt($card));

        $this->move($card, 'next');
        self::assertNull($this->storedCompletedAt($card));
    }

    /** The raw column, so a stamp the handler set in memory and never flushed cannot pass. */
    private function storedCompletedAt(Card $card): ?string
    {
        $value = $this->em->getConnection()->fetchOne('SELECT completed_at FROM board_cards WHERE id = :id', ['id' => (string) $card->id]);
        self::assertNotFalse($value);

        return null === $value ? null : (string) $value;
    }

    public function test_a_column_of_another_board_is_refused(): void
    {
        $other = $this->board($this->project->owner);
        $card = $this->create(null);

        $this->expectException(\LogicException::class);
        $this->move($card, 'next', $other);
    }

    public function test_listing_a_column_of_another_board_is_refused(): void
    {
        $other = $this->board($this->project->owner);
        $list = self::getContainer()->get(ListCardsHandler::class);
        self::assertInstanceOf(ListCardsHandler::class, $list);
        // Guard: the board's own column lists, so the refusal below is about the board.
        $list(new ListCardsCommand($this->project, $this->column($this->project, 'next')));

        $this->expectException(\LogicException::class);
        $list(new ListCardsCommand($this->project, $this->column($other, 'next')));
    }

    private function board(User $owner): Project
    {
        $project = new Project($owner, 'board-'.uniqid());
        $this->em->persist($project);
        $this->seedColumns($project);
        $this->em->flush();

        return $project;
    }

    private function create(?BoardColumn $column): Card
    {
        $handler = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $handler);

        return $handler(new CreateCardCommand(
            project: $this->project,
            title: 'Ship the columns',
            body: 'Body',
            type: CardType::Feature,
            column: $column,
        ));
    }

    private function move(Card $card, string $slug, ?Project $board = null): void
    {
        $handler = self::getContainer()->get(MoveCardHandler::class);
        self::assertInstanceOf(MoveCardHandler::class, $handler);

        $handler(new MoveCardCommand($card, CardReporter::Human, $this->column($board ?? $this->project, $slug)));
    }

    /** The slug of the column on the raw row, so the identity map cannot answer with what the handler assigned. */
    private function stored(Card $card): string
    {
        $slug = $this->em->getConnection()->fetchOne(
            'SELECT k.slug FROM board_cards c JOIN board_columns k ON k.id = c.column_id WHERE c.id = :id',
            ['id' => (string) $card->id],
        );
        self::assertIsString($slug);

        return $slug;
    }
}
