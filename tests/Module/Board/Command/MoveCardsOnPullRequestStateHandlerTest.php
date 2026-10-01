<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Board\Command\MoveCardsOnPullRequestStateCommand;
use App\Module\Board\Command\MoveCardsOnPullRequestStateHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Messenger\MoveAbandonedCard;
use App\Module\Board\Messenger\MoveAbandonedCardHandler;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Event\PullRequestStateChanged;
use App\Module\Forge\PullRequestSnapshot;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class MoveCardsOnPullRequestStateHandlerTest extends KernelTestCase
{
    use BoardToolScenario;

    private const string SHA = 'abc1234def';

    private EntityManagerInterface $em;
    private Project $project;
    private ForgePullRequest $pullRequest;
    private int $cardNumber = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->enableBoard();
        $this->project = $this->makeProject('move-on-pull-request');
        $this->addColumns('implementation', 'in-review');
        $this->pullRequest = new ForgePullRequest($this->project, 'github', 'Acme/Widgets', 5);
        $this->em->persist($this->pullRequest);
        $this->em->flush();
    }

    public function test_green_checks_move_a_card_from_implementation_to_in_review(): void
    {
        $card = $this->linkedCard('implementation');

        $this->handle(new PullRequestSnapshot(), $this->passed());

        self::assertSame('in-review', $card->column->slug);
        self::assertSame([['fromStatus' => 'implementation', 'toStatus' => 'in-review', 'actor' => 'system']], $this->moves());
        self::assertEquals([['system', null, 'implementation', 'in-review', ['type' => 'checks-passed', 'pullRequest' => 5]]], $this->history($card));
    }

    public function test_green_checks_on_the_same_sha_again_move_nothing(): void
    {
        $card = $this->linkedCard('implementation');

        $this->handle($this->passed(), $this->passed());

        self::assertSame('implementation', $card->column->slug);
    }

    public function test_a_draft_with_green_checks_moves_the_card_when_it_is_marked_ready(): void
    {
        $card = $this->linkedCard('implementation');
        $this->handle(new PullRequestSnapshot(), $this->passed(draft: true));
        self::assertSame('implementation', $card->column->slug);

        $this->handle($this->passed(draft: true), $this->passed());

        self::assertSame('in-review', $card->column->slug);
    }

    public function test_a_card_a_person_moved_since_the_read_keeps_its_column_on_green_checks(): void
    {
        $card = $this->linkedCard('implementation');
        $this->moveBehindTheEntity($card, 'done');

        $this->handle(new PullRequestSnapshot(), $this->passed());

        self::assertSame('done', $this->storedColumnOf($card));
        self::assertSame([], $this->moves());
    }

    public function test_a_card_a_person_finished_since_the_read_keeps_its_column_on_a_merge(): void
    {
        $this->addColumns('shipped');
        $this->column($this->project, 'shipped')->terminal = true;
        $this->em->flush();
        $card = $this->linkedCard('in-review');
        $this->moveBehindTheEntity($card, 'shipped');

        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(state: PullRequestState::Merged));

        self::assertSame('shipped', $this->storedColumnOf($card));
        self::assertSame([], $this->moves());
    }

    public function test_green_checks_leave_a_card_in_another_column(): void
    {
        $card = $this->linkedCard('in-progress');

        $this->handle(new PullRequestSnapshot(), $this->passed());

        self::assertSame('in-progress', $card->column->slug);
        self::assertSame([], $this->moves());
    }

    public function test_green_checks_on_a_draft_move_nothing(): void
    {
        $card = $this->linkedCard('implementation');

        $this->handle(new PullRequestSnapshot(draft: true), $this->passed(draft: true));

        self::assertSame('implementation', $card->column->slug);
    }

    public function test_green_checks_on_a_closed_pull_request_do_not_move_the_card_to_in_review(): void
    {
        $card = $this->linkedCard('implementation');
        $closed = new PullRequestSnapshot(state: PullRequestState::Closed);

        $this->handle($closed, $this->passed(state: PullRequestState::Closed));

        self::assertSame('implementation', $card->column->slug);
    }

    public function test_disabled_automation_moves_nothing(): void
    {
        $implementation = $this->linkedCard('implementation');
        $merging = $this->linkedCard('in-review');
        $this->disableAutomation();

        $this->handle(new PullRequestSnapshot(), $this->passed());
        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(state: PullRequestState::Merged));

        self::assertSame('implementation', $implementation->column->slug);
        self::assertSame('in-review', $merging->column->slug);
        self::assertSame([], $this->moves());
    }

    public function test_a_board_without_in_review_moves_nothing_on_green_checks(): void
    {
        $this->project = $this->makeProject('no-in-review');
        $this->addColumns('implementation');
        $this->pullRequest = new ForgePullRequest($this->project, 'github', 'Acme/Widgets', 5);
        $this->em->persist($this->pullRequest);
        $this->em->flush();
        $card = $this->linkedCard('implementation');

        $this->handle(new PullRequestSnapshot(), $this->passed());

        self::assertSame('implementation', $card->column->slug);
    }

    public function test_a_terminal_in_review_column_takes_no_card_on_green_checks(): void
    {
        $this->project = $this->makeProject('terminal-in-review');
        $this->em->persist(new BoardColumn(project: $this->project, label: 'implementation', slug: 'implementation', position: 10));
        $this->em->persist(new BoardColumn(project: $this->project, label: 'in-review', slug: 'in-review', position: 11, terminal: true));
        $this->pullRequest = new ForgePullRequest($this->project, 'github', 'Acme/Widgets', 5);
        $this->em->persist($this->pullRequest);
        $this->em->flush();
        $card = $this->linkedCard('implementation');

        $this->handle(new PullRequestSnapshot(), $this->passed());

        self::assertSame('implementation', $card->column->slug);
        self::assertSame([], $this->moves());
    }

    public function test_a_merge_of_the_only_link_moves_the_card_to_the_first_terminal_column(): void
    {
        $card = $this->linkedCard('in-review');

        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(state: PullRequestState::Merged));

        self::assertSame('done', $card->column->slug);
        self::assertSame([['fromStatus' => 'in-review', 'toStatus' => 'done', 'actor' => 'system']], $this->moves());
        self::assertEquals([['system', null, 'in-review', 'done', ['type' => 'merged', 'pullRequest' => 5]]], $this->history($card));
    }

    public function test_a_merge_with_a_second_link_still_open_moves_nothing(): void
    {
        $card = $this->linkedCard('in-review');
        $this->alsoLink($card, 6, PullRequestState::Open);

        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(state: PullRequestState::Merged));

        self::assertSame('in-review', $card->column->slug);
    }

    public function test_a_merge_with_a_second_link_merged_moves_the_card(): void
    {
        $card = $this->linkedCard('in-review');
        $this->alsoLink($card, 6, PullRequestState::Merged);

        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(state: PullRequestState::Closed));

        self::assertSame('done', $card->column->slug);
    }

    public function test_a_merge_reads_the_other_links_as_the_database_holds_them_now(): void
    {
        $card = $this->linkedCard('in-review');
        $this->alsoLink($card, 6, PullRequestState::Open);
        $this->em->getConnection()->executeStatement(
            "UPDATE forge_pull_requests SET state = 'merged' WHERE project_id = :project AND number = 6",
            ['project' => (string) $this->project->id],
        );

        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(state: PullRequestState::Merged));

        self::assertSame('done', $this->storedColumnOf($card));
    }

    public function test_a_merge_leaves_an_epic_with_an_open_child_in_its_column(): void
    {
        $epic = $this->linkedCard('in-review');
        $epic->type = CardType::Epic;
        $child = new Card($this->project, $this->column($this->project, 'backlog'), 'A child', '', ++$this->cardNumber);
        $child->parent = $epic;
        $this->em->persist($child);
        $this->em->flush();

        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(state: PullRequestState::Merged));

        self::assertSame('in-review', $this->storedColumnOf($epic));
        self::assertSame([], $this->moves());
    }

    public function test_green_checks_leave_an_epic_with_an_open_child_in_implementation(): void
    {
        $epic = $this->linkedCard('implementation');
        $epic->type = CardType::Epic;
        $this->child($epic, 'backlog');

        $this->handle(new PullRequestSnapshot(), $this->passed());

        self::assertSame('implementation', $this->storedColumnOf($epic));
        self::assertSame([], $this->moves());
    }

    public function test_green_checks_move_an_epic_whose_children_are_all_finished(): void
    {
        $epic = $this->linkedCard('implementation');
        $epic->type = CardType::Epic;
        $this->child($epic, 'done');

        $this->handle(new PullRequestSnapshot(), $this->passed());

        self::assertSame('in-review', $this->storedColumnOf($epic));
    }

    public function test_a_merge_with_a_second_link_never_read_moves_nothing(): void
    {
        $card = $this->linkedCard('in-review');
        $this->alsoLink($card, 6, null);

        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(state: PullRequestState::Merged));

        self::assertSame('in-review', $card->column->slug);
    }

    public function test_a_merge_with_a_second_link_on_another_forge_moves_nothing(): void
    {
        $card = $this->linkedCard('in-review');
        $link = new CardPullRequest($card, 'https://git.example.com/acme/widgets/merge/6');
        $card->pullRequests->add($link);
        $this->em->persist($link);
        $this->em->flush();

        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(state: PullRequestState::Merged));

        self::assertSame('in-review', $card->column->slug);
    }

    public function test_every_link_closed_with_none_merged_moves_nothing_now_and_queues_the_backlog_move(): void
    {
        $card = $this->linkedCard('in-review');
        $this->alsoLink($card, 6, PullRequestState::Closed);

        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(state: PullRequestState::Closed));

        self::assertSame('in-review', $card->column->slug);
        self::assertSame([], $this->moves());
        self::assertSame([(string) $card->id], $this->queuedAbandonedCards());
    }

    public function test_a_close_a_reopen_and_a_second_close_leave_only_the_second_move_able_to_act(): void
    {
        $card = $this->linkedCard('in-review');

        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(state: PullRequestState::Closed));
        $this->handle(new PullRequestSnapshot(state: PullRequestState::Closed), new PullRequestSnapshot());
        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(state: PullRequestState::Closed));
        $this->pullRequest->state = PullRequestState::Closed;
        $this->em->flush();

        $messages = $this->queuedAbandonedMessages();
        self::assertCount(2, $messages);
        $handler = self::getContainer()->get(MoveAbandonedCardHandler::class);
        self::assertInstanceOf(MoveAbandonedCardHandler::class, $handler);

        $handler($messages[0]);
        self::assertSame('in-review', $this->storedColumnOf($card));

        $handler($messages[1]);
        self::assertSame('backlog', $this->storedColumnOf($card));
    }

    public function test_a_close_queues_the_backlog_move_on_a_board_without_a_terminal_column(): void
    {
        $this->column($this->project, 'done')->terminal = false;
        $this->em->flush();
        $card = $this->linkedCard('in-review');

        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(state: PullRequestState::Closed));

        self::assertSame([(string) $card->id], $this->queuedAbandonedCards());
    }

    public function test_a_close_with_another_link_merged_queues_no_backlog_move(): void
    {
        $this->column($this->project, 'done')->terminal = false;
        $this->em->flush();
        $card = $this->linkedCard('in-review');
        $this->alsoLink($card, 6, PullRequestState::Merged);

        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(state: PullRequestState::Closed));

        self::assertSame([], $this->queuedAbandonedCards());
    }

    public function test_a_close_with_another_link_open_queues_no_backlog_move(): void
    {
        $card = $this->linkedCard('in-review');
        $this->alsoLink($card, 6, PullRequestState::Open);

        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(state: PullRequestState::Closed));

        self::assertSame([], $this->queuedAbandonedCards());
    }

    public function test_a_close_with_another_link_never_read_queues_no_backlog_move(): void
    {
        $card = $this->linkedCard('in-review');
        $this->alsoLink($card, 6, null);

        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(state: PullRequestState::Closed));

        self::assertSame([], $this->queuedAbandonedCards());
    }

    public function test_a_close_queues_no_backlog_move_for_a_card_already_in_the_backlog(): void
    {
        $this->linkedCard('backlog');

        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(state: PullRequestState::Closed));

        self::assertSame([], $this->queuedAbandonedCards());
    }

    public function test_a_close_queues_no_backlog_move_while_automation_is_off(): void
    {
        $this->linkedCard('in-review');
        $this->disableAutomation();

        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(state: PullRequestState::Closed));

        self::assertSame([], $this->queuedAbandonedCards());
    }

    public function test_two_spellings_of_the_merged_pull_request_both_read_as_merged(): void
    {
        $card = $this->linkedCard('in-review');
        $link = new CardPullRequest($card, 'https://github.com/acme/widgets/pull/5', Forge::GitHub, 'acme/widgets', 5);
        $card->pullRequests->add($link);
        $this->em->persist($link);
        $this->em->flush();

        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(state: PullRequestState::Merged));

        self::assertSame('done', $card->column->slug);
    }

    public function test_a_merge_and_green_checks_in_one_read_move_the_card_to_terminal_only(): void
    {
        $card = $this->linkedCard('implementation');

        $this->handle(new PullRequestSnapshot(), $this->passed(state: PullRequestState::Merged));

        self::assertSame('done', $card->column->slug);
        self::assertSame([['fromStatus' => 'implementation', 'toStatus' => 'done', 'actor' => 'system']], $this->moves());
    }

    public function test_a_card_already_in_a_terminal_column_stays(): void
    {
        $this->addColumns('shipped');
        $shipped = $this->column($this->project, 'shipped');
        $shipped->terminal = true;
        $this->em->flush();
        $card = $this->linkedCard('shipped');

        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(state: PullRequestState::Merged));

        self::assertSame('shipped', $card->column->slug);
        self::assertSame([], $this->moves());
    }

    public function test_the_listener_moves_the_card_after_the_facts_are_written(): void
    {
        $card = $this->linkedCard('implementation');

        $this->dispatch(new PullRequestStateChanged($this->pullRequest, new PullRequestSnapshot(), $this->passed()));

        self::assertSame('in-review', $card->column->slug);
        self::assertSame(['pull_request.checks_concluded', 'board.card_moved'], $this->outboxTypes());
    }

    public function test_the_listener_does_nothing_while_the_board_is_off(): void
    {
        $card = $this->linkedCard('implementation');
        $this->disableBoard();

        $this->dispatch(new PullRequestStateChanged($this->pullRequest, new PullRequestSnapshot(), $this->passed()));

        self::assertSame('implementation', $card->column->slug);
        self::assertSame([], $this->outboxTypes());
    }

    private function handle(PullRequestSnapshot $previous, PullRequestSnapshot $current): void
    {
        $handler = self::getContainer()->get(MoveCardsOnPullRequestStateHandler::class);
        self::assertInstanceOf(MoveCardsOnPullRequestStateHandler::class, $handler);
        $handler(new MoveCardsOnPullRequestStateCommand($this->pullRequest, $previous, $current));
    }

    /** Forge dispatches the event inside its own transaction. */
    private function dispatch(object $event): void
    {
        $events = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $events);
        $this->em->wrapInTransaction(static fn (): object => $events->dispatch($event));
    }

    private function passed(PullRequestState $state = PullRequestState::Open, bool $draft = false): PullRequestSnapshot
    {
        return new PullRequestSnapshot(state: $state, draft: $draft, headSha: self::SHA, checks: PullRequestChecks::Passed, checksSha: self::SHA);
    }

    /** Another request commits a move that this entity manager has not seen. */
    private function moveBehindTheEntity(Card $card, string $slug): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE board_cards SET column_id = :column WHERE id = :card',
            ['column' => (string) $this->column($this->project, $slug)->id, 'card' => (string) $card->id],
        );
    }

    private function storedColumnOf(Card $card): string
    {
        return (string) $this->em->getConnection()->fetchOne(
            'SELECT c.slug FROM board_cards b JOIN board_columns c ON c.id = b.column_id WHERE b.id = :card',
            ['card' => (string) $card->id],
        );
    }

    private function addColumns(string ...$slugs): void
    {
        foreach (array_values($slugs) as $offset => $slug) {
            $this->em->persist(new BoardColumn(project: $this->project, label: $slug, slug: $slug, position: 10 + $offset));
        }
        $this->em->flush();
    }

    private function disableAutomation(): void
    {
        $automation = self::getContainer()->get(BoardAutomation::class);
        self::assertInstanceOf(BoardAutomation::class, $automation);
        $automation->settingsForUpdate($this->project)->enabled = false;
        $this->em->flush();
    }

    private function linkedCard(string $slug): Card
    {
        $card = new Card($this->project, $this->column($this->project, $slug), 'Ship it', '', ++$this->cardNumber);
        $link = new CardPullRequest($card, 'https://github.com/Acme/Widgets/pull/5', Forge::GitHub, 'Acme/Widgets', 5);
        $card->pullRequests->add($link);
        $this->em->persist($card);
        $this->em->persist($link);
        $this->em->flush();

        return $card;
    }

    private function child(Card $parent, string $slug): void
    {
        $child = new Card($this->project, $this->column($this->project, $slug), 'A child', '', ++$this->cardNumber);
        $child->parent = $parent;
        $this->em->persist($child);
        $this->em->flush();
    }

    /** A null state links a pull request that Forge never read. */
    private function alsoLink(Card $card, int $number, ?PullRequestState $state): void
    {
        $link = new CardPullRequest($card, 'https://github.com/Acme/Widgets/pull/'.$number, Forge::GitHub, 'Acme/Widgets', $number);
        $card->pullRequests->add($link);
        $this->em->persist($link);
        if (null !== $state) {
            $row = new ForgePullRequest($this->project, 'github', 'Acme/Widgets', $number);
            $row->state = $state;
            $this->em->persist($row);
        }
        $this->em->flush();
    }

    /** @return list<array{fromStatus: mixed, toStatus: mixed, actor: mixed}> */
    private function moves(): array
    {
        $rows = $this->em->getConnection()->fetchFirstColumn(
            "SELECT payload FROM outbox_events WHERE project_id = :project AND type = 'board.card_moved' ORDER BY sequence",
            ['project' => (string) $this->project->id],
        );

        return array_map(static function (mixed $payload): array {
            $event = json_decode((string) $payload, true, flags: \JSON_THROW_ON_ERROR);

            return ['fromStatus' => $event['fromStatus'], 'toStatus' => $event['toStatus'], 'actor' => $event['actor']];
        }, $rows);
    }

    /** @return list<array{mixed, mixed, mixed, mixed, mixed}> actor kind, actor user, from slug, to slug, cause */
    private function history(Card $card): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT actor_kind, actor_user_id, detail FROM board_card_events WHERE card_id = :card AND kind = 'moved' ORDER BY occurred_at, id",
            ['card' => (string) $card->id],
        );

        return array_map(static function (array $row): array {
            $detail = json_decode((string) $row['detail'], true, flags: \JSON_THROW_ON_ERROR);

            return [$row['actor_kind'], $row['actor_user_id'], $detail['from']['slug'], $detail['to']['slug'], $detail['cause']];
        }, $rows);
    }

    /**
     * The cards of the queued backlog moves, each with a ten-minute delay.
     *
     * @return list<string>
     */
    private function queuedAbandonedCards(): array
    {
        return array_map(static fn (MoveAbandonedCard $message): string => (string) $message->cardId, $this->queuedAbandonedMessages());
    }

    /** @return list<MoveAbandonedCard> */
    private function queuedAbandonedMessages(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $messages = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if (!$message instanceof MoveAbandonedCard) {
                continue;
            }
            $delay = $envelope->last(DelayStamp::class);
            self::assertInstanceOf(DelayStamp::class, $delay);
            self::assertSame(600_000, $delay->getDelay());
            self::assertSame(['async'], $envelope->last(TransportNamesStamp::class)?->getTransportNames());
            $messages[] = $message;
        }

        return $messages;
    }

    /** @return list<string> */
    private function outboxTypes(): array
    {
        return array_map(strval(...), $this->em->getConnection()->fetchFirstColumn(
            'SELECT type FROM outbox_events WHERE project_id = :project ORDER BY sequence',
            ['project' => (string) $this->project->id],
        ));
    }
}
