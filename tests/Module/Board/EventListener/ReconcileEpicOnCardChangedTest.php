<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\BoardEventType;
use App\Module\Board\Command\CardLinkInput;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\DeleteCardCommand;
use App\Module\Board\Command\DeleteCardHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Install\BoardInstallFlags;
use App\Module\Board\Messenger\MoveAbandonedCard;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Project\Entity\Project;
use App\Outbox\Repository\OutboxEventRepository;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Ubermuda\FeatureFlagsBundle\Reader\DoctrineFeatureFlagReader;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class ReconcileEpicOnCardChangedTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private CreateCardHandler $createCard;
    private UpdateCardHandler $updateCard;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $create = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $create);
        $this->createCard = $create;

        $update = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $update);
        $this->updateCard = $update;

        $this->enableBoard();
    }

    public function test_the_last_child_to_finish_closes_its_epic(): void
    {
        $project = $this->lifecycleProject('epic-close');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $first = $this->card($project, parent: $epic);
        $second = $this->card($project, parent: $epic);
        $third = $this->card($project, parent: $epic);

        $this->update($first, 'done');
        $this->update($second, 'done');
        self::assertSame('implementation', $this->slugOf($epic));

        $this->update($third, 'done');

        self::assertSame('done', $this->slugOf($epic));
        self::assertNotNull($this->reload($epic)->completedAt);
        $last = $this->movedRows($project)[3];
        self::assertSame(['cardNumber' => $epic->number, 'fromStatus' => 'implementation', 'toStatus' => 'done', 'actor' => 'system'], $last);
        self::assertEquals(['type' => 'epic-reconciled', 'child' => $third->number], $this->lastCause($epic));
    }

    public function test_the_epic_closes_in_the_first_terminal_column_in_board_order(): void
    {
        $project = $this->lifecycleProject('epic-close-first-terminal');
        $archive = new BoardColumn($this->reloadProject($project), 'Archive', 'archive', 10, terminal: true);
        $this->em->persist($archive);
        $this->em->flush();
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $child = $this->card($project, parent: $epic);

        $this->update($child, 'archive');

        self::assertSame('done', $this->slugOf($epic));
    }

    public function test_a_child_that_reopens_moves_its_done_epic_back_to_implementation(): void
    {
        $project = $this->lifecycleProject('epic-reopen');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $child = $this->card($project, parent: $epic);
        $this->update($child, 'done');
        self::assertSame('done', $this->slugOf($epic));

        $this->update($child, 'next');

        self::assertSame('implementation', $this->slugOf($epic));
        $last = array_last($this->movedRows($project));
        self::assertSame(['cardNumber' => $epic->number, 'fromStatus' => 'done', 'toStatus' => 'implementation', 'actor' => 'system'], $last);
    }

    public function test_an_open_card_that_joins_a_done_epic_reopens_it(): void
    {
        $project = $this->lifecycleProject('epic-join');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $this->update($this->card($project, parent: $epic), 'done');
        self::assertSame('done', $this->slugOf($epic));

        $joining = $this->card($project);

        $this->update($joining, parentCardId: (string) $epic->id);

        self::assertSame('implementation', $this->slugOf($epic));
        self::assertEquals(['type' => 'epic-reconciled', 'child' => $joining->number], $this->lastCause($epic));
    }

    public function test_the_epic_closes_when_its_only_open_child_leaves_it(): void
    {
        $project = $this->lifecycleProject('epic-leave');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $this->update($this->card($project, parent: $epic), 'done');
        $open = $this->card($project, parent: $epic);
        self::assertSame('implementation', $this->slugOf($epic));

        $this->update($open, parentCardId: '');

        self::assertSame('done', $this->slugOf($epic));
    }

    public function test_the_epic_closes_when_its_only_open_child_is_deleted(): void
    {
        $project = $this->lifecycleProject('epic-delete-open');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $this->update($this->card($project, parent: $epic), 'done');
        $open = $this->card($project, parent: $epic);
        self::assertSame('implementation', $this->slugOf($epic));

        $this->delete($open);

        self::assertSame('done', $this->slugOf($epic));
    }

    public function test_an_epic_whose_last_child_is_deleted_does_not_move(): void
    {
        $project = $this->lifecycleProject('epic-delete-last');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $only = $this->card($project, parent: $epic);

        $this->delete($only);

        self::assertSame(0, $this->countChildren($epic));
        self::assertSame('implementation', $this->slugOf($epic));
    }

    public function test_an_epic_left_with_no_children_does_not_move(): void
    {
        $project = $this->lifecycleProject('epic-empty');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $only = $this->card($project, parent: $epic);

        $this->update($only, parentCardId: '');

        self::assertSame(0, $this->countChildren($epic));
        self::assertSame('implementation', $this->slugOf($epic));
    }

    public function test_a_board_with_no_terminal_column_never_closes_an_epic(): void
    {
        $project = $this->lifecycleProject('epic-no-terminal');
        $done = $this->column($project, 'done');
        $done->terminal = false;
        $this->em->flush();
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $child = $this->card($project, parent: $epic);

        $this->update($child, 'done');

        self::assertSame('implementation', $this->slugOf($epic));
        self::assertCount(1, $this->movedRows($project));
    }

    public function test_a_board_with_no_implementation_column_leaves_a_done_epic_alone(): void
    {
        $project = $this->makeProject('epic-no-implementation');
        $epic = $this->card($project, 'in-progress', CardType::Epic);
        $child = $this->card($project, parent: $epic);
        $this->update($child, 'done');
        self::assertSame('done', $this->slugOf($epic));

        $this->update($child, 'next');

        self::assertSame('done', $this->slugOf($epic));
    }

    public function test_a_terminal_implementation_column_leaves_a_done_epic_alone(): void
    {
        $project = $this->lifecycleProject('epic-terminal-implementation');
        $epic = $this->card($project, 'in-progress', CardType::Epic);
        $child = $this->card($project, parent: $epic);
        $this->update($child, 'done');
        self::assertSame('done', $this->slugOf($epic));
        $this->markImplementationTerminal($project);

        $this->update($child, 'next');

        self::assertSame('done', $this->slugOf($epic));
    }

    public function test_a_terminal_implementation_column_releases_nothing(): void
    {
        $project = $this->lifecycleProject('epic-release-terminal-implementation');
        $this->markImplementationTerminal($project);
        $epic = $this->card($project, 'in-progress', CardType::Epic);
        $blocker = $this->card($project, parent: $epic);
        $blocked = $this->card($project, parent: $epic, blockedBy: [$blocker]);

        $this->update($blocker, 'done');

        self::assertSame('backlog', $this->slugOf($blocked));
    }

    public function test_a_child_whose_last_blocker_finishes_starts_in_implementation(): void
    {
        $project = $this->lifecycleProject('epic-release');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $blocker = $this->card($project, parent: $epic);
        $blocked = $this->card($project, parent: $epic, blockedBy: [$blocker]);

        $this->update($blocker, 'done');

        self::assertSame('implementation', $this->slugOf($blocked));
        self::assertSame('implementation', $this->slugOf($epic));
        $rows = $this->movedRows($project);
        self::assertCount(2, $rows);
        self::assertSame(['cardNumber' => $blocked->number, 'fromStatus' => 'backlog', 'toStatus' => 'implementation', 'actor' => 'system'], $rows[1]);
        self::assertEquals(['type' => 'unblocked', 'blocker' => $blocker->number], $this->lastCause($blocked));
    }

    public function test_a_blocked_card_with_no_parent_stays_in_the_backlog(): void
    {
        $project = $this->lifecycleProject('epic-release-no-parent');
        $blocker = $this->card($project);
        $blocked = $this->card($project, blockedBy: [$blocker]);

        $this->update($blocker, 'done');

        self::assertSame('backlog', $this->slugOf($blocked));
    }

    public function test_a_blocked_child_outside_the_default_column_stays_where_it_is(): void
    {
        $project = $this->lifecycleProject('epic-release-not-default');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $blocker = $this->card($project, parent: $epic);
        $blocked = $this->card($project, 'next', parent: $epic, blockedBy: [$blocker]);

        $this->update($blocker, 'done');

        self::assertSame('next', $this->slugOf($blocked));
    }

    public function test_a_blocked_child_waits_while_another_blocker_is_open(): void
    {
        $project = $this->lifecycleProject('epic-release-other-blocker');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $first = $this->card($project, parent: $epic);
        $second = $this->card($project, parent: $epic);
        $blocked = $this->card($project, parent: $epic, blockedBy: [$first, $second]);

        $this->update($first, 'done');
        self::assertSame('backlog', $this->slugOf($blocked));

        $this->update($second, 'done');
        self::assertSame('implementation', $this->slugOf($blocked));
    }

    public function test_a_card_that_blocks_nothing_releases_nothing(): void
    {
        $project = $this->lifecycleProject('epic-release-direction');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $blocked = $this->card($project, parent: $epic);
        // The link runs the other way: the moved card is the one that waits.
        $mover = $this->card($project, parent: $epic, blockedBy: [$blocked]);

        $this->update($mover, 'done');

        self::assertSame('backlog', $this->slugOf($blocked));
    }

    public function test_a_board_with_no_implementation_column_releases_nothing(): void
    {
        $project = $this->makeProject('epic-release-no-implementation');
        $epic = $this->card($project, 'in-progress', CardType::Epic);
        $blocker = $this->card($project, parent: $epic);
        $blocked = $this->card($project, parent: $epic, blockedBy: [$blocker]);

        $this->update($blocker, 'done');

        self::assertSame('backlog', $this->slugOf($blocked));
    }

    /**
     * The epic's own move is automatic, and it releases the cards the epic
     * blocks. Those land in an open column, so the chain ends there.
     */
    public function test_the_chain_of_automatic_moves_stops_after_one_level(): void
    {
        $project = $this->lifecycleProject('epic-chain');
        $first = $this->card($project, 'implementation', CardType::Epic);
        $second = $this->card($project, 'implementation', CardType::Epic);
        $child = $this->card($project, parent: $first);
        $waiting = $this->card($project, parent: $second, blockedBy: [$first]);

        $this->update($child, 'done');

        self::assertSame('done', $this->slugOf($first));
        self::assertSame('implementation', $this->slugOf($waiting));
        self::assertSame('implementation', $this->slugOf($second));
        self::assertSame([
            ['cardNumber' => $child->number, 'fromStatus' => 'backlog', 'toStatus' => 'done', 'actor' => 'agent'],
            ['cardNumber' => $first->number, 'fromStatus' => 'implementation', 'toStatus' => 'done', 'actor' => 'system'],
            ['cardNumber' => $waiting->number, 'fromStatus' => 'backlog', 'toStatus' => 'implementation', 'actor' => 'system'],
        ], $this->movedRows($project));
    }

    public function test_the_last_child_to_finish_holds_an_epic_with_an_open_pull_request_in_review(): void
    {
        $project = $this->reviewProject('epic-hold-open');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $this->linkPullRequest($epic, 7, PullRequestState::Open);
        $child = $this->card($project, parent: $epic);

        $this->update($child, 'done');

        self::assertSame('in-review', $this->slugOf($epic));
        self::assertNull($this->reload($epic)->completedAt);
        self::assertEquals(['type' => 'epic-reconciled', 'child' => $child->number], $this->lastCause($epic));
        self::assertSame([], $this->queuedAbandonedCards());
    }

    public function test_a_pull_request_forge_never_read_holds_the_epic_in_review(): void
    {
        $project = $this->reviewProject('epic-hold-unread');
        $epic = $this->card($project, 'next', CardType::Epic);
        $this->linkPullRequest($epic, 7, null);
        $child = $this->card($project, parent: $epic);

        $this->update($child, 'done');

        self::assertSame('in-review', $this->slugOf($epic));
    }

    public function test_an_unparsed_pull_request_link_holds_the_epic_in_review(): void
    {
        $project = $this->reviewProject('epic-hold-unparsed');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $card = $this->reload($epic);
        $this->em->persist(new CardPullRequest($card, 'https://git.example.com/acme/widgets/merge/7'));
        $this->em->flush();
        $this->em->clear();
        $child = $this->card($project, parent: $epic);

        $this->update($child, 'done');

        self::assertSame('in-review', $this->slugOf($epic));
    }

    public function test_the_last_child_to_finish_closes_an_epic_with_no_pull_request_on_a_board_with_review(): void
    {
        $project = $this->reviewProject('epic-close-no-pr');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $child = $this->card($project, parent: $epic);

        $this->update($child, 'done');

        self::assertSame('done', $this->slugOf($epic));
    }

    public function test_the_last_child_to_finish_closes_an_epic_whose_pull_request_merged(): void
    {
        $project = $this->reviewProject('epic-close-merged');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $this->linkPullRequest($epic, 7, PullRequestState::Merged);
        $child = $this->card($project, parent: $epic);

        $this->update($child, 'done');

        self::assertSame('done', $this->slugOf($epic));
    }

    public function test_an_epic_whose_pull_request_closed_unmerged_queues_its_backlog_move_when_the_last_child_finishes(): void
    {
        $project = $this->reviewProject('epic-abandoned');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $this->linkPullRequest($epic, 7, PullRequestState::Closed);
        $child = $this->card($project, parent: $epic);

        $this->update($child, 'done');

        self::assertSame('implementation', $this->slugOf($epic));
        self::assertCount(1, $this->movedRows($project));
        self::assertSame([(string) $epic->id], $this->queuedAbandonedCards());
    }

    public function test_an_epic_in_the_backlog_stays_when_its_only_pull_request_closed_unmerged(): void
    {
        $project = $this->reviewProject('epic-backlog-abandoned');
        $epic = $this->card($project, 'backlog', CardType::Epic);
        $this->linkPullRequest($epic, 7, PullRequestState::Closed);
        $child = $this->card($project, parent: $epic);

        $this->update($child, 'done');

        self::assertSame('backlog', $this->slugOf($epic));
        self::assertSame([], $this->queuedAbandonedCards());
    }

    public function test_an_epic_with_an_abandoned_pull_request_queues_nothing_while_automation_is_off(): void
    {
        $project = $this->reviewProject('epic-abandoned-automation-off');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $this->linkPullRequest($epic, 7, PullRequestState::Closed);
        $child = $this->card($project, parent: $epic);
        $automation = self::getContainer()->get(BoardAutomation::class);
        self::assertInstanceOf(BoardAutomation::class, $automation);
        $automation->settingsForUpdate($this->reloadProject($project))->enabled = false;
        $this->em->flush();
        $this->em->clear();

        $this->update($child, 'done');

        self::assertSame('implementation', $this->slugOf($epic));
        self::assertSame([], $this->queuedAbandonedCards());
    }

    public function test_a_finished_child_that_moves_between_terminal_columns_does_not_queue_again(): void
    {
        $project = $this->reviewProject('epic-abandoned-terminal-move');
        $this->em->persist(new BoardColumn($this->reloadProject($project), 'Archive', 'archive', 10, terminal: true));
        $this->em->flush();
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $this->linkPullRequest($epic, 7, PullRequestState::Closed);
        $child = $this->card($project, parent: $epic);
        $this->update($child, 'done');
        self::assertSame([(string) $epic->id], $this->queuedAbandonedCards());

        $this->update($child, 'archive');

        self::assertSame([(string) $epic->id], $this->queuedAbandonedCards());
    }

    public function test_a_finished_child_that_leaves_the_epic_does_not_queue_again(): void
    {
        $project = $this->reviewProject('epic-abandoned-finished-leaves');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $this->linkPullRequest($epic, 7, PullRequestState::Closed);
        $child = $this->card($project, parent: $epic);
        $this->card($project, 'done', parent: $epic);
        $this->update($child, 'done');
        self::assertSame([(string) $epic->id], $this->queuedAbandonedCards());

        $this->update($child, parentCardId: '');

        self::assertSame([(string) $epic->id], $this->queuedAbandonedCards());
    }

    public function test_the_last_open_child_that_leaves_the_epic_queues_its_backlog_move(): void
    {
        $project = $this->reviewProject('epic-abandoned-open-leaves');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $this->linkPullRequest($epic, 7, PullRequestState::Closed);
        $open = $this->card($project, parent: $epic);
        $this->card($project, 'done', parent: $epic);
        self::assertSame([], $this->queuedAbandonedCards());

        $this->update($open, parentCardId: '');

        self::assertSame([(string) $epic->id], $this->queuedAbandonedCards());
    }

    public function test_a_child_that_finishes_and_leaves_in_one_update_queues_for_its_old_epic(): void
    {
        $project = $this->reviewProject('epic-abandoned-finish-and-leave');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $this->linkPullRequest($epic, 7, PullRequestState::Closed);
        $child = $this->card($project, parent: $epic);
        $this->card($project, 'done', parent: $epic);

        $this->update($child, 'done', parentCardId: '');

        self::assertSame([(string) $epic->id], $this->queuedAbandonedCards());
    }

    public function test_a_consumed_move_lets_the_next_change_queue_again(): void
    {
        $project = $this->reviewProject('epic-abandoned-consumed');
        $this->em->persist(new BoardColumn($this->reloadProject($project), 'Archive', 'archive', 10, terminal: true));
        $this->em->flush();
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $this->linkPullRequest($epic, 7, PullRequestState::Closed);
        $child = $this->card($project, parent: $epic);
        $this->update($child, 'done');
        $this->em->getConnection()->executeStatement(
            'UPDATE board_card_automations SET abandoned_move_token = NULL WHERE card_id = :card',
            ['card' => (string) $epic->id],
        );

        $this->update($child, 'archive');

        self::assertSame([(string) $epic->id, (string) $epic->id], $this->queuedAbandonedCards());
    }

    public function test_a_finished_first_child_queues_the_backlog_move(): void
    {
        $project = $this->reviewProject('epic-abandoned-finished-first');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $this->linkPullRequest($epic, 7, PullRequestState::Closed);

        $this->card($project, 'done', parent: $epic);

        self::assertSame([(string) $epic->id], $this->queuedAbandonedCards());
    }

    public function test_a_board_with_no_review_column_queues_the_backlog_move_of_an_epic_with_an_abandoned_pull_request(): void
    {
        $project = $this->lifecycleProject('epic-no-review-abandoned');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $this->linkPullRequest($epic, 7, PullRequestState::Closed);
        $child = $this->card($project, parent: $epic);

        $this->update($child, 'done');

        self::assertSame('implementation', $this->slugOf($epic));
        self::assertSame([(string) $epic->id], $this->queuedAbandonedCards());
    }

    public function test_an_epic_closes_when_its_pull_request_merged_before_the_last_child(): void
    {
        $project = $this->reviewProject('epic-merged-first');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $this->linkPullRequest($epic, 7, PullRequestState::Merged);
        $this->linkPullRequest($epic, 8, PullRequestState::Closed);
        $child = $this->card($project, parent: $epic);

        $this->update($child, 'done');

        self::assertSame('done', $this->slugOf($epic));
        self::assertSame([], $this->queuedAbandonedCards());
    }

    public function test_an_epic_in_review_that_gains_an_open_child_returns_to_implementation(): void
    {
        $project = $this->reviewProject('epic-review-reopen');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $this->linkPullRequest($epic, 7, PullRequestState::Open);
        $this->update($this->card($project, parent: $epic), 'done');
        self::assertSame('in-review', $this->slugOf($epic));

        $joining = $this->card($project);
        $this->update($joining, parentCardId: (string) $epic->id);

        self::assertSame('implementation', $this->slugOf($epic));
        self::assertEquals(['type' => 'epic-reconciled', 'child' => $joining->number], $this->lastCause($epic));

        $this->update($joining, 'done');

        self::assertSame('in-review', $this->slugOf($epic));
    }

    public function test_a_board_with_no_review_column_closes_an_epic_with_an_open_pull_request(): void
    {
        $project = $this->lifecycleProject('epic-no-review');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $this->linkPullRequest($epic, 7, PullRequestState::Open);
        $child = $this->card($project, parent: $epic);

        $this->update($child, 'done');

        self::assertSame('done', $this->slugOf($epic));
    }

    public function test_a_terminal_review_column_does_not_hold_the_epic(): void
    {
        $project = $this->lifecycleProject('epic-terminal-review');
        $this->em->persist(new BoardColumn($this->reloadProject($project), 'In review', 'in-review', 5, terminal: true));
        $this->em->flush();
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $this->linkPullRequest($epic, 7, PullRequestState::Open);
        $child = $this->card($project, parent: $epic);

        $this->update($child, 'done');

        self::assertSame('done', $this->slugOf($epic));
    }

    public function test_a_closed_epic_with_an_open_pull_request_stays_closed(): void
    {
        $project = $this->reviewProject('epic-closed-stays');
        $epic = $this->card($project, 'done', CardType::Epic);
        $this->linkPullRequest($epic, 7, PullRequestState::Open);
        $this->card($project, 'done', parent: $epic);
        $leaving = $this->card($project, 'done', parent: $epic);

        $this->update($leaving, parentCardId: '');

        self::assertSame('done', $this->slugOf($epic));
    }

    public function test_nothing_moves_while_the_board_is_switched_off(): void
    {
        $project = $this->lifecycleProject('epic-flag-off');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $child = $this->card($project, parent: $epic);
        $this->disableBoard();

        $this->update($child, 'done');

        self::assertSame('implementation', $this->slugOf($epic));
    }

    /** The four seeded columns and an open `implementation` column after them. */
    private function lifecycleProject(string $label): Project
    {
        $project = $this->makeProject($label);
        $this->em->persist(new BoardColumn($project, 'Implementation', 'implementation', 4));
        $this->em->flush();

        return $project;
    }

    /** A lifecycle board with an open `in-review` column after `implementation`. */
    private function reviewProject(string $label): Project
    {
        $project = $this->lifecycleProject($label);
        $this->em->persist(new BoardColumn($this->reloadProject($project), 'In review', 'in-review', 5));
        $this->em->flush();

        return $project;
    }

    /** A null state links a pull request that Forge never read. */
    private function linkPullRequest(Card $card, int $number, ?PullRequestState $state): void
    {
        $fresh = $this->reload($card);
        $this->em->persist(new CardPullRequest($fresh, 'https://github.com/Acme/Widgets/pull/'.$number, Forge::GitHub, 'Acme/Widgets', $number));
        if (null !== $state) {
            $row = new ForgePullRequest($fresh->project, 'github', 'Acme/Widgets', $number);
            $row->state = $state;
            $this->em->persist($row);
        }
        $this->em->flush();
        $this->em->clear();
    }

    /** @return list<string> the cards of the queued Backlog moves, each with a ten-minute delay */
    private function queuedAbandonedCards(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $cards = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if (!$message instanceof MoveAbandonedCard) {
                continue;
            }
            self::assertSame(600_000, $envelope->last(DelayStamp::class)?->getDelay());
            self::assertSame(['async'], $envelope->last(TransportNamesStamp::class)?->getTransportNames());
            $cards[] = (string) $message->cardId;
        }

        return $cards;
    }

    private function delete(Card $card): void
    {
        $delete = self::getContainer()->get(DeleteCardHandler::class);
        self::assertInstanceOf(DeleteCardHandler::class, $delete);
        $delete(new DeleteCardCommand($this->reload($card), CardReporter::Human));
        $this->em->clear();
    }

    private function markImplementationTerminal(Project $project): void
    {
        $this->column($project, 'implementation')->terminal = true;
        $this->em->flush();
        $this->em->clear();
    }

    /** @param list<Card> $blockedBy */
    private function card(
        Project $project,
        string $column = 'backlog',
        CardType $type = CardType::Feature,
        ?Card $parent = null,
        array $blockedBy = [],
    ): Card {
        $card = ($this->createCard)(new CreateCardCommand(
            $this->reloadProject($project),
            'Card',
            '',
            $type,
            column: $this->column($project, $column),
            relatedCards: array_map(static fn (Card $blocker): CardLinkInput => new CardLinkInput((string) $blocker->id, CardLinkKind::BlockedBy), $blockedBy),
            parentCardId: null === $parent ? null : (string) $parent->id,
        ));
        $this->em->clear();

        return $card;
    }

    private function update(Card $card, ?string $column = null, ?string $parentCardId = null): void
    {
        $fresh = $this->reload($card);
        ($this->updateCard)(new UpdateCardCommand(
            $fresh,
            CardReporter::Agent,
            column: null === $column ? null : $this->column($fresh->project, $column),
            parentCardId: $parentCardId,
        ));
        $this->em->clear();
    }

    private function slugOf(Card $card): string
    {
        return (string) $this->em->getConnection()->fetchOne(
            'SELECT k.slug FROM board_cards c JOIN board_columns k ON k.id = c.column_id WHERE c.id = ?',
            [(string) $card->id],
        );
    }

    private function countChildren(Card $card): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM board_cards WHERE parent_card_id = ?', [(string) $card->id]);
    }

    /** @return list<array{cardNumber: mixed, fromStatus: mixed, toStatus: mixed, actor: mixed}> */
    private function movedRows(Project $project): array
    {
        $outbox = self::getContainer()->get(OutboxEventRepository::class);
        self::assertInstanceOf(OutboxEventRepository::class, $outbox);

        $rows = [];
        foreach ($outbox->findBy(['project' => $project->id, 'type' => BoardEventType::CARD_MOVED], ['sequence' => 'ASC']) as $row) {
            $payload = json_decode($row->payload, true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($payload);
            $rows[] = [
                'cardNumber' => $payload['cardNumber'] ?? null,
                'fromStatus' => $payload['fromStatus'] ?? null,
                'toStatus' => $payload['toStatus'] ?? null,
                'actor' => $payload['actor'] ?? null,
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed>|null the cause of the newest move in the card's history */
    private function lastCause(Card $card): ?array
    {
        $detail = $this->em->getConnection()->fetchOne(
            "SELECT detail FROM board_card_events WHERE card_id = :card AND kind = 'moved' ORDER BY occurred_at DESC, id DESC LIMIT 1",
            ['card' => (string) $card->id],
        );
        self::assertIsString($detail);

        return json_decode($detail, true, flags: \JSON_THROW_ON_ERROR)['cause'];
    }

    private function disableBoard(): void
    {
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[BoardInstallFlags::FLAG_BOARD_ENABLED]->value = false;
        $this->em->flush();
        // The reader keeps the flags it read for the rest of the request.
        $reader = self::getContainer()->get(DoctrineFeatureFlagReader::class);
        self::assertInstanceOf(DoctrineFeatureFlagReader::class, $reader);
        $reader->reset();
    }

    private function reload(Card $card): Card
    {
        return $this->em->find(Card::class, $card->id) ?? throw new \LogicException('The card must exist.');
    }

    private function reloadProject(Project $project): Project
    {
        return $this->em->find(Project::class, $project->id) ?? throw new \LogicException('The project must exist.');
    }
}
