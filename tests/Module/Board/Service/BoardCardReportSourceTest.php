<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Board\Service\BoardCardReportSource;
use App\Module\Bridge\Experiment\CardColumn;
use App\Module\Bridge\Experiment\CardReportSourceInterface;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BoardCardReportSourceTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private CardReportSourceInterface $source;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        // No service reads the alias yet, so the compiled container drops it.
        $cards = self::getContainer()->get(CardRepository::class);
        self::assertInstanceOf(CardRepository::class, $cards);
        $board = self::getContainer()->get(BoardAvailability::class);
        self::assertInstanceOf(BoardAvailability::class, $board);
        $this->source = new BoardCardReportSource($cards, $board);
    }

    public function test_it_returns_the_columns_of_the_projects_cards_only(): void
    {
        $this->enableBoard();
        $project = $this->makeProject('card-report');
        $other = $this->makeProject('card-report-other');
        $open = $this->card($project, 1, 'in-progress');
        $finished = $this->card($project, 2, 'done');
        $foreign = $this->card($other, 1, 'done');
        $this->em->clear();

        $columns = $this->source->columnsFor($project, [$open, $finished, $foreign, Uuid::v7()]);

        $expected = [
            (string) $open => new CardColumn('board.card.status.in-progress', false),
            (string) $finished => new CardColumn('board.card.status.done', true),
        ];
        ksort($expected);
        ksort($columns);
        self::assertEquals($expected, $columns);
    }

    public function test_it_returns_nothing_when_the_board_is_off(): void
    {
        $project = $this->makeProject('card-report-off');
        $card = $this->card($project, 1, 'done');
        $this->disableBoard();

        self::assertSame([], $this->source->columnsFor($project, [$card]));
    }

    public function test_it_returns_nothing_for_no_ids(): void
    {
        $this->enableBoard();

        self::assertSame([], $this->source->columnsFor($this->makeProject('card-report-empty'), []));
    }

    private function card(Project $project, int $number, string $column): Uuid
    {
        $card = new Card(project: $project, column: $this->column($project, $column), title: 'Card '.$number, body: '', number: $number);
        $this->em->persist($card);
        $this->em->flush();

        return $card->id ?? throw new \LogicException('The card has no id after a flush.');
    }
}
