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
use App\Module\Board\Entity\CardOrigin;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\CardGroupOrder;
use App\Module\Project\Entity\Project;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final class CardAuditTrailTest extends KernelTestCase
{
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
        $this->deleteCard = new DeleteCardHandler(new CardGroupOrder($cards), $this->em, $this->audit->auditor);

        $owner = new User(fullName: 'Riley', email: 'board-audit-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'board-'.uniqid());
        $this->em->persist($this->project);
        $this->em->flush();
    }

    public function test_a_created_card_is_recorded_with_where_it_landed(): void
    {
        $card = $this->card('First', CardStatus::Next, CardPriority::High);

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
            'priority' => CardPriority::High->value,
            'status' => 'next',
            'origin' => 'agent',
            'pullRequestCount' => 0,
        ], $record->context);

        self::assertSame(['board.card_created'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
    }

    public function test_a_move_records_both_ends_of_the_transition(): void
    {
        $card = $this->card('Movable', CardStatus::Backlog, CardPriority::Low);
        $this->audit->forget();

        ($this->moveCard)(new MoveCardCommand($card, CardStatus::InProgress, CardPriority::High));

        $record = $this->audit->record('board.card_moved');
        self::assertNotNull($record->subject);
        self::assertSame('card', $record->subject->type);
        self::assertSame([
            'cardId' => (string) $card->id,
            'cardNumber' => $card->number,
            'projectId' => (string) $this->project->id,
            'fromStatus' => 'backlog',
            'fromPriority' => CardPriority::Low->value,
            'toStatus' => 'in-progress',
            'toPriority' => CardPriority::High->value,
            'position' => 0,
        ], $record->context);
    }

    public function test_a_deleted_card_is_named_by_the_record_that_survives_it(): void
    {
        $card = $this->card('Doomed', CardStatus::Next, CardPriority::High);
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
            'priority' => CardPriority::High->value,
        ], $record->context);

        $left = $this->em->getConnection()->fetchOne('SELECT count(*) FROM board_cards WHERE id = :id', ['id' => $cardId]);
        self::assertSame(0, (int) $left);
    }

    public function test_an_update_records_the_fields_it_changed_and_not_the_ones_resubmitted(): void
    {
        $card = $this->card('Before', CardStatus::Next, CardPriority::High);
        $this->audit->forget();

        ($this->updateCard)(new UpdateCardCommand(
            card: $card,
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
            'moved' => false,
        ], $record->context);

        self::assertSame([], $this->audit->records('board.card_moved'));
    }

    public function test_an_update_that_changes_the_group_pairs_with_a_move_record(): void
    {
        $card = $this->card('Promotable', CardStatus::Backlog, CardPriority::Low);
        $this->audit->forget();

        ($this->updateCard)(new UpdateCardCommand(card: $card, status: CardStatus::Done));

        $update = $this->audit->record('board.card_updated');
        self::assertTrue($update->context['moved']);

        $move = $this->audit->record('board.card_moved');
        self::assertSame('backlog', $move->context['fromStatus']);
        self::assertSame('done', $move->context['toStatus']);
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
}
