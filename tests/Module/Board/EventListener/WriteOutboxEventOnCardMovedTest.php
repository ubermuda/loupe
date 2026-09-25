<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\MoveCardCommand;
use App\Module\Board\Command\MoveCardHandler;
use App\Module\Board\Command\OpenInteractiveRun;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Event\CardMoved;
use App\Module\Board\EventListener\WriteOutboxEventOnCardMoved;
use App\Module\Board\Service\CardMove;
use App\Module\Bridge\Service\InteractiveRuns;
use App\Module\Project\Entity\Project;
use App\Outbox\Entity\OutboxEvent;
use App\Outbox\Repository\OutboxEventRepository;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

final class WriteOutboxEventOnCardMovedTest extends KernelTestCase
{
    use BoardColumnFixtures;

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
        $this->seedColumns($this->project);
        $this->em->flush();
    }

    /**
     * The keys and the value types below are what the reader of the outbox
     * decodes, so a rename that drifts from them drops the event with nothing
     * in any log.
     */
    public function test_a_move_writes_the_payload_the_reader_decodes(): void
    {
        $card = $this->card('Draggable', 'backlog');

        ($this->moveCard)(new MoveCardCommand($card, CardReporter::Human, $this->column($this->project, 'next'), 0));

        $row = $this->onlyRow();
        self::assertSame([
            'type' => 'board.card_moved',
            'subject' => ['type' => 'card', 'id' => (string) $card->id],
            'projectId' => (string) $this->project->id,
            'cardNumber' => $card->number,
            'fromStatus' => 'backlog',
            'toStatus' => 'next',
            'actor' => 'human',
            'card' => ['interactiveRun' => false],
        ], $this->decode($row));
    }

    public function test_the_row_carries_the_type_and_the_topic(): void
    {
        $card = $this->card('Routable', 'backlog');

        ($this->moveCard)(new MoveCardCommand($card, CardReporter::Human, $this->column($this->project, 'in-progress')));

        $topics = self::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);

        $row = $this->onlyRow();
        self::assertSame('board.card_moved', $row->type);
        self::assertNotNull($this->project->id);
        self::assertSame($topics->forProject($this->project->id), $row->topic);
        self::assertNull($row->publishedAt);
    }

    /** The MCP tool builds this command, and it must write the same one row. */
    public function test_an_update_that_moves_the_card_writes_one_row(): void
    {
        $card = $this->card('Promotable', 'backlog');

        ($this->updateCard)(new UpdateCardCommand(card: $card, actor: CardReporter::Agent, title: 'Promoted', column: $this->column($this->project, 'done')));

        $row = $this->onlyRow();
        self::assertSame([
            'type' => 'board.card_moved',
            'subject' => ['type' => 'card', 'id' => (string) $card->id],
            'projectId' => (string) $this->project->id,
            'cardNumber' => $card->number,
            'fromStatus' => 'backlog',
            'toStatus' => 'done',
            'actor' => 'agent',
            'card' => ['interactiveRun' => false],
        ], $this->decode($row));
    }

    public function test_a_move_that_opens_a_run_publishes_it_as_open(): void
    {
        $card = $this->card('Designable', 'backlog');

        ($this->updateCard)(new UpdateCardCommand(
            card: $card,
            actor: CardReporter::Agent,
            column: $this->column($this->project, 'next'),
            openInteractiveRun: new OpenInteractiveRun(Uuid::v4(), 'loupe:product-design'),
        ));

        self::assertSame(['interactiveRun' => true], $this->decode($this->onlyRow())['card']);
    }

    /** A move to another column closes the run before the row is written. */
    public function test_a_move_to_another_column_publishes_the_run_as_closed(): void
    {
        $card = $this->card('Left behind', 'backlog');
        $this->openRun($card);

        ($this->moveCard)(new MoveCardCommand($card, CardReporter::Human, $this->column($this->project, 'next')));

        self::assertSame(['interactiveRun' => false], $this->decode($this->onlyRow())['card']);
    }

    public function test_a_rank_move_publishes_the_run_as_still_open(): void
    {
        $first = $this->card('Already first', 'next');
        $second = $this->card('Worked on', 'next');
        $this->openRun($second);

        ($this->moveCard)(new MoveCardCommand($second, CardReporter::Human, $this->column($this->project, 'next'), 0));

        self::assertSame(0, $second->position);
        self::assertSame(1, $first->position);
        self::assertSame(['interactiveRun' => true], $this->decode($this->onlyRow())['card']);
    }

    /** A card with no id fails the run read, and the listener must not throw. */
    public function test_a_failed_run_read_publishes_false(): void
    {
        $listener = self::getContainer()->get(WriteOutboxEventOnCardMoved::class);
        self::assertInstanceOf(WriteOutboxEventOnCardMoved::class, $listener);
        $unsaved = new Card($this->project, $this->column($this->project, 'next'), 'Unsaved', 'Body', 99);

        $listener(new CardMoved($unsaved, new CardMove($this->column($this->project, 'backlog')), CardReporter::Human));
        $this->em->flush();

        self::assertSame(['interactiveRun' => false], $this->decode($this->onlyRow())['card']);
    }

    #[DataProvider('actors')]
    public function test_the_payload_names_the_actor_the_command_carries(CardReporter $actor): void
    {
        $card = $this->card('Attributed', 'backlog');

        ($this->updateCard)(new UpdateCardCommand(card: $card, actor: $actor, column: $this->column($this->project, 'next')));

        self::assertSame($actor->value, $this->decode($this->onlyRow())['actor']);
    }

    /** @return iterable<string, array{CardReporter}> */
    public static function actors(): iterable
    {
        foreach (CardReporter::cases() as $actor) {
            yield $actor->value => [$actor];
        }
    }

    public function test_a_move_back_to_the_backlog_is_published_like_any_other(): void
    {
        $card = $this->card('Returnable', 'next');

        ($this->moveCard)(new MoveCardCommand($card, CardReporter::Human, $this->column($this->project, 'backlog')));

        self::assertSame('backlog', $this->decode($this->onlyRow())['toStatus']);
    }

    /**
     * A drag to a new rank inside one column is a move, and it publishes like
     * any other. Deciding that a card already in a column is not worth acting
     * on belongs to whatever reads the stream, because the payload carries both
     * ends and this side holds no policy about which transitions matter.
     *
     * The rank has to really change. A lone card in a group is created at 0, so
     * moving it to 0 would publish only because a position was submitted, and
     * the test would still pass under a "skip when nothing changed" filter,
     * which is the filter it exists to catch.
     */
    public function test_a_reorder_inside_one_column_is_published_with_both_ends_equal(): void
    {
        $first = $this->card('Already first', 'next');
        $second = $this->card('Reorderable', 'next');
        self::assertSame(0, $first->position);
        self::assertSame(1, $second->position);

        ($this->moveCard)(new MoveCardCommand($second, CardReporter::Human, $this->column($this->project, 'next'), 0));

        self::assertSame(0, $second->position, 'the rank must really change, or this test proves nothing');

        $payload = $this->decode($this->onlyRow());
        self::assertSame('next', $payload['fromStatus']);
        self::assertSame('next', $payload['toStatus']);
        self::assertSame($second->number, $payload['cardNumber']);
    }

    public function test_an_update_that_moves_nothing_writes_no_row(): void
    {
        $card = $this->card('Editable', 'next');

        ($this->updateCard)(new UpdateCardCommand(card: $card, actor: CardReporter::Human, title: 'Renamed'));

        self::assertSame('Renamed', $card->title);
        self::assertSame([], $this->rows());
    }

    /**
     * The event is dispatched inside the transaction that moves the card, so a
     * commit that never happens leaves no row claiming a card moved.
     */
    public function test_a_rolled_back_transaction_leaves_no_row(): void
    {
        $card = $this->card('Doomed', 'backlog');
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
            ($this->moveCard)(new MoveCardCommand($card, CardReporter::Human, $this->column($this->project, 'next'), 0));
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
        self::assertSame('backlog', $connection->fetchOne('SELECT k.slug FROM board_cards c JOIN board_columns k ON k.id = c.column_id WHERE c.id = :id', ['id' => $cardId]));
    }

    private function openRun(Card $card): void
    {
        $runs = self::getContainer()->get(InteractiveRuns::class);
        self::assertInstanceOf(InteractiveRuns::class, $runs);
        $runs->open($this->project, $card->id ?? throw new \LogicException('A created card has an id.'), $card->number, Uuid::v4(), 'pairing');
    }

    private function card(string $title, string $column): Card
    {
        return ($this->createCard)(new CreateCardCommand(
            project: $this->project,
            title: $title,
            body: 'Body',
            type: CardType::Bug,
            column: $this->column($this->project, $column),
            reporter: CardReporter::Agent,
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
