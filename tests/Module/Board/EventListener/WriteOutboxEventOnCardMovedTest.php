<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\MoveCardCommand;
use App\Module\Board\Command\MoveCardHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardOrigin;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Event\CardMoved;
use App\Module\Project\Entity\Project;
use App\Outbox\Entity\OutboxEvent;
use App\Outbox\Repository\OutboxEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class WriteOutboxEventOnCardMovedTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CreateCardHandler $createCard;
    private MoveCardHandler $moveCard;
    private UpdateCardHandler $updateCard;
    private OutboxEventRepository $outbox;
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

        $outbox = self::getContainer()->get(OutboxEventRepository::class);
        self::assertInstanceOf(OutboxEventRepository::class, $outbox);
        $this->outbox = $outbox;

        $owner = new User(fullName: 'Riley', email: 'board-outbox-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'board-outbox-'.uniqid());
        $this->em->persist($this->project);
        $this->em->flush();
    }

    /**
     * The keys and the value types below are what the reader of the outbox
     * decodes, so a rename that drifts from them drops the event with nothing
     * in any log.
     */
    public function test_a_move_writes_the_payload_the_reader_decodes(): void
    {
        $card = $this->card('Draggable', CardStatus::Backlog, CardPriority::Low);

        ($this->moveCard)(new MoveCardCommand($card, CardStatus::Next, CardPriority::Low, 0));

        $row = $this->onlyRow();
        self::assertSame([
            'type' => 'board.card_moved',
            'subject' => ['type' => 'card', 'id' => (string) $card->id],
            'projectId' => (string) $this->project->id,
            'cardNumber' => $card->number,
            'fromStatus' => 'backlog',
            'toStatus' => 'next',
        ], $this->decode($row));
    }

    public function test_the_row_carries_the_type_the_topic_and_a_forwardable_default(): void
    {
        $card = $this->card('Routable', CardStatus::Backlog, CardPriority::Low);

        ($this->moveCard)(new MoveCardCommand($card, CardStatus::InProgress, CardPriority::Low));

        $topics = self::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);

        $row = $this->onlyRow();
        self::assertSame('board.card_moved', $row->type);
        self::assertNotNull($this->project->id);
        self::assertSame($topics->forProject($this->project->id), $row->topic);
        self::assertTrue($row->forwardable);
        self::assertNull($row->publishedAt);
    }

    /** The MCP tool builds this command, and it must write the same one row. */
    public function test_an_update_that_moves_the_card_writes_one_row(): void
    {
        $card = $this->card('Promotable', CardStatus::Backlog, CardPriority::Low);

        ($this->updateCard)(new UpdateCardCommand(card: $card, title: 'Promoted', status: CardStatus::Done));

        $row = $this->onlyRow();
        self::assertSame([
            'type' => 'board.card_moved',
            'subject' => ['type' => 'card', 'id' => (string) $card->id],
            'projectId' => (string) $this->project->id,
            'cardNumber' => $card->number,
            'fromStatus' => 'backlog',
            'toStatus' => 'done',
        ], $this->decode($row));
    }

    public function test_a_move_back_to_the_backlog_is_published_like_any_other(): void
    {
        $card = $this->card('Returnable', CardStatus::Next, CardPriority::Low);

        ($this->moveCard)(new MoveCardCommand($card, CardStatus::Backlog, CardPriority::Low));

        self::assertSame('backlog', $this->decode($this->onlyRow())['toStatus']);
    }

    public function test_an_update_that_moves_nothing_writes_no_row(): void
    {
        $card = $this->card('Editable', CardStatus::Next, CardPriority::Low);

        ($this->updateCard)(new UpdateCardCommand(card: $card, title: 'Renamed'));

        self::assertSame('Renamed', $card->title);
        self::assertSame([], $this->rows());
    }

    /**
     * The event is dispatched inside the transaction that moves the card, so a
     * commit that never happens leaves no row claiming a card moved.
     */
    public function test_a_rolled_back_transaction_leaves_no_row(): void
    {
        $card = $this->card('Doomed', CardStatus::Backlog, CardPriority::Low);
        $cardId = (string) $card->id;

        $pending = null;
        $em = $this->em;
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        // After the listener under test, which runs at the default priority.
        $dispatcher->addListener(CardMoved::class, static function () use ($em, &$pending): never {
            $pending = array_values(array_filter(
                $em->getUnitOfWork()->getScheduledEntityInsertions(),
                static fn (object $entity): bool => $entity instanceof OutboxEvent,
            ));

            throw new \RuntimeException('the transaction failed after the move');
        }, -10);

        try {
            ($this->moveCard)(new MoveCardCommand($card, CardStatus::Next, CardPriority::Low, 0));
            self::fail('a failed transaction must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('the transaction failed after the move', $e->getMessage());
        }

        // Without this the two assertions below also pass when the listener
        // never ran at all.
        self::assertIsArray($pending);
        self::assertCount(1, $pending);

        // The entity manager is closed by the rollback, so both reads go
        // through the connection.
        $connection = $this->em->getConnection();
        self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM outbox_events WHERE project_id = :id', [
            'id' => (string) $this->project->id,
        ]));
        self::assertSame('backlog', $connection->fetchOne('SELECT status FROM board_cards WHERE id = :id', ['id' => $cardId]));
    }

    private function card(string $title, CardStatus $status, CardPriority $priority): Card
    {
        return ($this->createCard)(new CreateCardCommand(
            project: $this->project,
            title: $title,
            body: 'Body',
            type: CardType::Bug,
            priority: $priority,
            status: $status,
            origin: CardOrigin::Agent,
        ));
    }

    /** @return array<string, mixed> */
    private function decode(OutboxEvent $row): array
    {
        $decoded = json_decode($row->payload, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function onlyRow(): OutboxEvent
    {
        $rows = $this->rows();
        self::assertCount(1, $rows);

        return $rows[0];
    }

    /** @return list<OutboxEvent> */
    private function rows(): array
    {
        return array_values($this->outbox->findBy(['project' => $this->project], ['createdAt' => 'ASC']));
    }
}
