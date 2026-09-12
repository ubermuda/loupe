<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\MoveCardCommand;
use App\Module\Board\Command\MoveCardHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPriority;
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

        self::assertSame('next', $this->stored($card)['slug']);
    }

    public function test_the_status_column_mirrors_the_slug_for_the_previous_image(): void
    {
        $card = $this->create($this->column($this->project, 'in-progress'));
        self::assertSame('in-progress', $this->stored($card)['status']);

        $this->move($card, 'done');

        self::assertSame(['slug' => 'done', 'status' => 'done'], $this->stored($card));
    }

    public function test_a_move_between_two_terminal_columns_keeps_the_first_completion(): void
    {
        $this->em->persist(new BoardColumn(project: $this->project, label: 'Won’t do', slug: 'wont-do', position: 4, terminal: true));
        $this->em->flush();
        $card = $this->create(null);

        $this->move($card, 'done');
        $completedAt = $card->completedAt;
        self::assertNotNull($completedAt);
        $this->move($card, 'wont-do');
        self::assertSame($completedAt, $card->completedAt);

        $this->move($card, 'next');
        self::assertNull($card->completedAt);
    }

    public function test_a_column_of_another_board_is_refused(): void
    {
        $other = $this->board($this->project->owner);
        $card = $this->create(null);

        $this->expectException(\LogicException::class);
        $this->move($card, 'next', $other);
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
            priority: CardPriority::Medium,
            column: $column,
        ));
    }

    private function move(Card $card, string $slug, ?Project $board = null): void
    {
        $handler = self::getContainer()->get(MoveCardHandler::class);
        self::assertInstanceOf(MoveCardHandler::class, $handler);

        $handler(new MoveCardCommand($card, CardReporter::Human, $this->column($board ?? $this->project, $slug), CardPriority::Medium));
    }

    /**
     * Reads the raw row, so the identity map cannot answer with what the handler assigned.
     *
     * @return array{slug: string, status: string}
     */
    private function stored(Card $card): array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT k.slug, c.status FROM board_cards c JOIN board_columns k ON k.id = c.column_id WHERE c.id = :id',
            ['id' => (string) $card->id],
        );
        self::assertIsArray($row);

        return ['slug' => (string) $row['slug'], 'status' => (string) $row['status']];
    }
}
