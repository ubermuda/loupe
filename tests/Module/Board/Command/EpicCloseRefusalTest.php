<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\EpicChildrenOpen;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EpicCloseRefusalTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private CreateCardHandler $createCard;
    private UpdateCardHandler $updateCard;

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
    }

    /** @return iterable<string, array{CardReporter}> */
    public static function people(): iterable
    {
        yield 'a person' => [CardReporter::Human];
        yield 'an agent' => [CardReporter::Agent];
    }

    #[DataProvider('people')]
    public function test_an_epic_with_open_children_is_not_moved_to_done(CardReporter $actor): void
    {
        $project = $this->makeProject('epic-refuse-'.$actor->value);
        $epic = $this->card($project, 'in-progress', CardType::Epic);
        $second = $this->card($project, 'next', parent: $epic);
        $first = $this->card($project, 'backlog', parent: $epic);
        $this->card($project, 'done', parent: $epic);

        try {
            $this->move($epic, 'done', $actor);
            self::fail('Expected the move to be refused.');
        } catch (EpicChildrenOpen $e) {
            self::assertSame([$second->number, $first->number], $e->numbers);
        }

        self::assertTrue($this->em->isOpen());
        $this->em->clear();
        self::assertSame('in-progress', $this->reload($epic)->column->slug);
    }

    public function test_the_app_itself_may_close_an_epic_with_open_children(): void
    {
        $project = $this->makeProject('epic-refuse-system');
        $epic = $this->card($project, 'in-progress', CardType::Epic);
        $this->card($project, 'backlog', parent: $epic);

        $this->move($epic, 'done', CardReporter::System);

        $this->em->clear();
        self::assertSame('done', $this->reload($epic)->column->slug);
    }

    public function test_an_epic_whose_children_are_all_done_may_be_moved_to_done(): void
    {
        $project = $this->makeProject('epic-refuse-all-done');
        $epic = $this->card($project, 'in-progress', CardType::Epic);
        $this->card($project, 'done', parent: $epic);
        // The flag stays off, so the child did not close the epic on its own.
        $this->move($epic, 'done', CardReporter::Human);

        $this->em->clear();
        self::assertSame('done', $this->reload($epic)->column->slug);
    }

    public function test_an_epic_with_open_children_moves_freely_between_open_columns(): void
    {
        $project = $this->makeProject('epic-refuse-open-column');
        $epic = $this->card($project, 'in-progress', CardType::Epic);
        $this->card($project, 'backlog', parent: $epic);

        $this->move($epic, 'next', CardReporter::Human);

        $this->em->clear();
        self::assertSame('next', $this->reload($epic)->column->slug);
    }

    private function card(Project $project, string $column, CardType $type = CardType::Feature, ?Card $parent = null): Card
    {
        $card = ($this->createCard)(new CreateCardCommand(
            $this->em->find(Project::class, $project->id) ?? throw new \LogicException('The project must exist.'),
            'Card',
            '',
            $type,
            column: $this->column($project, $column),
            parentCardId: null === $parent ? null : (string) $parent->id,
        ));
        $this->em->clear();

        return $card;
    }

    private function move(Card $card, string $column, CardReporter $actor): void
    {
        $fresh = $this->reload($card);
        ($this->updateCard)(new UpdateCardCommand($fresh, $actor, column: $this->column($fresh->project, $column)));
    }

    private function reload(Card $card): Card
    {
        return $this->em->find(Card::class, $card->id) ?? throw new \LogicException('The card must exist.');
    }
}
