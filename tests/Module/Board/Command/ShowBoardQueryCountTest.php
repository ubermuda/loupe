<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\ShowBoardCommand;
use App\Module\Board\Command\ShowBoardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The board reads every Up next deck at once, so more lanes and a bigger Backlog cost it no more queries. */
final class ShowBoardQueryCountTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private DebugDataHolder $queries;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $this->queries = $queries;
    }

    public function test_more_lanes_and_a_bigger_backlog_cost_the_board_no_more_queries(): void
    {
        $small = $this->board('board-q-small', 1);
        $big = $this->board('board-q-big', 12);
        $this->em->clear();

        $forSmall = $this->statementsOfShowing($small);
        $forBig = $this->statementsOfShowing($big);

        self::assertNotEmpty($forSmall);
        self::assertSame(\count($forSmall), \count($forBig), "The board query count grew with the board:\n".implode("\n", $forBig));
    }

    /** $size lane epics in Next, each with $size children in Backlog and one in Next. */
    private function board(string $slug, int $size): Project
    {
        $owner = new User(fullName: 'Riley', email: $slug.'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, $slug.'-'.uniqid());
        $this->em->persist($project);
        $this->seedColumns($project);
        $this->em->flush();

        $number = 1;
        for ($e = 0; $e < $size; ++$e) {
            $epic = $this->card($project, 'next', $number++, $e, 'epic');
            $this->card($project, 'next', $number++, $size + $e)->parent = $epic;
            for ($i = 0; $i < $size; ++$i) {
                $this->card($project, 'backlog', $number++, $i)->parent = $epic;
            }
        }
        $this->em->flush();

        return $project;
    }

    private function card(Project $project, string $slug, int $number, int $position, string $type = 'feature'): Card
    {
        $card = new Card(project: $project, column: $this->column($project, $slug), title: 'Card '.$number, body: '', number: $number, type: $type, position: $position);
        $this->em->persist($card);

        return $card;
    }

    /** @return list<string> */
    private function statementsOfShowing(Project $board): array
    {
        $project = $this->em->find(Project::class, $board->id);
        self::assertInstanceOf(Project::class, $project);
        $handler = self::getContainer()->get(ShowBoardHandler::class);
        self::assertInstanceOf(ShowBoardHandler::class, $handler);

        $this->queries->reset();
        $view = $handler(new ShowBoardCommand($project));

        // Guard: the counts mean nothing unless the board drew lanes with decks.
        self::assertNotSame([], $view->lanes);
        self::assertCount(\count($view->lanes), $view->decks);

        $statements = [];
        foreach ($this->queries->getData() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                $statements[] = (string) $query['sql'];
            }
        }

        return $statements;
    }
}
