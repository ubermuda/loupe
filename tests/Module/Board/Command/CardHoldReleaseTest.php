<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\DeleteBoardColumnCommand;
use App\Module\Board\Command\DeleteBoardColumnHandler;
use App\Module\Board\Command\DeleteCardCommand;
use App\Module\Board\Command\DeleteCardHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CardHoldReleaseTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private CreateCardHandler $createCard;
    private CardHolds $holds;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $createCard = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $createCard);
        $this->createCard = $createCard;

        $holds = self::getContainer()->get(CardHolds::class);
        self::assertInstanceOf(CardHolds::class, $holds);
        $this->holds = $holds;

        $owner = new User(fullName: 'Riley', email: 'board-hold-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'board-hold-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->flush();
    }

    public function test_a_human_move_keeps_the_hold(): void
    {
        $card = $this->heldCard('next');

        $this->updateCard()(new UpdateCardCommand(card: $card, actor: Actor::Human, column: $this->column($this->project, 'in-progress')));

        self::assertSame('in-progress', $card->column->slug);
        self::assertTrue($this->holds->isHeld($this->project, $this->idOf($card)));
    }

    #[DataProvider('automatedActors')]
    public function test_an_automated_move_keeps_the_hold(Actor $actor): void
    {
        $card = $this->heldCard('next');

        $this->updateCard()(new UpdateCardCommand(card: $card, actor: $actor, column: $this->column($this->project, 'in-progress')));

        self::assertSame('in-progress', $card->column->slug);
        self::assertTrue($this->holds->isHeld($this->project, $this->idOf($card)));
    }

    /** @return iterable<string, array{Actor}> */
    public static function automatedActors(): iterable
    {
        yield 'agent' => [Actor::Agent];
        yield 'system' => [Actor::System];
    }

    public function test_a_human_rank_move_inside_the_column_keeps_the_hold(): void
    {
        $card = $this->heldCard('next');
        $this->card('next');

        $this->updateCard()(new UpdateCardCommand(card: $card, actor: Actor::Human, column: $this->column($this->project, 'next'), position: 1));

        self::assertSame(1, $card->position, 'the rank must really change, or this test proves nothing');
        self::assertTrue($this->holds->isHeld($this->project, $this->idOf($card)));
    }

    public function test_a_column_delete_releases_the_holds_of_the_cards_it_moves(): void
    {
        $moved = $this->heldCard('next');
        $elsewhere = $this->heldCard('backlog');
        $delete = self::getContainer()->get(DeleteBoardColumnHandler::class);
        self::assertInstanceOf(DeleteBoardColumnHandler::class, $delete);

        $delete(new DeleteBoardColumnCommand($this->column($this->project, 'next'), Actor::Human, $this->column($this->project, 'in-progress')));

        self::assertFalse($this->holds->isHeld($this->project, $this->idOf($moved)));
        self::assertTrue($this->holds->isHeld($this->project, $this->idOf($elsewhere)));
    }

    public function test_a_card_delete_releases_its_hold(): void
    {
        $deleted = $this->heldCard('next');
        $deletedId = $this->idOf($deleted);
        $kept = $this->heldCard('next');
        $delete = self::getContainer()->get(DeleteCardHandler::class);
        self::assertInstanceOf(DeleteCardHandler::class, $delete);

        $delete(new DeleteCardCommand($deleted, Actor::Human));

        self::assertFalse($this->holds->isHeld($this->project, $deletedId));
        self::assertTrue($this->holds->isHeld($this->project, $this->idOf($kept)));
    }

    private function updateCard(): UpdateCardHandler
    {
        $updateCard = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $updateCard);

        return $updateCard;
    }

    private function heldCard(string $column): Card
    {
        $card = $this->card($column);
        $this->holds->hold($this->project, $this->idOf($card), null);
        self::assertTrue($this->holds->isHeld($this->project, $this->idOf($card)));

        return $card;
    }

    private function card(string $column): Card
    {
        return ($this->createCard)(new CreateCardCommand(
            project: $this->project,
            title: 'Card',
            body: 'Body',
            type: 'bug',
            column: $this->column($this->project, $column),
            reporter: Actor::Agent,
        ));
    }

    private function idOf(Card $card): Uuid
    {
        return $card->id ?? throw new \LogicException('A created card has an id.');
    }
}
