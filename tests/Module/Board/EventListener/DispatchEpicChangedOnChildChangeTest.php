<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\DeleteCardCommand;
use App\Module\Board\Command\DeleteCardHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Event\CardChanged;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class DispatchEpicChangedOnChildChangeTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private CreateCardHandler $createCard;
    private UpdateCardHandler $updateCard;
    private DeleteCardHandler $deleteCard;

    /** @var list<CardChanged> */
    private array $changes = [];

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
        $dispatcher->addListener(CardChanged::class, function (CardChanged $event): void {
            $this->changes[] = $event;
        });
    }

    public function test_a_card_that_joins_an_epic_changes_the_epic(): void
    {
        $project = $this->makeProject('epic-face-join');
        $epic = $this->card($project, type: 'epic');
        $this->changes = [];

        $this->card($project, parent: $epic);

        self::assertTrue($this->epicChanged($epic));
    }

    public function test_a_card_that_leaves_an_epic_changes_the_epic(): void
    {
        $project = $this->makeProject('epic-face-leave');
        $epic = $this->card($project, type: 'epic');
        $child = $this->card($project, parent: $epic);
        $this->changes = [];

        $this->update($child, parentCardId: '');

        self::assertTrue($this->epicChanged($epic));
    }

    public function test_a_card_that_changes_epic_changes_both_epics(): void
    {
        $project = $this->makeProject('epic-face-switch');
        $from = $this->card($project, type: 'epic');
        $to = $this->card($project, type: 'epic');
        $child = $this->card($project, parent: $from);
        $this->changes = [];

        $this->update($child, parentCardId: (string) $to->id);

        self::assertTrue($this->epicChanged($from));
        self::assertTrue($this->epicChanged($to));
    }

    public function test_a_deleted_child_changes_its_epic(): void
    {
        $project = $this->makeProject('epic-face-delete');
        $epic = $this->card($project, type: 'epic');
        $child = $this->card($project, parent: $epic);
        $this->changes = [];

        ($this->deleteCard)(new DeleteCardCommand($this->reload($child), CardReporter::Human));
        $this->em->clear();

        self::assertTrue($this->epicChanged($epic));
    }

    public function test_a_child_that_finishes_changes_its_epic(): void
    {
        $project = $this->makeProject('epic-face-finish');
        $epic = $this->card($project, type: 'epic');
        $child = $this->card($project, parent: $epic);
        $this->card($project, parent: $epic);
        $this->changes = [];

        $this->update($child, column: 'done');

        self::assertTrue($this->epicChanged($epic));
    }

    public function test_a_child_that_reopens_changes_its_epic(): void
    {
        $project = $this->makeProject('epic-face-reopen');
        $epic = $this->card($project, type: 'epic');
        $child = $this->card($project, column: 'done', parent: $epic);
        $this->card($project, parent: $epic);
        $this->changes = [];

        $this->update($child, column: 'backlog');

        self::assertTrue($this->epicChanged($epic));
    }

    public function test_a_child_that_leaves_the_backlog_changes_its_epic(): void
    {
        $project = $this->makeProject('epic-face-leave-backlog');
        $epic = $this->card($project, column: 'next', type: 'epic');
        $child = $this->card($project, parent: $epic);
        $this->changes = [];

        $this->update($child, column: 'next');

        self::assertTrue($this->epicChanged($epic));
    }

    public function test_a_child_that_enters_the_backlog_changes_its_epic(): void
    {
        $project = $this->makeProject('epic-face-enter-backlog');
        $epic = $this->card($project, column: 'next', type: 'epic');
        $child = $this->card($project, column: 'in-progress', parent: $epic);
        $this->changes = [];

        $this->update($child, column: 'backlog');

        self::assertTrue($this->epicChanged($epic));
    }

    /** The deck shows the Backlog children in rank order, so a new rank there changes the epic face. */
    public function test_a_child_ranked_inside_the_backlog_changes_its_epic(): void
    {
        $project = $this->makeProject('epic-face-backlog-rank');
        $epic = $this->card($project, column: 'next', type: 'epic');
        $this->card($project, parent: $epic);
        $child = $this->card($project, parent: $epic);
        $this->changes = [];

        $this->update($child, position: 0);

        self::assertTrue($this->epicChanged($epic));
    }

    public function test_a_child_that_moves_between_open_columns_leaves_its_epic_alone(): void
    {
        $project = $this->makeProject('epic-face-open-move');
        $epic = $this->card($project, type: 'epic');
        $child = $this->card($project, column: 'next', parent: $epic);
        $this->card($project, column: 'next', parent: $epic);
        $this->changes = [];

        $this->update($child, column: 'in-progress');
        $this->update($child, position: 0);

        // Guard: the moves ran and reported the child, so the absence below means something.
        self::assertCount(2, array_filter($this->changes, static fn (CardChanged $change): bool => $change->cardId->equals($child->id)));
        self::assertFalse($this->epicChanged($epic));
    }

    private function epicChanged(Card $epic): bool
    {
        foreach ($this->changes as $change) {
            if ($change->cardId->equals($epic->id) && CardChanged::UPDATED === $change->change && !$change->contentChanged) {
                self::assertTrue($change->projectId->equals($epic->project->id));

                return true;
            }
        }

        return false;
    }

    private function card(Project $project, string $column = 'backlog', string $type = 'feature', ?Card $parent = null): Card
    {
        $card = ($this->createCard)(new CreateCardCommand(
            $this->reloadProject($project),
            'Card',
            '',
            $type,
            column: $this->column($project, $column),
            parentCardId: null === $parent ? null : (string) $parent->id,
        ));
        $this->em->clear();

        return $card;
    }

    private function update(Card $card, ?string $column = null, ?string $parentCardId = null, ?int $position = null): void
    {
        $fresh = $this->reload($card);
        ($this->updateCard)(new UpdateCardCommand(
            $fresh,
            CardReporter::Human,
            column: null === $column ? null : $this->column($fresh->project, $column),
            position: $position,
            parentCardId: $parentCardId,
        ));
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
