<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\DeleteCardCommand;
use App\Module\Board\Command\DeleteCardHandler;
use App\Module\Board\Command\MoveCardCommand;
use App\Module\Board\Command\MoveCardHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\CardGroupOrder;
use App\Module\Project\Entity\Project;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A board write must read the card's group after it takes the project lock.
 *
 * Doctrine's lock() takes the project row and leaves a card loaded before the
 * lock exactly as the request read it, so a caller that waited on the lock
 * would otherwise renumber the group the card has already left.
 *
 * What these tests express is one half of that. The stale card is the card the
 * request loaded, and the SQL statement below is the committed state the winner
 * of the lock left behind. What they cannot express is the lock itself:
 * dama/doctrine-test-bundle wraps each test in one connection's transaction, so
 * two overlapping database transactions have nowhere to live. The lock is
 * verified by reading the handlers.
 */
final class CardPostLockStateTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RecordingAuditor $audit;
    private CreateCardHandler $createCard;
    private MoveCardHandler $moveCard;
    private UpdateCardHandler $updateCard;
    private DeleteCardHandler $deleteCard;
    private Project $project;

    /** @var list<Card> */
    private array $created = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        // Before the handlers are fetched: the container hands the replacement
        // only to what it builds afterwards.
        $this->audit = RecordingAuditor::installedIn(self::getContainer());

        $createCard = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $createCard);
        $this->createCard = $createCard;

        $moveCard = self::getContainer()->get(MoveCardHandler::class);
        self::assertInstanceOf(MoveCardHandler::class, $moveCard);
        $this->moveCard = $moveCard;

        $updateCard = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $updateCard);
        $this->updateCard = $updateCard;

        $cards = self::getContainer()->get(CardRepository::class);
        self::assertInstanceOf(CardRepository::class, $cards);

        $this->deleteCard = new DeleteCardHandler($cards, new CardGroupOrder($cards), $this->em, $this->audit->auditor);

        $owner = new User(fullName: 'Riley', email: 'board-post-lock-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'board-'.uniqid());
        $this->em->persist($this->project);
        $this->em->flush();
    }

    public function test_a_move_compacts_the_group_the_database_holds_rather_than_the_one_it_loaded(): void
    {
        $backlogFirst = $this->card('Backlog first');
        $mover = $this->card('Mover');
        $next = $this->card('Next only', CardStatus::Next);
        $behind = $this->card('Behind the mover', CardStatus::Next);
        $this->putInTheMiddleOfNext($mover, $behind);

        ($this->moveCard)(new MoveCardCommand($mover, CardStatus::InProgress, CardPriority::Medium));

        self::assertSame('next', $this->audit->record('board.card_moved')->context['fromStatus']);
        self::assertSame([0, 1], [$this->storedPosition($next), $this->storedPosition($behind)]);
        self::assertSame(0, $this->storedPosition($backlogFirst));
    }

    public function test_a_delete_compacts_the_group_the_database_holds_rather_than_the_one_it_loaded(): void
    {
        $backlogFirst = $this->card('Backlog first');
        $doomed = $this->card('Doomed');
        $next = $this->card('Next only', CardStatus::Next);
        $behind = $this->card('Behind the doomed card', CardStatus::Next);
        $this->putInTheMiddleOfNext($doomed, $behind);

        ($this->deleteCard)(new DeleteCardCommand($doomed));

        self::assertSame('next', $this->audit->record('board.card_deleted')->context['status']);
        self::assertSame([0, 1], [$this->storedPosition($next), $this->storedPosition($behind)]);
        self::assertSame(0, $this->storedPosition($backlogFirst));
    }

    public function test_an_update_moves_the_card_out_of_the_group_the_database_holds(): void
    {
        $this->card('Backlog first');
        $mover = $this->card('Mover');
        $next = $this->card('Next only', CardStatus::Next);
        $behind = $this->card('Behind the mover', CardStatus::Next);
        $this->putInTheMiddleOfNext($mover, $behind);

        ($this->updateCard)(new UpdateCardCommand(
            card: $mover,
            title: 'Renamed',
            body: 'Rewritten',
            status: CardStatus::InProgress,
        ));

        self::assertSame('next', $this->audit->record('board.card_moved')->context['fromStatus']);
        self::assertTrue($this->audit->record('board.card_updated')->context['moved']);
        self::assertSame([0, 1], [$this->storedPosition($next), $this->storedPosition($behind)]);
        // The field changes are applied after the re-read, so the re-read does
        // not throw them away.
        self::assertSame(['Renamed', 'Rewritten', 'in-progress'], $this->storedCard($mover));
    }

    public function test_an_update_to_the_column_the_database_already_holds_is_not_a_move(): void
    {
        $backlogFirst = $this->card('Backlog first');
        $mover = $this->card('Mover');
        $next = $this->card('Next only', CardStatus::Next);
        $behind = $this->card('Behind the mover', CardStatus::Next);
        $this->putInTheMiddleOfNext($mover, $behind);

        ($this->updateCard)(new UpdateCardCommand(card: $mover, status: CardStatus::Next));

        // Nothing changed, so nothing is recorded: not the move the re-read
        // ruled out, and not an update whose every flag is false.
        self::assertSame([], $this->audit->operations());
        self::assertSame([0, 1, 2], [
            $this->storedPosition($next),
            $this->storedPosition($mover),
            $this->storedPosition($behind),
        ]);
        self::assertSame(0, $this->storedPosition($backlogFirst));
    }

    /**
     * Commits the card into the middle of the Next column behind the caller's
     * back, the way the winner of the project lock leaves it.
     *
     * The statement runs on the connection the test already holds, so the
     * handler's own reads see it while the entities the test loaded do not. The
     * cards the handler must read again are detached first, because a request
     * that moves one card has not loaded the rest of the board.
     */
    private function putInTheMiddleOfNext(Card $card, Card $behind): void
    {
        $this->em->getConnection()->executeStatement(
            "UPDATE board_cards SET status = 'next', position = 1 WHERE id = :id",
            ['id' => (string) $card->id],
        );
        $this->em->getConnection()->executeStatement(
            'UPDATE board_cards SET position = 2 WHERE id = :id',
            ['id' => (string) $behind->id],
        );

        foreach ($this->created as $other) {
            if ($other !== $card) {
                $this->em->detach($other);
            }
        }

        $this->audit->forget();
    }

    private function storedPosition(Card $card): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT position FROM board_cards WHERE id = :id',
            ['id' => (string) $card->id],
        );
    }

    /** @return list<string> the title, body and status the database holds */
    private function storedCard(Card $card): array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT title, body, status FROM board_cards WHERE id = :id',
            ['id' => (string) $card->id],
        );
        self::assertIsArray($row);

        return [(string) $row['title'], (string) $row['body'], (string) $row['status']];
    }

    private function card(string $title, CardStatus $status = CardStatus::Backlog): Card
    {
        $card = ($this->createCard)(new CreateCardCommand(
            project: $this->project,
            title: $title,
            body: 'Body of '.$title,
            type: CardType::Feature,
            priority: CardPriority::Medium,
            status: $status,
        ));

        $this->created[] = $card;

        return $card;
    }
}
