<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\BulkMoveBacklogCardsCommand;
use App\Module\Board\Command\BulkMoveBacklogCardsHandler;
use App\Module\Board\Command\MoveBacklogCardCommand;
use App\Module\Board\Command\MoveBacklogCardHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/** A move writes the ranks of a column in a fixed number of statements, however long the column is. */
final class CardRankWriteQueryCountTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private const int COLUMN_SIZE = 50;

    private EntityManagerInterface $em;
    private DebugDataHolder $queries;
    private CardRepository $cards;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $this->queries = $queries;

        $cards = self::getContainer()->get(CardRepository::class);
        self::assertInstanceOf(CardRepository::class, $cards);
        $this->cards = $cards;

        $owner = new User(fullName: 'Riley', email: 'rank-write-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'rank-write-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->flush();
    }

    public function test_a_move_of_the_last_card_to_the_top_writes_the_column_in_one_statement(): void
    {
        $this->fill('next', 1, self::COLUMN_SIZE);
        $last = $this->cardNumbered(self::COLUMN_SIZE);

        $updates = $this->positionUpdatesOf(fn () => $this->updateCard(new UpdateCardCommand(
            card: $last,
            actor: Actor::Human,
            column: $this->column($this->project, 'next'),
            position: 0,
        )));

        self::assertLessThanOrEqual(3, \count($updates), implode("\n", $updates));
        $this->assertColumnReads('next', [self::COLUMN_SIZE, ...range(1, self::COLUMN_SIZE - 1)]);
    }

    public function test_a_drag_before_a_neighbour_writes_the_column_in_one_statement(): void
    {
        $this->fill('next', 1, self::COLUMN_SIZE);
        $dragged = $this->cardNumbered(41);
        $neighbour = $this->cardNumbered(6);

        $updates = $this->positionUpdatesOf(fn () => $this->updateCard(new UpdateCardCommand(
            card: $dragged,
            actor: Actor::Human,
            column: $this->column($this->project, 'next'),
            beforeCardId: $neighbour->id?->toRfc4122(),
        )));

        self::assertLessThanOrEqual(3, \count($updates), implode("\n", $updates));
        $this->assertColumnReads('next', [...range(1, 5), 41, ...range(6, 40), ...range(42, self::COLUMN_SIZE)]);
    }

    public function test_a_move_off_the_top_of_the_backlog_writes_each_column_in_one_statement(): void
    {
        $this->fill('backlog', 1, self::COLUMN_SIZE);
        $this->fill('next', self::COLUMN_SIZE + 1, 3);
        $top = $this->cardNumbered(1);
        // Loaded Backlog cards, whose ranks the move must read back.
        $this->cardNumbered(2);
        $this->cardNumbered(30);

        $handler = self::getContainer()->get(MoveBacklogCardHandler::class);
        self::assertInstanceOf(MoveBacklogCardHandler::class, $handler);
        $updates = $this->positionUpdatesOf(fn () => $handler(new MoveBacklogCardCommand($top, Actor::Human, $this->column($this->project, 'next'))));

        self::assertLessThanOrEqual(4, \count($updates), implode("\n", $updates));
        $this->assertColumnReads('backlog', range(2, self::COLUMN_SIZE));
        $this->assertColumnReads('next', [...range(self::COLUMN_SIZE + 1, self::COLUMN_SIZE + 3), 1]);
    }

    public function test_a_bulk_move_off_the_top_of_the_backlog_writes_a_fixed_number_of_statements_per_card(): void
    {
        $this->fill('backlog', 1, self::COLUMN_SIZE);
        $this->fill('next', self::COLUMN_SIZE + 1, 3);
        $ids = array_map(fn (int $number): string => (string) $this->cardNumbered($number)->id, range(1, 5));
        $this->cardNumbered(20);
        // The bulk move checks write access against the owner the identity map holds now.
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($this->project->owner, 'main', $this->project->owner->getRoles()));

        $handler = self::getContainer()->get(BulkMoveBacklogCardsHandler::class);
        self::assertInstanceOf(BulkMoveBacklogCardsHandler::class, $handler);
        $updates = $this->positionUpdatesOf(fn () => $handler(new BulkMoveBacklogCardsCommand(
            $this->column($this->project, 'backlog'),
            $ids,
            Actor::Human,
            $this->column($this->project, 'next'),
        )));

        self::assertLessThanOrEqual(3 * \count($ids), \count($updates), implode("\n", $updates));
        $this->assertColumnReads('backlog', range(6, self::COLUMN_SIZE));
        $this->assertColumnReads('next', [...range(self::COLUMN_SIZE + 1, self::COLUMN_SIZE + 3), ...range(1, 5)]);
    }

    /** Persists cards numbered from $first at ranks 0 to $count - 1, then empties the identity map. */
    private function fill(string $slug, int $first, int $count): void
    {
        $this->reloadProject();
        $column = $this->column($this->project, $slug);
        for ($rank = 0; $rank < $count; ++$rank) {
            $number = $first + $rank;
            $this->em->persist(new Card(project: $this->project, column: $column, title: 'Card '.$number, body: '', number: $number, position: $rank));
        }
        $this->em->flush();
        $this->em->clear();
    }

    private function cardNumbered(int $number): Card
    {
        $this->reloadProject();
        $card = $this->cards->findOneByProjectAndNumber($this->project, $number);
        self::assertInstanceOf(Card::class, $card);

        return $card;
    }

    private function reloadProject(): void
    {
        $project = $this->em->find(Project::class, $this->project->id);
        self::assertInstanceOf(Project::class, $project);
        $this->project = $project;
    }

    private function updateCard(UpdateCardCommand $command): void
    {
        $handler = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $handler);
        $handler($command);
    }

    /**
     * @param callable(): mixed $action
     *
     * @return list<string> the statements that wrote a card rank
     */
    private function positionUpdatesOf(callable $action): array
    {
        $this->queries->reset();
        $action();

        $statements = [];
        foreach ($this->queries->getData() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                $statements[] = (string) $query['sql'];
            }
        }
        // Guard: a count of zero proves nothing unless the move ran.
        self::assertNotEmpty($statements);

        return array_values(array_filter(
            $statements,
            static fn (string $sql): bool => str_contains($sql, 'UPDATE board_cards') && str_contains($sql, 'position'),
        ));
    }

    /**
     * Checks the column in the database holds the card numbers in that order at
     * ranks 0 to n - 1, and that every loaded card of it holds its database rank.
     *
     * @param list<int> $numbers
     */
    private function assertColumnReads(string $slug, array $numbers): void
    {
        $column = $this->column($this->project, $slug);
        $positions = $this->cards->positionsInColumn($column);

        self::assertSame($numbers, array_column($this->cards->findRowsInColumn($column), 'number'));
        $ranks = array_values($positions);
        sort($ranks);
        self::assertSame(range(0, \count($numbers) - 1), $ranks);

        $loaded = 0;
        foreach ($this->em->getUnitOfWork()->getIdentityMap()[Card::class] ?? [] as $card) {
            if (!$card instanceof Card || $this->em->isUninitializedObject($card) || $card->column !== $column) {
                continue;
            }
            self::assertSame($positions[(string) $card->id] ?? null, $card->position, 'Card '.$card->number.' holds a stale rank in memory.');
            ++$loaded;
        }
        self::assertGreaterThan(0, $loaded);
    }
}
