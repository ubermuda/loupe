<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Repository;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardRepositoryTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private CardRepository $cards;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $cards = self::getContainer()->get(CardRepository::class);
        self::assertInstanceOf(CardRepository::class, $cards);
        $this->cards = $cards;
    }

    private function cardIn(Project $project): Card
    {
        $handler = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $handler);

        return $handler(new CreateCardCommand($project, 'Ship it', 'Body', CardType::Feature));
    }

    public function test_a_number_resolves_inside_its_own_project_only(): void
    {
        $mine = $this->makeProject('repo-number-mine');
        $theirs = $this->makeProject('repo-number-theirs');
        $theirCard = $this->cardIn($theirs);
        $myCard = $this->cardIn($mine);

        // Guard: both projects hold a card 1, so a lookup that ignores the project could match either.
        self::assertSame(1, $myCard->number);
        self::assertSame(1, $theirCard->number);

        self::assertSame($myCard, $this->cards->findOneByProjectAndNumber($mine, 1));
        self::assertSame($theirCard, $this->cards->findOneByProjectAndNumber($theirs, 1));
        self::assertNull($this->cards->findOneByProjectAndNumber($mine, 2));
    }

    public function test_child_ids_are_the_children_of_that_card_only(): void
    {
        $project = $this->makeProject('repo-child-ids');
        $epic = $this->cardIn($project);
        $child = $this->cardIn($project);
        $other = $this->cardIn($project);
        $child->parent = $epic;
        $this->em->flush();

        self::assertSame([(string) $child->id], $this->cards->findChildIds($epic->id ?? throw new \LogicException('A stored card has an id.')));
        self::assertSame([], $this->cards->findChildIds($other->id ?? throw new \LogicException('A stored card has an id.')));
    }

    public function test_refresh_type_and_parent_reads_the_lane_setting_the_database_holds(): void
    {
        $card = $this->cardIn($this->makeProject('repo-refresh-lane'));
        self::assertTrue($card->laneEnabled);

        $this->em->getConnection()->executeStatement(
            "UPDATE board_cards SET lane_enabled = false, type = 'epic' WHERE id = :id",
            ['id' => (string) $card->id],
        );
        $this->cards->refreshTypeAndParent($card);

        self::assertFalse($card->laneEnabled);
        self::assertSame(CardType::Epic, $card->type);
    }

    public function test_lane_epics_are_the_open_epics_with_their_lane_on_in_column_then_rank_order(): void
    {
        $project = $this->makeProject('repo-lane-epics');
        $this->epicIn($project, 'Next second', 'next', 1);
        $this->epicIn($project, 'Next first', 'next', 0);
        $this->epicIn($project, 'In progress', 'in-progress', 0);
        $this->epicIn($project, 'Waiting in the Backlog', 'backlog', 3);
        $this->epicIn($project, 'Lane off', 'next', 2)->laneEnabled = false;
        $this->epicIn($project, 'Done', 'done', 0);
        $this->epicIn($project, 'Plain card', 'backlog', 0, CardType::Feature);
        $this->epicIn($this->makeProject('repo-lane-epics-other'), 'Other project', 'next', 0);
        $this->em->flush();
        $this->em->clear();

        $project = $this->em->find(Project::class, $project->id) ?? throw new \LogicException('The project is gone.');

        self::assertSame(
            ['Waiting in the Backlog', 'Next first', 'Next second', 'In progress'],
            array_map(static fn (Card $card): string => $card->title, $this->cards->findLaneEpics($project)),
        );
    }

    private function epicIn(Project $project, string $title, string $slug, int $position, CardType $type = CardType::Epic): Card
    {
        $column = $this->column($project, $slug);
        $card = new Card(project: $project, column: $column, title: $title, body: '', number: random_int(1, 1_000_000), type: $type, position: $position);
        if ($column->terminal) {
            $card->completedAt = new \DateTimeImmutable();
        }
        $this->em->persist($card);
        $this->em->flush();

        return $card;
    }
}
