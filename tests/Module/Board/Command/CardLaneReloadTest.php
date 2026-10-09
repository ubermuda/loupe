<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\DeleteCardCommand;
use App\Module\Board\Command\DeleteCardHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Event\BoardColumnsChanged;
use App\Module\Board\Event\CardChanged;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/** A board reloads when a lane appears or disappears, and only then. */
final class CardLaneReloadTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private CreateCardHandler $createCard;
    private UpdateCardHandler $updateCard;
    private DeleteCardHandler $deleteCard;
    private int $reloads = 0;
    private int $cardChanges = 0;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $create = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $create);
        $this->createCard = $create;

        $update = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $update);
        $this->updateCard = $update;

        $delete = self::getContainer()->get(DeleteCardHandler::class);
        self::assertInstanceOf(DeleteCardHandler::class, $delete);
        $this->deleteCard = $delete;

        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(BoardColumnsChanged::class, function (): void {
            ++$this->reloads;
        });
        $dispatcher->addListener(CardChanged::class, function (): void {
            ++$this->cardChanges;
        });
    }

    public function test_a_new_lane_epic_reloads_the_board(): void
    {
        $project = $this->makeProject('lane-create');

        $this->card($project, type: 'epic');

        self::assertSame(1, $this->reloads);
    }

    public function test_a_new_card_that_draws_no_lane_reloads_nothing(): void
    {
        $project = $this->makeProject('lane-create-none');

        $this->card($project);
        $this->card($project, type: 'epic', laneEnabled: false);
        $this->card($project, column: 'done', type: 'epic');

        self::assertSame(3, $this->cardChanges);
        self::assertSame(0, $this->reloads);
    }

    public function test_a_deleted_lane_epic_reloads_the_board(): void
    {
        $project = $this->makeProject('lane-delete');
        $epic = $this->card($project, type: 'epic');
        $this->reset();

        $this->delete($epic);

        self::assertSame(1, $this->reloads);
    }

    public function test_a_deleted_card_that_draws_no_lane_reloads_nothing(): void
    {
        $project = $this->makeProject('lane-delete-none');
        $cards = [
            $this->card($project),
            $this->card($project, type: 'epic', laneEnabled: false),
            $this->card($project, column: 'done', type: 'epic'),
        ];
        $this->reset();

        foreach ($cards as $card) {
            $this->delete($card);
        }

        self::assertSame(3, $this->cardChanges);
        self::assertSame(0, $this->reloads);
    }

    public function test_a_card_that_becomes_an_epic_reloads_the_board(): void
    {
        $project = $this->makeProject('lane-type');
        $card = $this->card($project);
        $this->reset();

        $this->update($card, type: 'epic');

        self::assertSame(1, $this->reloads);
    }

    public function test_a_lane_turned_off_or_on_reloads_the_board(): void
    {
        $project = $this->makeProject('lane-toggle');
        $epic = $this->card($project, type: 'epic');
        $this->reset();

        $this->update($epic, laneEnabled: false);
        $this->update($epic, laneEnabled: true);

        self::assertSame(2, $this->reloads);
    }

    public function test_a_lane_epic_that_finishes_or_reopens_reloads_the_board(): void
    {
        $project = $this->makeProject('lane-move');
        $epic = $this->card($project, type: 'epic');
        $this->reset();

        $this->update($epic, column: 'done');
        self::assertSame(1, $this->reloads);

        $this->update($epic, column: 'backlog');
        self::assertSame(2, $this->reloads);
    }

    public function test_a_move_reads_the_lane_setting_another_request_committed(): void
    {
        $project = $this->makeProject('lane-stale');
        $epic = $this->card($project, column: 'done', type: 'epic', laneEnabled: false);
        $this->reset();

        $loaded = $this->reload($epic);
        $this->em->getConnection()->executeStatement(
            'UPDATE board_cards SET lane_enabled = true WHERE id = :id',
            ['id' => (string) $epic->id],
        );
        ($this->updateCard)(new UpdateCardCommand(
            $loaded,
            Actor::Human,
            column: $this->column($loaded->project, 'backlog'),
        ));

        self::assertSame(1, $this->reloads);
    }

    public function test_a_change_that_leaves_every_lane_as_it_was_reloads_nothing(): void
    {
        $project = $this->makeProject('lane-none');
        $laneEpic = $this->card($project, type: 'epic');
        $quietEpic = $this->card($project, type: 'epic', laneEnabled: false);
        $doneEpic = $this->card($project, column: 'done', type: 'epic');
        $feature = $this->card($project);
        $this->reset();

        $this->update($laneEpic, column: 'next');
        $this->update($laneEpic, title: 'Renamed');
        $this->update($quietEpic, column: 'done');
        $this->update($doneEpic, laneEnabled: false);
        $this->update($feature, column: 'done');

        self::assertSame(5, $this->cardChanges);
        self::assertSame(0, $this->reloads);
    }

    private function reset(): void
    {
        $this->reloads = 0;
        $this->cardChanges = 0;
    }

    private function card(Project $project, string $column = 'backlog', string $type = 'feature', ?bool $laneEnabled = null): Card
    {
        $card = ($this->createCard)(new CreateCardCommand(
            $this->reloadProject($project),
            'Card',
            '',
            $type,
            column: $this->column($project, $column),
            laneEnabled: $laneEnabled,
        ));
        $this->em->clear();

        return $card;
    }

    private function update(Card $card, ?string $column = null, ?string $type = null, ?bool $laneEnabled = null, ?string $title = null): void
    {
        $fresh = $this->reload($card);
        ($this->updateCard)(new UpdateCardCommand(
            $fresh,
            Actor::Human,
            title: $title,
            type: $type,
            column: null === $column ? null : $this->column($fresh->project, $column),
            laneEnabled: $laneEnabled,
        ));
        $this->em->clear();
    }

    private function delete(Card $card): void
    {
        ($this->deleteCard)(new DeleteCardCommand($this->reload($card), Actor::Human));
        $this->em->clear();
    }

    private function reload(Card $card): Card
    {
        return $this->em->find(Card::class, $card->id) ?? throw new \LogicException('The card must exist.');
    }

    private function reloadProject(Project $project): Project
    {
        return $this->em->find(Project::class, $project->id) ?? throw new \LogicException('The project must exist.');
    }
}
