<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\ShowCardPlacementCommand;
use App\Module\Board\Command\ShowCardPlacementHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The placement of one card reads that card, so a bigger board costs it no more queries. */
final class ShowCardPlacementQueryCountTest extends KernelTestCase
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

    public function test_a_bigger_board_costs_the_placement_no_more_queries(): void
    {
        $small = $this->board('placement-q-small', 1);
        $big = $this->board('placement-q-big', 10);
        $this->em->clear();

        $forSmall = $this->statementsOfPlacing($small);
        $forBig = $this->statementsOfPlacing($big);

        self::assertNotEmpty($forSmall);
        self::assertSame(\count($forSmall), \count($forBig), "The placement query count grew with the board:\n".implode("\n", $forBig));
        // The badges of the placed card read its own links, and never those of the board with its cards.
        $linkReads = array_values(array_filter($forBig, static fn (string $sql): bool => str_contains($sql, 'board_card_pull_requests')));
        self::assertCount(1, $linkReads, 'The placement read pull requests more than once.');
        self::assertStringNotContainsString('board_cards', $linkReads[0], 'The placement read the pull requests of the board.');
    }

    /**
     * An epic first in the in-progress column, with a triage column and an
     * empty column before it, and $perColumn cards in triage, in progress and done.
     *
     * @param non-empty-string $slug
     *
     * @return array{Project, Card}
     */
    private function board(string $slug, int $perColumn): array
    {
        $owner = new User(fullName: 'Riley', email: $slug.'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, $slug.'-'.uniqid());
        $this->em->persist($project);
        $this->seedColumns($project);
        $this->addTriageColumn($project);
        $this->em->flush();

        $number = 1;
        $epic = $this->card($project, 'in-progress', $number++, 0, 'epic');
        for ($i = 0; $i < $perColumn; ++$i) {
            foreach (['triage', 'in-progress', 'done'] as $column) {
                $card = $this->card($project, $column, $number++, $i + 1);
                $card->parent = $epic;
                $this->em->persist(new CardPullRequest($card, 'https://github.com/acme/app/pull/'.$number));
                $document = new Document(owner: $owner, project: $project, title: 'doc '.$number);
                $this->em->persist($document);
                $this->em->persist(new CardDocument($card, $document));
            }
        }
        $this->em->flush();

        return [$project, $epic];
    }

    private function card(Project $project, string $slug, int $number, int $position, string $type = 'feature'): Card
    {
        $column = $this->column($project, $slug);
        $card = new Card(project: $project, column: $column, title: 'Card '.$number, body: '', number: $number, type: $type, position: $position);
        if ($column->terminal) {
            $card->completedAt = new \DateTimeImmutable();
        }
        $this->em->persist($card);

        return $card;
    }

    /**
     * @param array{Project, Card} $board
     *
     * @return list<string>
     */
    private function statementsOfPlacing(array $board): array
    {
        $project = $this->em->find(Project::class, $board[0]->id);
        $card = $this->em->find(Card::class, $board[1]->id);
        self::assertInstanceOf(Project::class, $project);
        self::assertInstanceOf(Card::class, $card);
        $handler = self::getContainer()->get(ShowCardPlacementHandler::class);
        self::assertInstanceOf(ShowCardPlacementHandler::class, $handler);

        $this->queries->reset();
        $view = $handler(new ShowCardPlacementCommand($project, $card));

        // Guard: the counts mean nothing unless the card was placed and read its children.
        self::assertSame($card, $view->card);
        self::assertNotNull($view->rowAfter);
        self::assertGreaterThan(0, $view->progress?->total);

        $statements = [];
        foreach ($this->queries->getData() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                $statements[] = (string) $query['sql'];
            }
        }

        return $statements;
    }
}
