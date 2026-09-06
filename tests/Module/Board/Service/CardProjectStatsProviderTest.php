<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardStatus;
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
    }

    public function test_a_project_whose_cards_are_all_done_is_absent(): void
    {
        $this->enableBoard();
        $finished = $this->makeProject('stats-done');
        $this->em->persist(new Card(project: $finished, title: 'Shipped', body: '', number: 1, status: CardStatus::Done));
        // A sibling with one open card, so the absence below cannot pass on a
        // provider that reported nothing at all.
        $busy = $this->makeProject('stats-still-going');
        $this->em->persist(new Card(project: $busy, title: 'Underway', body: '', number: 1));
        $this->em->flush();

        $stats = $this->provider->statsFor([$finished, $busy]);

        self::assertSame(1, $stats[(string) $busy->id]->openCardCount);
        self::assertArrayNotHasKey((string) $finished->id, $stats);
    }

    public function test_it_counts_each_project_separately(): void
    {
        $this->enableBoard();
        $busy = $this->makeProject('stats-busy');
        $quiet = $this->makeProject('stats-quiet');
        $this->em->persist(new Card(project: $busy, title: 'One', body: '', number: 1));
        $this->em->persist(new Card(project: $busy, title: 'Two', body: '', number: 2));
        $this->em->flush();

        $stats = $this->provider->statsFor([$busy, $quiet]);

        self::assertSame(2, $stats[(string) $busy->id]->openCardCount);
        self::assertArrayNotHasKey((string) $quiet->id, $stats);
    }

    public function test_it_reports_nothing_while_the_flag_is_off(): void
    {
        $project = $this->makeProject('stats-flag-off');
        $this->seedOnePerStatus($project);
        // Guard: the assertion below also passes on a fixture that wrote no
        // card at all, which would prove nothing about the flag.
        $this->assertCardRowCount($project, 4);

        self::assertSame([], $this->provider->statsFor([$project]));
    }

    private function seedOnePerStatus(Project $project): void
    {
        foreach (CardStatus::cases() as $index => $status) {
            $this->em->persist(new Card(project: $project, title: $status->value, body: '', number: $index + 1, status: $status));
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
