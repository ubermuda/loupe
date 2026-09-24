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
use App\Module\Board\Service\CardParentPolicy;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Support\SilentAuditor;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardOrderingTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private CreateCardHandler $createCard;
    private MoveCardHandler $moveCard;
    private UpdateCardHandler $updateCard;
    private DeleteCardHandler $deleteCard;
    private CardRepository $cards;
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

        $cards = self::getContainer()->get(CardRepository::class);
        self::assertInstanceOf(CardRepository::class, $cards);
        $this->cards = $cards;

        // Built by hand rather than fetched: nothing injects the delete handler
        // until the board has a controller, so the container inlines it away.
        $this->deleteCard = new DeleteCardHandler($cards, new CardGroupOrder($cards), new CardParentPolicy($cards), $this->em, SilentAuditor::create());

        $owner = new User(fullName: 'Riley', email: 'board-ordering-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'board-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->flush();
    }

    public function test_new_cards_append_to_the_end_of_their_column(): void
    {
        $first = $this->card('First');
        $second = $this->card('Second');
        $third = $this->card('Third');

        self::assertSame([0, 1, 2], [$first->position, $second->position, $third->position]);
    }

    public function test_card_numbers_count_up_inside_one_project(): void
    {
        $first = $this->card('First');
        $second = $this->card('Second');

        self::assertSame([1, 2], [$first->number, $second->number]);
    }

    public function test_every_project_starts_its_own_numbering_at_one(): void
    {
        $here = $this->card('First here');

        $other = new Project($this->project->owner, 'board-'.uniqid());
        $this->em->persist($other);
        $this->seedColumns($other);
        $this->em->flush();
        $there = ($this->createCard)(new CreateCardCommand(
            project: $other,
            title: 'First there',
            body: 'Body',
            type: CardType::Feature,
        ));

        self::assertSame([1, 1], [$here->number, $there->number]);
    }

    public function test_the_same_number_cannot_be_used_twice_in_one_project(): void
    {
        $backlog = $this->column($this->project, 'backlog');
        $this->em->persist(new Card(project: $this->project, column: $backlog, title: 'First', body: 'Body', number: 1));
        $this->em->persist(new Card(project: $this->project, column: $backlog, title: 'Second', body: 'Body', number: 1));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function test_a_move_inside_a_column_renumbers_that_column(): void
    {
        $first = $this->card('First');
        $second = $this->card('Second');
        $third = $this->card('Third');

        ($this->moveCard)(new MoveCardCommand($third, CardReporter::Human, $this->column($this->project, 'backlog'), 0));

        self::assertSame(0, $third->position);
        self::assertSame(1, $first->position);
        self::assertSame(2, $second->position);
    }

    public function test_a_move_past_the_end_of_a_column_lands_at_the_end(): void
    {
        $first = $this->card('First');
        $second = $this->card('Second');

        ($this->moveCard)(new MoveCardCommand($first, CardReporter::Human, $this->column($this->project, 'backlog'), 99));

        self::assertSame(0, $second->position);
        self::assertSame(1, $first->position);
    }

    public function test_the_board_reads_an_open_column_in_rank_order(): void
    {
        $this->card('First');
        $this->card('Second');
        $third = $this->card('Third');

        ($this->moveCard)(new MoveCardCommand($third, CardReporter::Human, $this->column($this->project, 'backlog'), 1));
        $this->em->clear();

        self::assertSame(
            ['First', 'Third', 'Second'],
            array_map(static fn (Card $card): string => $card->title, $this->cards->findForBoard([$this->column($this->project, 'backlog')])),
        );
    }

    public function test_a_change_of_status_appends_the_card_to_the_end_of_the_target_column(): void
    {
        $incumbent = $this->card('Already next', 'next');
        $mover = $this->card('Moving on');

        ($this->moveCard)(new MoveCardCommand($mover, CardReporter::Human, $this->column($this->project, 'next')));

        self::assertSame('next', $mover->column->slug);
        self::assertSame(0, $incumbent->position);
        self::assertSame(1, $mover->position);
    }

    public function test_a_card_from_another_column_lands_at_the_rank_the_drop_chose(): void
    {
        $first = $this->card('Already first', 'next');
        $second = $this->card('Already second', 'next');
        $mover = $this->card('Moving on');

        ($this->moveCard)(new MoveCardCommand($mover, CardReporter::Human, $this->column($this->project, 'next'), 1));

        self::assertSame('next', $mover->column->slug);
        self::assertSame(0, $first->position);
        self::assertSame(1, $mover->position);
        self::assertSame(2, $second->position);
    }

    public function test_a_move_out_of_a_column_closes_the_gap_it_leaves(): void
    {
        $first = $this->card('First');
        $mover = $this->card('Middle');
        $last = $this->card('Last');
        self::assertSame([0, 1, 2], [$first->position, $mover->position, $last->position]);

        ($this->moveCard)(new MoveCardCommand($mover, CardReporter::Human, $this->column($this->project, 'next')));

        self::assertSame(0, $first->position);
        self::assertSame(1, $last->position);
        self::assertSame(0, $mover->position);
    }

    public function test_a_move_to_the_end_of_its_own_column_leaves_no_gap(): void
    {
        $first = $this->card('First');
        $second = $this->card('Second');
        $third = $this->card('Third');

        ($this->moveCard)(new MoveCardCommand($first, CardReporter::Human, $this->column($this->project, 'backlog')));

        self::assertSame(0, $second->position);
        self::assertSame(1, $third->position);
        self::assertSame(2, $first->position);
    }

    public function test_deleting_a_card_closes_the_gap_it_leaves(): void
    {
        $first = $this->card('First');
        $doomed = $this->card('Middle');
        $last = $this->card('Last');
        self::assertSame([0, 1, 2], [$first->position, $doomed->position, $last->position]);

        ($this->deleteCard)(new DeleteCardCommand($doomed));

        self::assertSame(0, $first->position);
        self::assertSame(1, $last->position);
        self::assertSame(2, $this->cards->nextPosition($this->column($this->project, 'backlog')));
    }

    public function test_two_cards_finished_in_the_same_second_read_newest_first(): void
    {
        // completed_at holds whole seconds, so a tie here is reachable rather than
        // theoretical. The Done column states newest first; the tie-break has to
        // agree with it.
        $sameSecond = new \DateTimeImmutable('2026-09-07 12:00:00');

        $earlier = new Card(project: $this->project, column: $this->column($this->project, 'done'), title: 'Started earlier', body: '', number: 901, createdAt: new \DateTimeImmutable('2026-09-01 09:00:00'));
        $later = new Card(project: $this->project, column: $this->column($this->project, 'done'), title: 'Started later', body: '', number: 902, createdAt: new \DateTimeImmutable('2026-09-02 09:00:00'));
        $earlier->completedAt = $sameSecond;
        $later->completedAt = $sameSecond;
        $this->em->persist($earlier);
        $this->em->persist($later);
        $this->em->flush();

        $titles = array_map(
            static fn (Card $card): string => $card->title,
            $this->cards->findForBoard([$this->column($this->project, 'done')]),
        );

        self::assertSame(['Started later', 'Started earlier'], $titles);
    }

    public function test_the_board_and_the_done_history_agree_on_a_total_tie(): void
    {
        // Both columns hold whole seconds, so two cards can tie on completion and
        // creation. The board reads one query and the history page reads another;
        // a reader moving between them must not see the pair swap.
        $completed = new \DateTimeImmutable('2026-09-07 12:00:00');
        $created = new \DateTimeImmutable('2026-09-01 09:00:00');

        foreach ([911, 912] as $number) {
            $card = new Card(project: $this->project, column: $this->column($this->project, 'done'), title: 'Tied '.$number, body: '', number: $number, createdAt: $created);
            $card->completedAt = $completed;
            $this->em->persist($card);
        }
        $this->em->flush();

        $titles = static fn (array $cards): array => array_map(static fn (Card $card): string => $card->title, $cards);

        self::assertSame(
            $titles($this->cards->findForBoard([$this->column($this->project, 'done')])),
            $titles($this->cards->findCompletedPage($this->column($this->project, 'done'), 0, 10)),
        );
    }

    public function test_entering_done_stamps_the_completion(): void
    {
        $card = $this->card('Finish me');
        self::assertNull($card->completedAt);

        ($this->moveCard)(new MoveCardCommand($card, CardReporter::Human, $this->column($this->project, 'done')));

        self::assertSame('done', $card->column->slug);
        self::assertNotNull($this->storedCompletion($card));
    }

    public function test_a_move_inside_done_keeps_the_first_completion(): void
    {
        $card = $this->card('Finish me');
        ($this->moveCard)(new MoveCardCommand($card, CardReporter::Human, $this->column($this->project, 'done')));
        $completedAt = $this->storedCompletion($card);
        self::assertNotNull($completedAt);

        ($this->moveCard)(new MoveCardCommand($card, CardReporter::Human, $this->column($this->project, 'done')));

        self::assertSame($completedAt, $this->storedCompletion($card));
    }

    public function test_leaving_done_clears_the_completion(): void
    {
        $card = $this->card('Finish me');
        ($this->moveCard)(new MoveCardCommand($card, CardReporter::Human, $this->column($this->project, 'done')));
        // Guard: without it the assertion below also passes on a card that was
        // never stamped in the first place.
        self::assertNotNull($card->completedAt);

        ($this->moveCard)(new MoveCardCommand($card, CardReporter::Human, $this->column($this->project, 'in-progress')));

        self::assertNull($this->storedCompletion($card));
    }

    public function test_a_card_created_straight_into_done_is_stamped(): void
    {
        $card = ($this->createCard)(new CreateCardCommand(
            project: $this->project,
            title: 'Already done',
            body: 'Body',
            type: CardType::Docs,
            column: $this->column($this->project, 'done'),
        ));

        self::assertNotNull($card->completedAt);
        self::assertSame(0, $card->position);
    }

    public function test_an_update_that_changes_status_moves_the_card(): void
    {
        $incumbent = $this->card('Already done', 'next');
        $card = $this->card('Change me');

        ($this->updateCard)(new UpdateCardCommand(card: $card, actor: CardReporter::Agent, title: 'Changed', column: $this->column($this->project, 'next')));

        self::assertSame('Changed', $card->title);
        self::assertSame('next', $card->column->slug);
        self::assertSame(0, $incumbent->position);
        self::assertSame(1, $card->position);
    }

    public function test_an_update_that_changes_the_fields_and_the_column_applies_both(): void
    {
        $incumbent = $this->card('Already next', 'next');
        $card = $this->card('Change me');

        ($this->updateCard)(new UpdateCardCommand(
            card: $card,
            actor: CardReporter::Agent,
            title: 'Changed',
            body: 'Rewritten',
            type: CardType::Bug,
            column: $this->column($this->project, 'next'),
        ));

        $this->em->clear();
        $stored = $this->cards->find($card->id);
        $storedIncumbent = $this->cards->find($incumbent->id);
        self::assertInstanceOf(Card::class, $stored);
        self::assertInstanceOf(Card::class, $storedIncumbent);
        self::assertSame(['Changed', 'Rewritten'], [$stored->title, $stored->body]);
        self::assertSame(CardType::Bug, $stored->type);
        self::assertSame('next', $stored->column->slug);
        self::assertSame([0, 1], [$storedIncumbent->position, $stored->position]);
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

    private function card(string $title, string $column = 'backlog'): Card
    {
        return ($this->createCard)(new CreateCardCommand(
            project: $this->project,
            title: $title,
            body: 'Body of '.$title,
            type: CardType::Feature,
            column: $this->column($this->project, $column),
        ));
    }
}
