<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Board\Command\MoveCardCommand;
use App\Module\Board\Command\MoveCardHandler;
use App\Module\Board\Command\PublishPullRequestEventsCommand;
use App\Module\Board\Command\PublishPullRequestEventsHandler;
use App\Module\Board\Entity\BoardFixStrategy;
use App\Module\Board\Entity\BoardMergeStrategy;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardAutomation;
use App\Module\Board\Entity\CardAutomationAction;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\CardAutomationRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Event\PullRequestStateChanged;
use App\Module\Forge\PullRequestSnapshot;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcherInterface as SymfonyEventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

final class PublishPullRequestEventsHandlerTest extends KernelTestCase
{
    use BoardToolScenario;

    private const string SHA = 'abc1234def';

    private EntityManagerInterface $em;
    private MockClock $clock;
    private Project $project;
    private ForgePullRequest $pullRequest;
    private int $cardNumber = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = new MockClock('2026-09-28 12:00:00');
        self::getContainer()->set('clock', $this->clock);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->enableBoard();
        $this->project = $this->makeProject('pull-request-events');
        $this->pullRequest = new ForgePullRequest($this->project, 'github', 'Acme/Widgets', 5);
        $this->em->persist($this->pullRequest);
        $this->em->flush();
    }

    public function test_failed_checks_publish_the_fact_and_a_fix_request(): void
    {
        $card = $this->linkedCard();

        $this->handle(new PullRequestSnapshot(), $this->failed(self::SHA, ['phpunit', 'lint']));

        $events = $this->outbox();
        self::assertSame(['pull_request.checks_concluded', 'pull_request.fix_requested'], array_column($events, 'type'));
        self::assertSame([
            'type' => 'pull_request.checks_concluded',
            'subject' => ['type' => 'card', 'id' => (string) $card->id],
            'projectId' => (string) $this->project->id,
            'cardId' => (string) $card->id,
            'cardNumber' => $card->number,
            'forge' => 'github',
            'repository' => 'Acme/Widgets',
            'pullRequestNumber' => 5,
            'pullRequestUrl' => 'https://github.com/Acme/Widgets/pull/5',
            'headSha' => self::SHA,
            'actor' => 'system',
            'conclusion' => 'failed',
            'failedChecks' => ['phpunit', 'lint'],
        ], $events[0]);
        self::assertSame('checks-failed', $events[1]['reason']);
        self::assertArrayNotHasKey('conclusion', $events[1]);
        self::assertArrayNotHasKey('failedChecks', $events[1]);
        self::assertArrayNotHasKey('sessionId', $events[1]);

        $automation = $this->automationOf($card);
        self::assertSame(1, $automation->fixRounds);
        self::assertSame(CardAutomationAction::FixRequested, $automation->lastAction);
        self::assertEquals($this->clock->now(), $automation->lastActionAt);
    }

    public function test_passed_checks_publish_the_fact_and_reset_the_rounds(): void
    {
        $card = $this->linkedCard();
        $this->handle(new PullRequestSnapshot(), $this->failed('aaaaaaa'));
        $this->block($card, 3, 'checks-failed');

        $this->handle($this->failed('aaaaaaa'), new PullRequestSnapshot(headSha: 'bbbbbbb', checks: PullRequestChecks::Passed, checksSha: 'bbbbbbb'));

        $last = $this->outbox()[2];
        self::assertSame('pull_request.checks_concluded', $last['type']);
        self::assertSame('passed', $last['conclusion']);
        self::assertSame([], $last['failedChecks']);
        self::assertCount(3, $this->outbox());
        $automation = $this->automationOf($card);
        self::assertSame(0, $automation->fixRounds);
        self::assertNull($automation->blockedReason);
    }

    public function test_the_same_checks_on_the_same_sha_publish_once_and_a_new_sha_publishes_again(): void
    {
        $this->linkedCard();
        $this->handle(new PullRequestSnapshot(), $this->failed(self::SHA));
        $sameSha = new PullRequestSnapshot(headSha: self::SHA, checks: PullRequestChecks::Failed, checksSha: self::SHA, mergeability: PullRequestMergeability::Blocked);

        $this->handle($this->failed(self::SHA), $sameSha);
        self::assertSame(1, $this->eventsOfType('pull_request.checks_concluded'));

        $this->handle($sameSha, $this->failed('fedcba9'));
        self::assertSame(2, $this->eventsOfType('pull_request.checks_concluded'));
    }

    public function test_failed_check_names_are_cleaned(): void
    {
        $this->linkedCard();

        $this->handle(new PullRequestSnapshot(), $this->failed(self::SHA, ['{build} "linux"', '   ', str_repeat('x', 300)]));

        self::assertSame(['build linux', str_repeat('x', 200)], $this->outbox()[0]['failedChecks']);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function refusedFields(): iterable
    {
        yield 'plain http, no owner and a bad sha' => ['http://github.com/Acme/Widgets/pull/5', 'Widgets', 'NOT-A-SHA'];
        yield 'trailing newlines' => ["https://github.com/Acme/Widgets/pull/5\n", "Acme/Widgets\n", "abc1234\n"];
        yield 'no host' => ['https:///Acme/Widgets/pull/5', 'Acme/Wid gets', 'NOT-A-SHA'];
    }

    #[DataProvider('refusedFields')]
    public function test_a_field_the_bridge_refuses_is_left_out(string $url, string $repository, string $headSha): void
    {
        $this->trackRepository($repository);
        $card = $this->linkedCard($url, repository: $repository);

        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(state: PullRequestState::Merged, headSha: $headSha));

        $event = $this->outbox()[0];
        self::assertSame('pull_request.merged', $event['type']);
        self::assertSame((string) $card->id, $event['cardId']);
        self::assertArrayNotHasKey('repository', $event);
        self::assertArrayNotHasKey('pullRequestUrl', $event);
        self::assertArrayNotHasKey('headSha', $event);
    }

    public function test_a_repository_at_the_length_limit_is_kept(): void
    {
        $repository = 'Acme/'.str_repeat('w', 250);
        $this->trackRepository($repository);
        $this->linkedCard(repository: $repository);

        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(state: PullRequestState::Merged));

        self::assertSame($repository, $this->outbox()[0]['repository']);
    }

    public function test_conflict_asks_for_a_fix_and_behind_does_not(): void
    {
        $this->linkedCard();

        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(mergeability: PullRequestMergeability::Behind));
        $this->handle(new PullRequestSnapshot(mergeability: PullRequestMergeability::Behind), new PullRequestSnapshot(mergeability: PullRequestMergeability::Conflicting));
        $this->handle(new PullRequestSnapshot(mergeability: PullRequestMergeability::Conflicting), new PullRequestSnapshot(draft: true, mergeability: PullRequestMergeability::Conflicting));

        $events = $this->outbox();
        self::assertSame(['pull_request.behind', 'pull_request.conflicted', 'pull_request.fix_requested'], array_column($events, 'type'));
        self::assertSame('conflict', $events[2]['reason']);
    }

    public function test_merged_and_closed_publish_their_facts(): void
    {
        $this->linkedCard();

        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(state: PullRequestState::Closed));
        $this->handle(new PullRequestSnapshot(state: PullRequestState::Closed), new PullRequestSnapshot(state: PullRequestState::Merged));

        self::assertSame(['pull_request.closed', 'pull_request.merged'], array_column($this->outbox(), 'type'));
    }

    public function test_a_review_publishes_its_verdict_and_changes_requested_starts_a_new_round(): void
    {
        $card = $this->linkedCard();
        $this->handle(new PullRequestSnapshot(), $this->failed('aaaaaaa'));
        $this->block($card, 3, 'checks-failed');

        // The snapshot says None, as it does for a branch that requires no review.
        $this->review(PullRequestReview::ChangesRequested);
        $this->review(PullRequestReview::Approved, new PullRequestSnapshot(review: PullRequestReview::ChangesRequested));
        $this->review(PullRequestReview::Required);

        $events = array_slice($this->outbox(), 2);
        self::assertSame(['pull_request.review_submitted', 'pull_request.fix_requested', 'pull_request.review_submitted'], array_column($events, 'type'));
        self::assertSame('changes-requested', $events[0]['verdict']);
        self::assertSame('changes-requested', $events[1]['reason']);
        self::assertArrayNotHasKey('verdict', $events[1]);
        self::assertSame('approved', $events[2]['verdict']);
        self::assertSame(0, $this->automationOf($card)->fixRounds);
    }

    public function test_a_state_change_of_the_review_publishes_no_review_event(): void
    {
        $this->linkedCard();

        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(review: PullRequestReview::ChangesRequested));

        self::assertSame([], $this->outbox());
    }

    public function test_a_read_with_no_verdict_on_an_approved_pull_request_sends_nothing_and_keeps_the_rounds(): void
    {
        $card = $this->linkedCard();
        $this->handle(new PullRequestSnapshot(), $this->failed('aaaaaaa'));
        $this->block($card, 3, 'checks-failed');
        $approved = new PullRequestSnapshot(headSha: 'aaaaaaa', checks: PullRequestChecks::Failed, checksSha: 'aaaaaaa', review: PullRequestReview::Approved);

        $this->handle($approved, $approved);

        self::assertCount(2, $this->outbox());
        $automation = $this->automationOf($card);
        self::assertSame(3, $automation->fixRounds);
        self::assertSame('checks-failed', $automation->blockedReason);
    }

    public function test_a_card_in_a_terminal_column_gets_facts_and_no_decisions(): void
    {
        $card = $this->linkedCard(slug: 'done');

        $this->handle(new PullRequestSnapshot(), $this->failed(self::SHA));
        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(readyToMerge: true));

        self::assertSame(['pull_request.checks_concluded'], array_column($this->outbox(), 'type'));
        self::assertNull($this->findAutomation($card));
    }

    public function test_failed_checks_on_a_closed_pull_request_publish_the_fact_and_no_fix(): void
    {
        $card = $this->linkedCard();
        $closed = new PullRequestSnapshot(state: PullRequestState::Closed);

        $this->handle($closed, new PullRequestSnapshot(state: PullRequestState::Closed, headSha: self::SHA, checks: PullRequestChecks::Failed, checksSha: self::SHA, failedChecks: ['phpunit']));

        self::assertSame(['pull_request.checks_concluded'], array_column($this->outbox(), 'type'));
        self::assertNull($this->findAutomation($card));
    }

    public function test_changes_requested_on_a_merged_pull_request_publishes_the_review_and_no_fix(): void
    {
        $card = $this->linkedCard();

        $this->review(PullRequestReview::ChangesRequested, new PullRequestSnapshot(state: PullRequestState::Merged));

        self::assertSame(['pull_request.review_submitted'], array_column($this->outbox(), 'type'));
        self::assertNull($this->findAutomation($card));
    }

    public function test_disabled_automation_publishes_facts_and_no_decisions(): void
    {
        $card = $this->linkedCard();
        $this->configure(enabled: false);

        $this->handle(new PullRequestSnapshot(), $this->failed(self::SHA));
        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(readyToMerge: true));

        self::assertSame(['pull_request.checks_concluded'], array_column($this->outbox(), 'type'));
        self::assertNull($this->findAutomation($card));
    }

    public function test_fix_requests_stop_at_the_loop_limit(): void
    {
        $card = $this->linkedCard();
        $this->configure(loopLimit: 2);

        $this->handle(new PullRequestSnapshot(), $this->failed('aaaaaaa'));
        $this->handle($this->failed('aaaaaaa'), $this->failed('bbbbbbb'));
        $this->clock->sleep(60);
        $this->handle($this->failed('bbbbbbb'), $this->failed('ccccccc'));
        $this->handle($this->failed('ccccccc'), new PullRequestSnapshot(headSha: 'ccccccc', checks: PullRequestChecks::Failed, checksSha: 'ccccccc', mergeability: PullRequestMergeability::Conflicting));

        self::assertSame(2, $this->eventsOfType('pull_request.fix_requested'));
        self::assertSame(3, $this->eventsOfType('pull_request.checks_concluded'));
        self::assertSame(1, $this->eventsOfType('pull_request.conflicted'));
        $automation = $this->automationOf($card);
        self::assertSame(2, $automation->fixRounds);
        self::assertSame('checks-failed', $automation->blockedReason);
        self::assertSame(CardAutomationAction::Stopped, $automation->lastAction);
        self::assertEquals($this->clock->now(), $automation->lastActionAt);
        // The fourth read finds the card already stopped, so the stop is one row.
        self::assertEquals([
            ['fix-requested', 'system', null, ['reason' => 'checks-failed', 'pullRequest' => 5], '2026-09-28 12:00:00'],
            ['fix-requested', 'system', null, ['reason' => 'checks-failed', 'pullRequest' => 5], '2026-09-28 12:00:00'],
            ['stopped', 'system', null, ['reason' => 'checks-failed', 'pullRequest' => 5], '2026-09-28 12:01:00'],
        ], $this->history($card));
    }

    public function test_one_read_with_two_fix_reasons_asks_for_one_fix_and_names_the_conflict(): void
    {
        $card = $this->linkedCard();
        $this->configure(loopLimit: 1);

        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(headSha: self::SHA, checks: PullRequestChecks::Failed, checksSha: self::SHA, mergeability: PullRequestMergeability::Conflicting));

        $events = $this->outbox();
        self::assertSame(['pull_request.checks_concluded', 'pull_request.conflicted', 'pull_request.fix_requested'], array_column($events, 'type'));
        self::assertSame('conflict', $events[2]['reason']);
        $automation = $this->automationOf($card);
        self::assertSame(1, $automation->fixRounds);
        self::assertNull($automation->blockedReason);
        self::assertSame(CardAutomationAction::FixRequested, $automation->lastAction);
    }

    public function test_one_read_with_a_changes_requested_verdict_and_failed_checks_asks_for_one_fix(): void
    {
        $card = $this->linkedCard();

        $this->handler()(new PublishPullRequestEventsCommand($this->pullRequest, new PullRequestSnapshot(), $this->failed(self::SHA), PullRequestReview::ChangesRequested));

        $events = $this->outbox();
        self::assertSame(['pull_request.review_submitted', 'pull_request.checks_concluded', 'pull_request.fix_requested'], array_column($events, 'type'));
        self::assertSame('checks-failed', $events[2]['reason']);
        self::assertSame(1, $this->automationOf($card)->fixRounds);
    }

    public function test_ready_to_merge_fires_only_when_it_turns_true_under_the_worker_strategy(): void
    {
        $card = $this->linkedCard();

        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(readyToMerge: true));
        $this->handle(new PullRequestSnapshot(readyToMerge: true), new PullRequestSnapshot(draft: true, readyToMerge: true));

        self::assertSame(['pull_request.ready_to_merge'], array_column($this->outbox(), 'type'));
        self::assertSame(CardAutomationAction::ReadyToMerge, $this->automationOf($card)->lastAction);
        self::assertEquals([['ready-to-merge', 'system', null, ['pullRequest' => 5], '2026-09-28 12:00:00']], $this->history($card));

        $this->configure(mergeStrategy: BoardMergeStrategy::Off);
        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(readyToMerge: true));
        self::assertSame(1, $this->eventsOfType('pull_request.ready_to_merge'));
    }

    public function test_a_resumed_fix_names_the_session_only_while_its_bridge_is_fresh(): void
    {
        $card = $this->linkedCard();
        $this->configure(fixStrategy: BoardFixStrategy::Resume);

        $this->handle(new PullRequestSnapshot(), $this->failed('aaaaaaa'));

        $bridgeId = Uuid::v4();
        $bridge = new Bridge($this->project->owner, $bridgeId, [$this->projectId()], 'v1', $this->clock->now()->modify('-4 minutes'));
        $sessionId = Uuid::v4();
        $run = new WorkerRun($this->project, $bridgeId, $card->id ?? throw new \LogicException('Flushed.'), $card->number, 'fix', WorkerRunState::Succeeded, sessionId: $sessionId, receivedAt: $this->clock->now());
        $this->em->persist($bridge);
        $this->em->persist($run);
        $this->em->flush();
        $this->handle($this->failed('aaaaaaa'), $this->failed('bbbbbbb'));

        $bridge->lastSeenAt = $this->clock->now()->modify('-6 minutes');
        $this->em->flush();
        $this->handle($this->failed('bbbbbbb'), $this->failed('ccccccc'));

        $fixes = array_values(array_filter($this->outbox(), static fn (array $event): bool => 'pull_request.fix_requested' === $event['type']));
        self::assertCount(3, $fixes);
        self::assertArrayNotHasKey('sessionId', $fixes[0]);
        self::assertArrayNotHasKey('bridgeId', $fixes[0]);
        self::assertSame($sessionId->toRfc4122(), $fixes[1]['sessionId']);
        self::assertSame($bridgeId->toRfc4122(), $fixes[1]['bridgeId']);
        self::assertArrayNotHasKey('sessionId', $fixes[2]);
        self::assertArrayNotHasKey('bridgeId', $fixes[2]);
    }

    public function test_a_resumed_fix_names_no_session_when_its_bridge_follows_another_project(): void
    {
        $card = $this->linkedCard();
        $this->configure(fixStrategy: BoardFixStrategy::Resume);
        $bridgeId = Uuid::v4();
        $this->em->persist(new Bridge($this->project->owner, $bridgeId, [Uuid::v4()->toRfc4122()], 'v1', $this->clock->now()));
        $this->em->persist(new WorkerRun($this->project, $bridgeId, $card->id ?? throw new \LogicException('Flushed.'), $card->number, 'fix', WorkerRunState::Succeeded, sessionId: Uuid::v4(), receivedAt: $this->clock->now()));
        $this->em->flush();

        $this->handle(new PullRequestSnapshot(), $this->failed(self::SHA));

        $fixes = array_values(array_filter($this->outbox(), static fn (array $event): bool => 'pull_request.fix_requested' === $event['type']));
        self::assertCount(1, $fixes);
        self::assertArrayNotHasKey('sessionId', $fixes[0]);
        self::assertArrayNotHasKey('bridgeId', $fixes[0]);
    }

    private function projectId(): string
    {
        return ($this->project->id ?? throw new \LogicException('Flushed.'))->toRfc4122();
    }

    public function test_a_fresh_fix_names_no_session(): void
    {
        $card = $this->linkedCard();
        $bridgeId = Uuid::v4();
        $this->em->persist(new Bridge($this->project->owner, $bridgeId, [], 'v1', $this->clock->now()));
        $this->em->persist(new WorkerRun($this->project, $bridgeId, $card->id ?? throw new \LogicException('Flushed.'), $card->number, 'fix', WorkerRunState::Succeeded, sessionId: Uuid::v4()));
        $this->em->flush();

        $this->handle(new PullRequestSnapshot(), $this->failed(self::SHA));

        self::assertArrayNotHasKey('sessionId', $this->outbox()[1]);
    }

    public function test_every_card_linking_the_pull_request_gets_its_events_once(): void
    {
        $first = $this->linkedCard();
        $second = $this->linkedCard();
        $this->em->persist(new CardPullRequest($second, 'https://github.com/acme/widgets/pull/5', Forge::GitHub, 'acme/widgets', 5));
        $this->em->flush();

        $this->handle(new PullRequestSnapshot(), $this->failed(self::SHA));

        self::assertSame(
            [(string) $first->id, (string) $first->id, (string) $second->id, (string) $second->id],
            array_column($this->outbox(), 'cardId'),
        );
        self::assertSame(1, $this->automationOf($second)->fixRounds);
    }

    public function test_a_displayed_change_tells_each_linked_card_once_and_an_unchanged_read_tells_none(): void
    {
        $first = $this->linkedCard();
        $second = $this->linkedCard();
        $this->linkedCardAlso($second, 'https://github.com/acme/widgets/pull/5');
        $changes = $this->cardChanges();

        $this->handle(new PullRequestSnapshot(baseBranch: 'main'), new PullRequestSnapshot(baseBranch: 'develop'));
        self::assertSame([], $changes->getArrayCopy());

        $this->handle(new PullRequestSnapshot(), $this->failed(self::SHA));
        $expected = [[(string) $first->id, CardChanged::UPDATED, false], [(string) $second->id, CardChanged::UPDATED, false]];
        self::assertEqualsCanonicalizing($expected, $changes->getArrayCopy());
    }

    public function test_a_change_of_the_covered_head_alone_tells_the_card(): void
    {
        $card = $this->linkedCard();
        $changes = $this->cardChanges();
        $previous = new PullRequestSnapshot(headSha: 'pushed1', review: PullRequestReview::Approved, approvalSha: 'approved1', approvalId: 'review1', coveredSha: 'approved1');

        $this->handle($previous, new PullRequestSnapshot(headSha: 'pushed1', review: PullRequestReview::Approved, approvalSha: 'approved1', approvalId: 'review1', coveredSha: 'pushed1'));

        self::assertSame([], $this->outbox());
        self::assertSame([[(string) $card->id, CardChanged::UPDATED, false]], $changes->getArrayCopy());
    }

    /** A new push clears failed checks, and neither this nor a solved conflict publishes a fact. */
    public function test_a_displayed_change_with_no_fact_still_tells_the_card(): void
    {
        $card = $this->linkedCard();
        $changes = $this->cardChanges();

        $this->handle(
            new PullRequestSnapshot(headSha: 'aaa1111', checks: PullRequestChecks::Failed, checksSha: 'aaa1111', mergeability: PullRequestMergeability::Conflicting),
            new PullRequestSnapshot(headSha: 'bbb2222', mergeability: PullRequestMergeability::Mergeable),
        );

        self::assertSame([], $this->outbox());
        self::assertSame([[(string) $card->id, CardChanged::UPDATED, false]], $changes->getArrayCopy());
    }

    public function test_a_verdict_that_clears_a_block_tells_the_card(): void
    {
        $card = $this->linkedCard();
        $this->configure(enabled: true, loopLimit: 1);
        $this->handle(new PullRequestSnapshot(), $this->failed(self::SHA));
        $this->handle($this->failed(self::SHA), $this->failed('def5678abc'));
        self::assertSame('checks-failed', $this->automationOf($card)->blockedReason);
        $changes = $this->cardChanges();

        $this->review(PullRequestReview::Approved, $this->failed('def5678abc'));

        self::assertNull($this->automationOf($card)->blockedReason);
        self::assertSame([[(string) $card->id, CardChanged::UPDATED, false]], $changes->getArrayCopy());
    }

    public function test_the_listener_publishes_while_the_board_is_on(): void
    {
        $this->linkedCard();

        $this->dispatch(new PullRequestStateChanged($this->pullRequest, new PullRequestSnapshot(), $this->failed(self::SHA), PullRequestReview::Approved));

        self::assertSame(
            ['pull_request.review_submitted', 'pull_request.checks_concluded', 'pull_request.fix_requested'],
            array_column($this->outbox(), 'type'),
        );
    }

    public function test_the_listener_does_nothing_while_the_board_is_off(): void
    {
        $this->disableBoard();
        $card = $this->linkedCard();

        $this->dispatch(new PullRequestStateChanged($this->pullRequest, new PullRequestSnapshot(), $this->failed(self::SHA), PullRequestReview::Approved));

        self::assertSame([], $this->outbox());
        self::assertNull($this->findAutomation($card));
    }

    public function test_a_human_move_resets_the_rounds_and_an_agent_or_system_move_does_not(): void
    {
        $card = $this->linkedCard();
        $untouched = $this->linkedCard();
        $this->handle(new PullRequestSnapshot(), $this->failed(self::SHA));
        $this->block($card, 3, 'checks-failed');

        $this->move($card, CardReporter::Agent, 'next');
        $this->move($card, CardReporter::System, 'in-progress');
        self::assertSame(3, $this->automationOf($card)->fixRounds);

        $this->move($card, CardReporter::Human, 'next');
        $automation = $this->automationOf($card);
        self::assertSame(0, $automation->fixRounds);
        self::assertNull($automation->blockedReason);

        $this->em->createQuery('DELETE '.CardAutomation::class.' a WHERE a.card = :card')->setParameter('card', $untouched)->execute();
        $this->move($untouched, CardReporter::Human, 'next');
        self::assertNull($this->findAutomation($untouched));
    }

    private function handle(PullRequestSnapshot $previous, PullRequestSnapshot $current): void
    {
        $this->handler()(new PublishPullRequestEventsCommand($this->pullRequest, $previous, $current));
    }

    private function review(PullRequestReview $verdict, PullRequestSnapshot $snapshot = new PullRequestSnapshot()): void
    {
        $this->handler()(new PublishPullRequestEventsCommand($this->pullRequest, $snapshot, $snapshot, $verdict));
    }

    private function trackRepository(string $repository): void
    {
        $this->pullRequest = new ForgePullRequest($this->project, 'github', $repository, 5);
        $this->em->persist($this->pullRequest);
        $this->em->flush();
    }

    private function handler(): PublishPullRequestEventsHandler
    {
        $handler = self::getContainer()->get(PublishPullRequestEventsHandler::class);
        self::assertInstanceOf(PublishPullRequestEventsHandler::class, $handler);

        return $handler;
    }

    /** Forge dispatches the event inside its own transaction. */
    private function dispatch(object $event): void
    {
        $events = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $events);
        $this->em->wrapInTransaction(static fn (): object => $events->dispatch($event));
    }

    private function move(Card $card, CardReporter $actor, string $slug): void
    {
        $handler = self::getContainer()->get(MoveCardHandler::class);
        self::assertInstanceOf(MoveCardHandler::class, $handler);
        $handler(new MoveCardCommand($card, $actor, $this->column($this->project, $slug)));
    }

    /** @param list<string> $failedChecks */
    private function failed(string $sha, array $failedChecks = ['phpunit']): PullRequestSnapshot
    {
        return new PullRequestSnapshot(headSha: $sha, checks: PullRequestChecks::Failed, checksSha: $sha, failedChecks: $failedChecks);
    }

    private function configure(?bool $enabled = null, ?BoardMergeStrategy $mergeStrategy = null, ?BoardFixStrategy $fixStrategy = null, ?int $loopLimit = null): void
    {
        $automation = self::getContainer()->get(BoardAutomation::class);
        self::assertInstanceOf(BoardAutomation::class, $automation);
        $settings = $automation->settingsForUpdate($this->project);
        $settings->enabled = $enabled ?? $settings->enabled;
        $settings->mergeStrategy = $mergeStrategy ?? $settings->mergeStrategy;
        $settings->fixStrategy = $fixStrategy ?? $settings->fixStrategy;
        $settings->loopLimit = $loopLimit ?? $settings->loopLimit;
        $this->em->flush();
    }

    private function block(Card $card, int $rounds, string $reason): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE board_card_automations SET fix_rounds = :rounds, blocked_reason = :reason WHERE card_id = :card',
            ['rounds' => $rounds, 'reason' => $reason, 'card' => (string) $card->id],
        );
    }

    private function automationOf(Card $card): CardAutomation
    {
        $automation = $this->findAutomation($card);
        self::assertNotNull($automation);

        return $automation;
    }

    private function findAutomation(Card $card): ?CardAutomation
    {
        $automations = self::getContainer()->get(CardAutomationRepository::class);
        self::assertInstanceOf(CardAutomationRepository::class, $automations);
        $automation = $automations->findOneBy(['card' => $card]);
        if (null !== $automation) {
            $this->em->refresh($automation);
        }

        return $automation;
    }

    private function linkedCard(string $url = 'https://github.com/Acme/Widgets/pull/5', string $slug = 'backlog', string $repository = 'Acme/Widgets'): Card
    {
        $card = new Card($this->project, $this->column($this->project, $slug), 'Ship it', '', ++$this->cardNumber);
        $this->em->persist($card);
        $this->em->persist(new CardPullRequest($card, $url, Forge::GitHub, $repository, 5));
        $this->em->flush();

        return $card;
    }

    private function linkedCardAlso(Card $card, string $url): void
    {
        $this->em->persist(new CardPullRequest($card, $url, Forge::GitHub, 'acme/widgets', 5));
        $this->em->flush();
    }

    /** @return \ArrayObject<int, array{string, string, bool}> */
    private function cardChanges(): \ArrayObject
    {
        /** @var \ArrayObject<int, array{string, string, bool}> $changes */
        $changes = new \ArrayObject();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(SymfonyEventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(CardChanged::class, static function (CardChanged $event) use ($changes): void {
            $changes[] = [(string) $event->cardId, $event->change, $event->contentChanged];
        });

        return $changes;
    }

    /** @return list<array{mixed, mixed, mixed, mixed, mixed}> kind, actor kind, actor user, detail, occurred at */
    private function history(Card $card): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT kind, actor_kind, actor_user_id, detail, occurred_at FROM board_card_events WHERE card_id = :card ORDER BY occurred_at, id',
            ['card' => (string) $card->id],
        );

        return array_map(static fn (array $row): array => [
            $row['kind'],
            $row['actor_kind'],
            $row['actor_user_id'],
            json_decode((string) $row['detail'], true, flags: \JSON_THROW_ON_ERROR),
            $row['occurred_at'],
        ], $rows);
    }

    private function eventsOfType(string $type): int
    {
        return \count(array_filter($this->outbox(), static fn (array $event): bool => $type === $event['type']));
    }

    /** @return list<array<string, mixed>> */
    private function outbox(): array
    {
        $rows = $this->em->getConnection()->fetchFirstColumn(
            'SELECT payload FROM outbox_events WHERE project_id = :project AND type LIKE :prefix ORDER BY sequence',
            ['project' => (string) $this->project->id, 'prefix' => 'pull\_request.%'],
        );

        return array_map(static fn (mixed $payload): array => json_decode((string) $payload, true, flags: \JSON_THROW_ON_ERROR), $rows);
    }
}
