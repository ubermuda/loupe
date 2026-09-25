<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Service\CardProjectStatsProvider;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardProjectStatsProviderTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private CardProjectStatsProvider $provider;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $provider = self::getContainer()->get(CardProjectStatsProvider::class);
        self::assertInstanceOf(CardProjectStatsProvider::class, $provider);
        $this->provider = $provider;
    }

    public function test_it_counts_every_column_except_done(): void
    {
        $this->enableBoard();
        $project = $this->makeProject('stats');
        $this->seedOnePerStatus($project);

        $stats = $this->provider->statsFor([$project]);

        self::assertSame(3, $stats[(string) $project->id]->openCardCount);
        self::assertSame(1, $stats[(string) $project->id]->completedCardCount);
    }

    public function test_a_project_whose_cards_are_all_done_has_only_completed_work(): void
    {
        $this->enableBoard();
        $finished = $this->makeProject('stats-done');
        $this->em->persist(new Card(project: $finished, column: $this->column($finished, 'done'), title: 'Shipped', body: '', number: 1));
        $busy = $this->makeProject('stats-still-going');
        $this->em->persist(new Card(project: $busy, column: $this->column($busy, 'backlog'), title: 'Underway', body: '', number: 1));
        $this->em->flush();

        $stats = $this->provider->statsFor([$finished, $busy]);

        self::assertSame(1, $stats[(string) $busy->id]->openCardCount);
        self::assertSame(0, $stats[(string) $finished->id]->openCardCount);
        self::assertSame(1, $stats[(string) $finished->id]->completedCardCount);
        self::assertSame(0, $stats[(string) $busy->id]->completedCardCount);
    }

    public function test_it_counts_each_project_separately(): void
    {
        $this->enableBoard();
        $busy = $this->makeProject('stats-busy');
        $quiet = $this->makeProject('stats-quiet');
        $this->em->persist(new Card(project: $busy, column: $this->column($busy, 'backlog'), title: 'One', body: '', number: 1));
        $this->em->persist(new Card(project: $busy, column: $this->column($busy, 'backlog'), title: 'Two', body: '', number: 2));
        $this->em->flush();

        $stats = $this->provider->statsFor([$busy, $quiet]);

        self::assertSame(2, $stats[(string) $busy->id]->openCardCount);
        self::assertArrayNotHasKey((string) $quiet->id, $stats);
    }

    public function test_completion_follows_terminal_columns_instead_of_names(): void
    {
        $this->enableBoard();
        $project = $this->makeProject('stats-custom-terminal');
        $this->seedOnePerStatus($project);
        $this->column($project, 'next')->terminal = true;
        $this->column($project, 'done')->terminal = false;
        $this->em->flush();
        $stats = $this->provider->statsFor([$project]);
        self::assertSame(3, $stats[(string) $project->id]->openCardCount);
        self::assertSame(1, $stats[(string) $project->id]->completedCardCount);
        $this->column($project, 'in-progress')->terminal = true;
        $this->em->flush();
        $stats = $this->provider->statsFor([$project]);
        self::assertSame(2, $stats[(string) $project->id]->openCardCount);
        self::assertSame(2, $stats[(string) $project->id]->completedCardCount);
    }

    public function test_it_reports_nothing_while_the_flag_is_off(): void
    {
        $this->disableBoard();
        $project = $this->makeProject('stats-flag-off');
        $this->seedOnePerStatus($project);
        // Guard: the assertion below also passes on a fixture that wrote no
        // card at all, which would prove nothing about the flag.
        $this->assertCardRowCount($project, 4);

        self::assertSame([], $this->provider->statsFor([$project]));
    }

    private function seedOnePerStatus(Project $project): void
    {
        foreach (['backlog', 'next', 'in-progress', 'done'] as $index => $slug) {
            $this->em->persist(new Card(project: $project, column: $this->column($project, $slug), title: $slug, body: '', number: $index + 1));
        }
        $this->em->flush();
    }

    private function assertCardRowCount(Project $project, int $expected): void
    {
        $stored = $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM board_cards WHERE project_id = :id',
            ['id' => (string) $project->id],
        );

        self::assertSame($expected, (int) $stored);
    }
}
