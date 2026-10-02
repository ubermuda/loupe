<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\BoardCardColumnLookup;
use App\Module\Bridge\Service\CardColumnLookupInterface;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BoardCardColumnLookupTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private CardColumnLookupInterface $lookup;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $this->lookup = new BoardCardColumnLookup(new CardRepository($registry));
    }

    public function test_it_returns_the_slug_of_the_column_the_card_sits_in(): void
    {
        $project = $this->makeProject('column-lookup');
        $card = $this->card($project, 1, 'in-progress');

        self::assertSame('in-progress', $this->lookup->columnOf($project, $card));
    }

    public function test_it_returns_backlog_for_a_backlog_card(): void
    {
        $project = $this->makeProject('column-lookup-backlog');
        $card = $this->card($project, 1, 'backlog');

        self::assertSame('backlog', $this->lookup->columnOf($project, $card));
    }

    /** The card stays managed with its old column, so only a fresh read sees the move. */
    public function test_it_reads_the_column_after_a_move_behind_the_entity_manager(): void
    {
        $project = $this->makeProject('column-lookup-moved');
        $cardId = $this->card($project, 1, 'next');
        self::assertNotNull($this->em->find(Card::class, $cardId));
        $this->em->getConnection()->executeStatement(
            'UPDATE board_cards SET column_id = ? WHERE id = ?',
            [(string) $this->column($project, 'done')->id, (string) $cardId],
        );

        self::assertSame('done', $this->lookup->columnOf($project, $cardId));
    }

    public function test_it_returns_null_for_an_unknown_card(): void
    {
        self::assertNull($this->lookup->columnOf($this->makeProject('column-lookup-unknown'), Uuid::v7()));
    }

    public function test_it_returns_null_for_a_card_of_another_project(): void
    {
        $project = $this->makeProject('column-lookup-mine');
        $foreign = $this->card($this->makeProject('column-lookup-other'), 1, 'next');

        self::assertNull($this->lookup->columnOf($project, $foreign));
    }

    public function test_it_returns_the_id_of_the_card_with_a_number(): void
    {
        $project = $this->makeProject('number-lookup');
        $this->card($project, 1, 'next');
        $card = $this->card($project, 2, 'next');

        self::assertSame((string) $card, (string) $this->lookup->cardIdOfNumber($project, 2));
    }

    public function test_it_returns_no_id_for_an_unknown_number(): void
    {
        self::assertNull($this->lookup->cardIdOfNumber($this->makeProject('number-lookup-unknown'), 7));
    }

    public function test_it_returns_no_id_for_a_number_of_another_project(): void
    {
        $this->card($this->makeProject('number-lookup-other'), 3, 'next');

        self::assertNull($this->lookup->cardIdOfNumber($this->makeProject('number-lookup-mine'), 3));
    }

    private function card(Project $project, int $number, string $slug): Uuid
    {
        $card = new Card(project: $project, column: $this->column($project, $slug), title: 'Card', body: '', number: $number);
        $this->em->persist($card);
        $this->em->flush();

        return $card->id ?? throw new \LogicException('The card has no id after a flush.');
    }
}
