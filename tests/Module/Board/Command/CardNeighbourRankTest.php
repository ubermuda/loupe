<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\MoveCardCommand;
use App\Module\Board\Command\MoveCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A drop inside a lane names the card next to it, and the server turns that
 * card into a rank in the whole column.
 */
final class CardNeighbourRankTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private CreateCardHandler $createCard;
    private MoveCardHandler $moveCard;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $createCard = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $createCard);
        $this->createCard = $createCard;

        $moveCard = self::getContainer()->get(MoveCardHandler::class);
        self::assertInstanceOf(MoveCardHandler::class, $moveCard);
        $this->moveCard = $moveCard;

        $owner = new User(fullName: 'Riley', email: 'board-neighbour-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'board-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->flush();
    }

    public function test_a_card_moved_down_lands_above_the_card_named_before_it(): void
    {
        [$a, , , $d] = $this->column4('backlog');

        $this->move($a, 'backlog', before: $d);

        self::assertSame(['B', 'C', 'A', 'D'], $this->titles('backlog'));
    }

    public function test_a_card_moved_down_lands_below_the_card_named_after_it(): void
    {
        [$a, , $c] = $this->column4('backlog');

        $this->move($a, 'backlog', after: $c);

        self::assertSame(['B', 'C', 'A', 'D'], $this->titles('backlog'));
    }

    public function test_a_card_moved_up_lands_above_the_card_named_before_it(): void
    {
        [, $b, , $d] = $this->column4('backlog');

        $this->move($d, 'backlog', before: $b);

        self::assertSame(['A', 'D', 'B', 'C'], $this->titles('backlog'));
    }

    public function test_a_card_moved_up_lands_below_the_card_named_after_it(): void
    {
        [$a, , , $d] = $this->column4('backlog');

        $this->move($d, 'backlog', after: $a);

        self::assertSame(['A', 'D', 'B', 'C'], $this->titles('backlog'));
    }

    public function test_a_card_from_another_column_lands_next_to_its_neighbour(): void
    {
        [, $b] = $this->column4('next');
        $mover = $this->card('Mover', 'backlog');

        $this->move($mover, 'next', before: $b);
        self::assertSame(['A', 'Mover', 'B', 'C', 'D'], $this->titles('next'));

        [, , , $d] = $this->column4('in-progress');
        $this->move($mover, 'in-progress', after: $d);
        self::assertSame(['A', 'B', 'C', 'D', 'Mover'], $this->titles('in-progress'));
    }

    public function test_a_neighbour_in_another_column_sends_the_card_to_the_end(): void
    {
        $this->column4('next');
        $stranger = $this->card('Elsewhere', 'backlog');
        $mover = $this->card('Mover', 'backlog');

        $this->move($mover, 'next', before: $stranger);

        self::assertSame(['A', 'B', 'C', 'D', 'Mover'], $this->titles('next'));
    }

    public function test_a_missing_neighbour_sends_the_card_to_the_end(): void
    {
        [$a] = $this->column4('backlog');

        ($this->moveCard)(new MoveCardCommand(
            card: $a,
            actor: CardReporter::Human,
            column: $this->column($this->project, 'backlog'),
            beforeCardId: '0199a0a0-0000-7000-8000-000000000000',
        ));

        self::assertSame(['B', 'C', 'D', 'A'], $this->titles('backlog'));
    }

    public function test_a_neighbour_in_a_terminal_column_still_finishes_the_card(): void
    {
        $finished = $this->card('Finished', 'done');
        $mover = $this->card('Mover', 'backlog');

        $this->move($mover, 'done', after: $finished);

        self::assertSame('done', $mover->column->slug);
        self::assertNotNull($mover->completedAt);
    }

    public function test_no_neighbour_sends_the_card_to_the_end(): void
    {
        $this->column4('next');
        $mover = $this->card('Mover', 'backlog');

        $this->move($mover, 'next');

        self::assertSame(['A', 'B', 'C', 'D', 'Mover'], $this->titles('next'));
    }

    /** @return list<Card> four cards A to D, in that order */
    private function column4(string $column): array
    {
        return array_map(fn (string $title): Card => $this->card($title, $column), ['A', 'B', 'C', 'D']);
    }

    private function move(Card $card, string $column, ?Card $before = null, ?Card $after = null): void
    {
        ($this->moveCard)(new MoveCardCommand(
            card: $card,
            actor: CardReporter::Human,
            column: $this->column($this->project, $column),
            beforeCardId: $before?->id?->toRfc4122(),
            afterCardId: $after?->id?->toRfc4122(),
        ));
    }

    /**
     * Read back over SQL, so the order is what was written rather than what is in memory.
     *
     * @return list<string>
     */
    private function titles(string $column): array
    {
        /* @var list<string> */
        return $this->em->getConnection()->fetchFirstColumn(
            'SELECT title FROM board_cards WHERE column_id = :column ORDER BY position, created_at, id',
            ['column' => (string) $this->column($this->project, $column)->id],
        );
    }

    private function card(string $title, string $column): Card
    {
        return ($this->createCard)(new CreateCardCommand(
            project: $this->project,
            title: $title,
            body: '',
            type: CardType::Feature,
            column: $this->column($this->project, $column),
        ));
    }
}
