<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\MoveCardCommand;
use App\Module\Board\Command\MoveCardHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Entity\CardType;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardOrderingTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CreateCardHandler $createCard;
    private MoveCardHandler $moveCard;
    private UpdateCardHandler $updateCard;
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

        $moveCard = self::getContainer()->get(MoveCardHandler::class);
        self::assertInstanceOf(MoveCardHandler::class, $moveCard);
        $this->moveCard = $moveCard;

        $updateCard = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $updateCard);
        $this->updateCard = $updateCard;

        $owner = new User(fullName: 'Riley', email: 'board-ordering-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'board-'.uniqid());
        $this->em->persist($this->project);
        $this->em->flush();
    }

    public function test_new_cards_append_to_the_end_of_their_group(): void
    {
        $first = $this->card('First');
        $second = $this->card('Second');
        $third = $this->card('Third');

        self::assertSame([0, 1, 2], [$first->position, $second->position, $third->position]);
    }

    public function test_a_move_inside_a_group_renumbers_that_group(): void
    {
        $first = $this->card('First');
        $second = $this->card('Second');
        $third = $this->card('Third');

        ($this->moveCard)(new MoveCardCommand($third, CardStatus::Backlog, CardPriority::Medium, 0));

        self::assertSame(0, $third->position);
        self::assertSame(1, $first->position);
        self::assertSame(2, $second->position);
    }

    public function test_a_move_past_the_end_of_a_group_lands_at_the_end(): void
    {
        $first = $this->card('First');
        $second = $this->card('Second');

        ($this->moveCard)(new MoveCardCommand($first, CardStatus::Backlog, CardPriority::Medium, 99));

        self::assertSame(0, $second->position);
        self::assertSame(1, $first->position);
    }

    public function test_a_change_of_priority_appends_the_card_to_the_end_of_the_target_group(): void
    {
        $incumbent = $this->card('Already high', CardPriority::High);
        $mover = $this->card('Moving up');
        self::assertSame(0, $mover->position);

        ($this->moveCard)(new MoveCardCommand($mover, CardStatus::Backlog, CardPriority::High));

        self::assertSame(CardPriority::High, $mover->priority);
        self::assertSame(0, $incumbent->position);
        self::assertSame(1, $mover->position);
    }

    public function test_a_change_of_status_appends_the_card_to_the_end_of_the_target_group(): void
    {
        $incumbent = $this->card('Already next', CardPriority::Medium, CardStatus::Next);
        $mover = $this->card('Moving on');

        ($this->moveCard)(new MoveCardCommand($mover, CardStatus::Next, CardPriority::Medium));

        self::assertSame(CardStatus::Next, $mover->status);
        self::assertSame(0, $incumbent->position);
        self::assertSame(1, $mover->position);
    }

    public function test_entering_done_stamps_the_completion(): void
    {
        $card = $this->card('Finish me');
        self::assertNull($card->completedAt);

        ($this->moveCard)(new MoveCardCommand($card, CardStatus::Done, CardPriority::Medium));

        self::assertSame(CardStatus::Done, $card->status);
        self::assertNotNull($this->storedCompletion($card));
    }

    public function test_a_move_inside_done_keeps_the_first_completion(): void
    {
        $card = $this->card('Finish me');
        ($this->moveCard)(new MoveCardCommand($card, CardStatus::Done, CardPriority::Medium));
        $completedAt = $this->storedCompletion($card);
        self::assertNotNull($completedAt);

        ($this->moveCard)(new MoveCardCommand($card, CardStatus::Done, CardPriority::High));

        self::assertSame($completedAt, $this->storedCompletion($card));
    }

    public function test_leaving_done_clears_the_completion(): void
    {
        $card = $this->card('Finish me');
        ($this->moveCard)(new MoveCardCommand($card, CardStatus::Done, CardPriority::Medium));
        // Guard: without it the assertion below also passes on a card that was
        // never stamped in the first place.
        self::assertNotNull($card->completedAt);

        ($this->moveCard)(new MoveCardCommand($card, CardStatus::InProgress, CardPriority::High));

        self::assertNull($this->storedCompletion($card));
    }

    public function test_a_card_created_straight_into_done_is_stamped(): void
    {
        $card = ($this->createCard)(new CreateCardCommand(
            project: $this->project,
            title: 'Already done',
            body: 'Body',
            type: CardType::Docs,
            priority: CardPriority::Low,
            status: CardStatus::Done,
        ));

        self::assertNotNull($card->completedAt);
        self::assertSame(0, $card->position);
    }

    public function test_an_update_that_changes_status_moves_the_card(): void
    {
        $incumbent = $this->card('Already done', CardPriority::Medium, CardStatus::Next);
        $card = $this->card('Change me');

        ($this->updateCard)(new UpdateCardCommand(card: $card, title: 'Changed', status: CardStatus::Next));

        self::assertSame('Changed', $card->title);
        self::assertSame(CardStatus::Next, $card->status);
        self::assertSame(0, $incumbent->position);
        self::assertSame(1, $card->position);
    }

    /** Read back over SQL, so the assertion covers what was written rather than what is in memory. */
    private function storedCompletion(Card $card): ?string
    {
        $stored = $this->em->getConnection()->fetchOne(
            'SELECT completed_at FROM board_cards WHERE id = :id',
            ['id' => (string) $card->id],
        );

        return \is_string($stored) ? $stored : null;
    }

    private function card(string $title, CardPriority $priority = CardPriority::Medium, CardStatus $status = CardStatus::Backlog): Card
    {
        return ($this->createCard)(new CreateCardCommand(
            project: $this->project,
            title: $title,
            body: 'Body of '.$title,
            type: CardType::Feature,
            priority: $priority,
            status: $status,
        ));
    }
}
