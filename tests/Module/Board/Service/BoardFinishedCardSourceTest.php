<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Bridge\Cost\FinishedCard;
use App\Module\Bridge\Cost\FinishedCardSourceInterface;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BoardFinishedCardSourceTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private FinishedCardSourceInterface $source;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $source = self::getContainer()->get(FinishedCardSourceInterface::class);
        self::assertInstanceOf(FinishedCardSourceInterface::class, $source);
        $this->source = $source;
    }

    public function test_it_returns_the_finished_cards_of_the_project_oldest_first(): void
    {
        $project = $this->makeProject('finished-cards');
        $other = $this->makeProject('finished-cards-other');
        $newer = $this->card($project, 1, 'Newer', 'done', '2026-09-20 10:00:00');
        $older = $this->card($project, 2, 'Older', 'done', '2026-09-01 10:00:00');
        $this->card($project, 3, 'Still open', 'backlog', null);
        $this->card($other, 1, 'Another project', 'done', '2026-09-10 10:00:00');
        $this->em->clear();

        $cards = $this->source->finishedCards($project, null);

        self::assertSame([(string) $older->id, (string) $newer->id], array_map(static fn (FinishedCard $card): string => (string) $card->id, $cards));
        self::assertSame(2, $cards[0]->number);
        self::assertSame('Older', $cards[0]->title);
        self::assertEquals(new \DateTimeImmutable('2026-09-01 10:00:00'), $cards[0]->completedAt);
    }

    public function test_a_start_date_leaves_out_the_cards_finished_before_it(): void
    {
        $project = $this->makeProject('finished-cards-since');
        $this->card($project, 1, 'Before', 'done', '2026-08-31 23:59:59');
        $this->card($project, 2, 'On the day', 'done', '2026-09-01 00:00:00');

        $cards = $this->source->finishedCards($project, new \DateTimeImmutable('2026-09-01 00:00:00'));

        self::assertSame(['On the day'], array_map(static fn (FinishedCard $card): string => $card->title, $cards));
    }

    /** A stale completion date on a card that left the terminal column does not make it finished. */
    public function test_a_card_outside_a_terminal_column_is_not_finished(): void
    {
        $project = $this->makeProject('finished-cards-reopened');
        $this->card($project, 1, 'Reopened', 'backlog', '2026-09-01 10:00:00');

        self::assertSame([], $this->source->finishedCards($project, null));
    }

    private function card(Project $project, int $number, string $title, string $column, ?string $completedAt): Card
    {
        $card = new Card(project: $project, column: $this->column($project, $column), title: $title, body: '', number: $number);
        $card->completedAt = null === $completedAt ? null : new \DateTimeImmutable($completedAt);
        $this->em->persist($card);
        $this->em->flush();

        return $card;
    }
}
