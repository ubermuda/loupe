<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\MoveCardCommand;
use App\Module\Board\Command\MoveCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Service\BoardColumnSeeder;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Every card write sets the column row beside the status. */
final class CardColumnWriteTest extends KernelTestCase
{
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
        $this->project = new Project($owner, 'board-'.uniqid());
        $this->em->persist($this->project);
        $seeder = self::getContainer()->get(BoardColumnSeeder::class);
        self::assertInstanceOf(BoardColumnSeeder::class, $seeder);
        $seeder->seed($this->project);
        $this->em->flush();
    }

    public function test_a_created_card_points_at_the_column_its_status_names(): void
    {
        $card = $this->create(CardStatus::Next);

        self::assertSame('next', $this->storedColumnSlug($card));
    }

    public function test_a_moved_card_points_at_its_new_column(): void
    {
        $card = $this->create(CardStatus::Backlog);
        self::assertSame('backlog', $this->storedColumnSlug($card));
        $move = self::getContainer()->get(MoveCardHandler::class);
        self::assertInstanceOf(MoveCardHandler::class, $move);

        $move(new MoveCardCommand($card, CardStatus::Done, CardPriority::Medium));

        self::assertSame('done', $this->storedColumnSlug($card));
    }

    public function test_a_card_written_to_a_project_without_columns_seeds_them_first(): void
    {
        $bare = new Project($this->project->owner, 'bare-'.uniqid());
        $this->em->persist($bare);
        $this->em->flush();
        // Guard: a project persisted by hand dispatches no event, so it starts with none.
        self::assertSame(0, $this->columnCount($bare));

        $card = $this->create(CardStatus::InProgress, $bare);

        self::assertSame('in-progress', $this->storedColumnSlug($card));
        self::assertSame(4, $this->columnCount($bare));
    }

    private function columnCount(Project $project): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM board_columns WHERE project_id = :id',
            ['id' => (string) $project->id],
        );
    }

    private function create(CardStatus $status, ?Project $project = null): Card
    {
        $handler = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $handler);

        return $handler(new CreateCardCommand(
            project: $project ?? $this->project,
            title: 'Ship the columns',
            body: 'Body',
            type: CardType::Feature,
            priority: CardPriority::Medium,
            status: $status,
        ));
    }

    /** Reads the raw row, so the identity map cannot answer with what the handler assigned. */
    private function storedColumnSlug(Card $card): ?string
    {
        $slug = $this->em->getConnection()->fetchOne(
            'SELECT k.slug FROM board_cards c LEFT JOIN board_columns k ON k.id = c.column_id WHERE c.id = :id',
            ['id' => (string) $card->id],
        );
        self::assertNotFalse($slug);

        return null === $slug ? null : (string) $slug;
    }
}
