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
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\CardGroupOrder;
use App\Module\Bridge\Service\InteractiveRuns;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final class CardAuditTrailTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private RecordingAuditor $audit;
    private CreateCardHandler $createCard;
    private MoveCardHandler $moveCard;
    private UpdateCardHandler $updateCard;
    private DeleteCardHandler $deleteCard;
    private Project $project;

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

        // Built by hand rather than fetched: nothing injects the delete handler
        // until the board has a controller, so the container inlines it away.
        $interactiveRuns = self::getContainer()->get(InteractiveRuns::class);
        self::assertInstanceOf(InteractiveRuns::class, $interactiveRuns);
        $this->deleteCard = new DeleteCardHandler($cards, new CardGroupOrder($cards), $this->em, $this->audit->auditor, $interactiveRuns, new EventDispatcher());

        $owner = new User(fullName: 'Riley', email: 'board-audit-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'board-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->flush();
    }

    public function test_a_created_card_is_recorded_with_where_it_landed(): void
    {
        $card = $this->card('First', 'next');

        $record = $this->audit->record('board.card_created');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('card', $record->subject->type);
        self::assertSame((string) $card->id, $record->subject->id);
        self::assertSame([
            'cardId' => (string) $card->id,
            'cardNumber' => $card->number,
            'projectId' => (string) $this->project->id,
            'type' => 'bug',
            'status' => 'next',
            'columnId' => (string) $this->column($this->project, 'next')->id,
            'reporter' => 'agent',
            'pullRequestCount' => 0,
            'documentCount' => 0,
            'relatedCardCount' => 0,
        ], $record->context);

        self::assertSame(['board.card_created'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
    }

    public function test_a_move_records_both_ends_of_the_transition_by_slug_and_by_column_id(): void
    {
        $card = $this->card('Movable', 'backlog');
        $this->audit->forget();

        ($this->moveCard)(new MoveCardCommand($card, CardReporter::Human, $this->column($this->project, 'in-progress')));

        $record = $this->audit->record('board.card_moved');
        self::assertNotNull($record->subject);
        self::assertSame('card', $record->subject->type);
        self::assertSame([
            'cardId' => (string) $card->id,
            'cardNumber' => $card->number,
            'projectId' => (string) $this->project->id,
            'fromStatus' => 'backlog',
            'fromColumnId' => (string) $this->column($this->project, 'backlog')->id,
            'toStatus' => 'in-progress',
            'toColumnId' => (string) $this->column($this->project, 'in-progress')->id,
            'position' => 0,
        ], $record->context);
    }

    public function test_a_deleted_card_is_named_by_the_record_that_survives_it(): void
    {
        $card = $this->card('Doomed', 'next');
        $cardId = (string) $card->id;
        $cardNumber = $card->number;
        $this->audit->forget();

        ($this->deleteCard)(new DeleteCardCommand($card));

        $record = $this->audit->record('board.card_deleted');
        self::assertNotNull($record->subject);
        self::assertSame('card', $record->subject->type);
        self::assertSame($cardId, $record->subject->id);
        self::assertSame([
            'cardId' => $cardId,
            'cardNumber' => $cardNumber,
            'projectId' => (string) $this->project->id,
            'status' => 'next',
            'columnId' => (string) $this->column($this->project, 'next')->id,
        ], $record->context);

        $left = $this->em->getConnection()->fetchOne('SELECT count(*) FROM board_cards WHERE id = :id', ['id' => $cardId]);
        self::assertSame(0, (int) $left);
    }

    public function test_an_update_records_the_fields_it_changed_and_not_the_ones_resubmitted(): void
    {
        $card = $this->card('Before', 'next');
        $this->audit->forget();

        ($this->updateCard)(new UpdateCardCommand(
            card: $card,
            actor: CardReporter::Agent,
            title: 'After',
            body: 'Body',
            type: CardType::Bug,
        ));

        $record = $this->audit->record('board.card_updated');
        self::assertSame([
            'cardId' => (string) $card->id,
            'cardNumber' => $card->number,
            'projectId' => (string) $this->project->id,
            'titleChanged' => true,
            'bodyChanged' => false,
            'typeChanged' => false,
            'pullRequestsReplaced' => false,
            'documentsReplaced' => false,
            'relatedCardsReplaced' => false,
            'moved' => false,
        ], $record->context);

        self::assertSame([], $this->audit->records('board.card_moved'));
    }

    public function test_an_update_that_only_moves_the_card_records_the_move_alone(): void
    {
        $card = $this->card('Promotable', 'backlog');
        $this->audit->forget();

        ($this->updateCard)(new UpdateCardCommand(card: $card, actor: CardReporter::Agent, column: $this->column($this->project, 'done')));

        $move = $this->audit->record('board.card_moved');
        self::assertSame('backlog', $move->context['fromStatus']);
        self::assertSame('done', $move->context['toStatus']);

        self::assertSame(['board.card_moved'], $this->audit->operations());
    }

    public function test_an_update_that_changes_a_field_and_the_column_pairs_both_records(): void
    {
        $card = $this->card('Promotable', 'backlog');
        $this->audit->forget();

        ($this->updateCard)(new UpdateCardCommand(card: $card, actor: CardReporter::Agent, title: 'Promoted', column: $this->column($this->project, 'done')));

        $update = $this->audit->record('board.card_updated');
        self::assertTrue($update->context['titleChanged']);
        self::assertTrue($update->context['moved']);

        $move = $this->audit->record('board.card_moved');
        self::assertSame('done', $move->context['toStatus']);
    }

    public function test_a_resubmission_that_changes_nothing_records_nothing(): void
    {
        $card = $this->card('Unchanged', 'next');
        $this->audit->forget();

        ($this->updateCard)(new UpdateCardCommand(
            card: $card,
            actor: CardReporter::Agent,
            title: 'Unchanged',
            body: 'Body',
            type: CardType::Bug,
            column: $this->column($this->project, 'next'),
        ));

        self::assertSame([], $this->audit->operations());
    }

    public function test_a_drag_and_drop_move_records_one_transition_and_no_update(): void
    {
        $card = $this->card('Draggable', 'backlog');
        $this->audit->forget();

        ($this->moveCard)(new MoveCardCommand($card, CardReporter::Human, $this->column($this->project, 'next'), 0));

        self::assertSame(['board.card_moved'], $this->audit->operations());
        self::assertSame('next', $this->audit->record('board.card_moved')->context['toStatus']);
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function migratedHandlers(): iterable
    {
        yield 'create' => [CreateCardHandler::class];
        yield 'update' => [UpdateCardHandler::class];
        yield 'move' => [MoveCardHandler::class];
        yield 'delete' => [DeleteCardHandler::class];
    }

    /**
     * @param class-string $handler
     */
    #[DataProvider('migratedHandlers')]
    public function test_the_handler_keeps_no_logger_beside_the_auditor(string $handler): void
    {
        DirectLogging::assertRemovedFrom($handler);
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
}
