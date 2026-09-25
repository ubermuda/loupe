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
use App\Module\Board\Command\OpenInteractiveRun;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Service\CardMover;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\InteractiveRuns;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CardInteractiveRunTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private CreateCardHandler $createCard;
    private UpdateCardHandler $updateCard;
    private InteractiveRuns $runs;
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

        $updateCard = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $updateCard);
        $this->updateCard = $updateCard;

        $runs = self::getContainer()->get(InteractiveRuns::class);
        self::assertInstanceOf(InteractiveRuns::class, $runs);
        $this->runs = $runs;

        $owner = new User(fullName: 'Riley', email: 'board-interactive-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'board-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->flush();
    }

    public function test_a_move_to_another_column_closes_an_open_run(): void
    {
        $card = $this->card('next');
        $run = $this->openRun($card);

        ($this->updateCard)(new UpdateCardCommand(card: $card, actor: CardReporter::Human, column: $this->column($this->project, 'in-progress')));

        self::assertSame(WorkerRunState::Closed, $this->stateOf($run));
        self::assertFalse($this->runs->hasOpenRun($this->project, $this->idOf($card)));
    }

    public function test_a_rank_move_inside_the_column_closes_nothing(): void
    {
        $card = $this->card('next');
        $this->card('next');
        $run = $this->openRun($card);

        ($this->updateCard)(new UpdateCardCommand(card: $card, actor: CardReporter::Human, column: $this->column($this->project, 'next'), position: 1));

        self::assertSame(1, $card->position, 'the rank must really change, or this test proves nothing');
        self::assertSame(WorkerRunState::Running, $this->stateOf($run));
    }

    public function test_an_edit_with_no_column_closes_nothing(): void
    {
        $card = $this->card('next');
        $run = $this->openRun($card);

        ($this->updateCard)(new UpdateCardCommand(card: $card, actor: CardReporter::Human, title: 'Renamed'));

        self::assertSame(WorkerRunState::Running, $this->stateOf($run));
    }

    public function test_a_move_that_opens_a_run_closes_the_old_one_and_keeps_the_new_one(): void
    {
        $card = $this->card('backlog');
        $old = $this->openRun($card);
        $sessionId = Uuid::v4();

        ($this->updateCard)(new UpdateCardCommand(
            card: $card,
            actor: CardReporter::Agent,
            column: $this->column($this->project, 'next'),
            openInteractiveRun: new OpenInteractiveRun($sessionId, 'loupe:product-design'),
        ));

        self::assertSame('next', $card->column->slug);
        self::assertSame(WorkerRunState::Closed, $this->stateOf($old));
        $new = $this->workerRuns()->findOpenInteractive($this->project, $this->idOf($card), $sessionId);
        self::assertNotNull($new);
        self::assertSame('loupe:product-design', $new->ruleName);
        self::assertSame($card->number, $new->cardNumber);
    }

    public function test_an_open_with_no_column_change_closes_nothing(): void
    {
        $card = $this->card('next');
        $other = $this->openRun($card);
        $sessionId = Uuid::v4();

        ($this->updateCard)(new UpdateCardCommand(
            card: $card,
            actor: CardReporter::Agent,
            column: $this->column($this->project, 'next'),
            openInteractiveRun: new OpenInteractiveRun($sessionId, 'loupe:product-design'),
        ));

        self::assertSame(WorkerRunState::Running, $this->stateOf($other));
        self::assertNotNull($this->workerRuns()->findOpenInteractive($this->project, $this->idOf($card), $sessionId));
    }

    public function test_an_update_returns_the_run_it_opened(): void
    {
        $card = $this->card('backlog');
        $sessionId = Uuid::v4();

        $updated = ($this->updateCard)(new UpdateCardCommand(
            card: $card,
            actor: CardReporter::Agent,
            column: $this->column($this->project, 'next'),
            openInteractiveRun: new OpenInteractiveRun($sessionId, 'loupe:product-design'),
        ));

        self::assertSame($card, $updated->card);
        self::assertSame($this->workerRuns()->findOpenInteractive($this->project, $this->idOf($card), $sessionId), $updated->openedRun);
        self::assertNotNull($updated->openedRun);
    }

    public function test_an_update_that_opens_nothing_returns_no_run(): void
    {
        $card = $this->card('next');

        $updated = ($this->updateCard)(new UpdateCardCommand(card: $card, actor: CardReporter::Human, title: 'Renamed'));

        self::assertSame($card, $updated->card);
        self::assertNull($updated->openedRun);
    }

    public function test_a_column_delete_closes_the_runs_of_the_cards_it_moves(): void
    {
        $moved = $this->card('next');
        $stays = $this->card('in-progress');
        $movedRun = $this->openRun($moved);
        $staysRun = $this->openRun($stays);
        $delete = self::getContainer()->get(DeleteBoardColumnHandler::class);
        self::assertInstanceOf(DeleteBoardColumnHandler::class, $delete);

        $delete(new DeleteBoardColumnCommand($this->column($this->project, 'next'), CardReporter::Human, $this->column($this->project, 'in-progress')));

        self::assertSame(WorkerRunState::Closed, $this->stateOf($movedRun));
        self::assertSame(WorkerRunState::Running, $this->stateOf($staysRun));
    }

    public function test_the_mover_closes_the_runs_of_a_card_it_moves_for_any_caller(): void
    {
        $card = $this->card('next');
        $run = $this->openRun($card);
        $mover = self::getContainer()->get(CardMover::class);
        self::assertInstanceOf(CardMover::class, $mover);

        $mover->move($card, $this->column($this->project, 'in-progress'));

        self::assertSame(WorkerRunState::Closed, $this->stateOf($run));
    }

    public function test_the_bulk_mover_closes_the_runs_of_the_cards_it_moves(): void
    {
        $moved = $this->card('next');
        $run = $this->openRun($moved);
        $mover = self::getContainer()->get(CardMover::class);
        self::assertInstanceOf(CardMover::class, $mover);

        $movedIds = $mover->moveAll($this->column($this->project, 'next'), $this->column($this->project, 'in-progress'), new \DateTimeImmutable());

        self::assertSame([(string) $this->idOf($moved)], $movedIds);
        self::assertSame(WorkerRunState::Closed, $this->stateOf($run));
    }

    public function test_a_card_delete_closes_its_runs(): void
    {
        $deleted = $this->card('next');
        $kept = $this->card('next');
        $deletedRun = $this->openRun($deleted);
        $keptRun = $this->openRun($kept);
        $delete = self::getContainer()->get(DeleteCardHandler::class);
        self::assertInstanceOf(DeleteCardHandler::class, $delete);

        $delete(new DeleteCardCommand($deleted));

        self::assertSame(WorkerRunState::Closed, $this->stateOf($deletedRun));
        self::assertSame(WorkerRunState::Running, $this->stateOf($keptRun));
    }

    private function card(string $column): Card
    {
        return ($this->createCard)(new CreateCardCommand(
            project: $this->project,
            title: 'Interactive',
            body: 'Body',
            type: CardType::Feature,
            column: $this->column($this->project, $column),
            reporter: CardReporter::Agent,
        ));
    }

    private function openRun(Card $card): WorkerRun
    {
        return $this->runs->open($this->project, $this->idOf($card), $card->number, Uuid::v4(), 'pairing');
    }

    private function idOf(Card $card): Uuid
    {
        return $card->id ?? throw new \LogicException('A created card has an id.');
    }

    private function stateOf(WorkerRun $run): WorkerRunState
    {
        $state = $this->em->getConnection()->fetchOne('SELECT state FROM bridge_worker_runs WHERE id = :id', ['id' => (string) $run->id]);
        self::assertIsString($state);

        return WorkerRunState::from($state);
    }

    private function workerRuns(): WorkerRunRepository
    {
        $repository = self::getContainer()->get(WorkerRunRepository::class);
        self::assertInstanceOf(WorkerRunRepository::class, $repository);

        return $repository;
    }
}
