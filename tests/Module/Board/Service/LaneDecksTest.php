<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\LaneDeckView;
use App\Module\Board\Entity\Card;
use App\Module\Board\Service\LaneDecks;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class LaneDecksTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private LaneDecks $decks;
    private Project $project;
    private int $nextNumber = 1;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $decks = self::getContainer()->get(LaneDecks::class);
        self::assertInstanceOf(LaneDecks::class, $decks);
        $this->decks = $decks;

        $owner = new User(fullName: 'Riley', email: 'lane-decks-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'decks-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->flush();
    }

    public function test_a_deck_holds_the_first_backlog_children_in_rank_order_and_counts_them_all(): void
    {
        $epic = $this->card('Epic', 'next', 0, 'epic');
        $created = new \DateTimeImmutable('-1 hour');
        for ($i = LaneDecks::DECK_SIZE + 1; $i >= 0; --$i) {
            $this->card('Waiting '.$i, 'backlog', $i, parent: $epic, createdAt: $created);
        }
        $tie = $this->card('Tie on rank, created later', 'backlog', 0, parent: $epic);
        $this->em->clear();

        $deck = $this->deckOf($epic);

        self::assertSame(LaneDecks::DECK_SIZE + 3, $deck->count);
        self::assertSame(
            ['Waiting 0', $tie->title, 'Waiting 1', 'Waiting 2', 'Waiting 3', 'Waiting 4', 'Waiting 5', 'Waiting 6'],
            array_map(static fn (Card $card): string => $card->title, $deck->cards),
        );
        self::assertCount(LaneDecks::DECK_SIZE, $deck->cards);
    }

    public function test_a_tie_on_rank_and_creation_falls_back_to_the_id(): void
    {
        $epic = $this->card('Epic', 'next', 0, 'epic');
        $created = new \DateTimeImmutable('-1 hour');
        $one = $this->card('One', 'backlog', 0, parent: $epic, createdAt: $created);
        $two = $this->card('Two', 'backlog', 0, parent: $epic, createdAt: $created);
        $this->em->clear();

        $expected = [(string) $one->id, (string) $two->id];
        usort($expected, static fn (string $a, string $b): int => Uuid::fromString($a)->toBinary() <=> Uuid::fromString($b)->toBinary());

        self::assertSame($expected, array_map(static fn (Card $card): string => (string) $card->id, $this->deckOf($epic)->cards));
    }

    public function test_only_backlog_children_of_the_asked_epics_count(): void
    {
        $epic = $this->card('Epic', 'backlog', 0, 'epic');
        $other = $this->card('Other epic', 'next', 0, 'epic');
        $unasked = $this->card('Epic nobody asks for', 'next', 1, 'epic');
        $this->card('Waiting child', 'backlog', 1, parent: $epic);
        $this->card('Child in Next', 'next', 2, parent: $epic);
        $this->card('Finished child', 'done', 0, parent: $epic);
        $this->card('Waiting with no epic', 'backlog', 2);
        $this->card('Waiting child of the other epic', 'backlog', 3, parent: $other);
        $this->card('Waiting child of the unasked epic', 'backlog', 4, parent: $unasked);
        $empty = $this->card('Epic with nothing waiting', 'next', 3, 'epic');
        $this->em->clear();

        $decks = $this->decks->forEpics($this->column($this->project, 'backlog'), [(string) $epic->id, (string) $other->id, (string) $empty->id]);

        self::assertSame([(string) $epic->id, (string) $other->id], array_keys($decks));
        self::assertSame(['Waiting child'], array_map(static fn (Card $card): string => $card->title, $decks[(string) $epic->id]->cards));
        self::assertSame(1, $decks[(string) $epic->id]->count);
        self::assertSame(['Waiting child of the other epic'], array_map(static fn (Card $card): string => $card->title, $decks[(string) $other->id]->cards));
    }

    public function test_no_epic_gives_no_deck(): void
    {
        self::assertSame([], $this->decks->forEpics($this->column($this->project, 'backlog'), []));
    }

    private function deckOf(Card $epic): LaneDeckView
    {
        $decks = $this->decks->forEpics($this->column($this->project, 'backlog'), [(string) $epic->id]);

        return $decks[(string) $epic->id] ?? throw new \LogicException('The epic has no deck.');
    }

    private function card(string $title, string $slug, int $position, string $type = 'feature', ?Card $parent = null, ?\DateTimeImmutable $createdAt = null): Card
    {
        $column = $this->column($this->project, $slug);
        $card = new Card(
            project: $this->em->find(Project::class, $this->project->id) ?? $this->project,
            column: $column,
            title: $title,
            body: '',
            number: $this->nextNumber++,
            type: $type,
            position: $position,
            createdAt: $createdAt ?? new \DateTimeImmutable(),
        );
        $card->parent = null === $parent ? null : $this->em->find(Card::class, $parent->id);
        if ($column->terminal) {
            $card->completedAt = new \DateTimeImmutable();
        }
        $this->em->persist($card);
        $this->em->flush();

        return $card;
    }
}
