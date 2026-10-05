<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Board\Command\WritePullRequestFactEventsCommand;
use App\Module\Board\Command\WritePullRequestFactEventsHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Event\CardChanged;
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
use Symfony\Component\EventDispatcher\EventDispatcherInterface as SymfonyEventDispatcherInterface;

final class WritePullRequestFactEventsHandlerTest extends KernelTestCase
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

        $this->project = $this->makeProject('pull-request-events');
        $this->pullRequest = new ForgePullRequest($this->project, 'github', 'Acme/Widgets', 5);
        $this->em->persist($this->pullRequest);
        $this->em->flush();
    }

    public function test_failed_checks_write_the_fact(): void
    {
        $card = $this->linkedCard();

        $this->handle(new PullRequestSnapshot(), $this->failed(self::SHA, ['phpunit', 'lint']));

        self::assertSame([[
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
        ]], $this->outbox());
    }

    public function test_passed_checks_write_the_fact_with_no_failed_checks(): void
    {
        $this->linkedCard();

        $this->handle($this->failed('aaaaaaa'), new PullRequestSnapshot(headSha: 'bbbbbbb', checks: PullRequestChecks::Passed, checksSha: 'bbbbbbb'));

        $events = $this->outbox();
        self::assertCount(1, $events);
        self::assertSame('pull_request.checks_concluded', $events[0]['type']);
        self::assertSame('passed', $events[0]['conclusion']);
        self::assertSame([], $events[0]['failedChecks']);
    }

    public function test_the_same_checks_on_the_same_sha_write_once_and_a_new_sha_writes_again(): void
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
    public function test_a_field_of_a_bad_shape_is_left_out(string $url, string $repository, string $headSha): void
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

    public function test_behind_and_conflicted_write_their_facts(): void
    {
        $this->linkedCard();

        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(mergeability: PullRequestMergeability::Behind));
        $this->handle(new PullRequestSnapshot(mergeability: PullRequestMergeability::Behind), new PullRequestSnapshot(mergeability: PullRequestMergeability::Conflicting));
        $this->handle(new PullRequestSnapshot(mergeability: PullRequestMergeability::Conflicting), new PullRequestSnapshot(draft: true, mergeability: PullRequestMergeability::Conflicting));

        self::assertSame(['pull_request.behind', 'pull_request.conflicted'], array_column($this->outbox(), 'type'));
    }

    public function test_merged_and_closed_write_their_facts(): void
    {
        $this->linkedCard();

        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(state: PullRequestState::Closed));
        $this->handle(new PullRequestSnapshot(state: PullRequestState::Closed), new PullRequestSnapshot(state: PullRequestState::Merged));

        self::assertSame(['pull_request.closed', 'pull_request.merged'], array_column($this->outbox(), 'type'));
    }

    public function test_a_review_writes_its_verdict_and_a_required_review_writes_nothing(): void
    {
        $this->linkedCard();

        // The snapshot says None, as it does for a branch that requires no review.
        $this->review(PullRequestReview::ChangesRequested);
        $this->review(PullRequestReview::Approved, new PullRequestSnapshot(review: PullRequestReview::ChangesRequested));
        $this->review(PullRequestReview::Required);

        $events = $this->outbox();
        self::assertSame(['pull_request.review_submitted', 'pull_request.review_submitted'], array_column($events, 'type'));
        self::assertSame('changes-requested', $events[0]['verdict']);
        self::assertSame('approved', $events[1]['verdict']);
    }

    public function test_a_state_change_of_the_review_writes_no_review_event(): void
    {
        $this->linkedCard();

        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(review: PullRequestReview::ChangesRequested));

        self::assertSame([], $this->outbox());
    }

    public function test_a_card_in_a_terminal_column_gets_the_facts(): void
    {
        $this->linkedCard(slug: 'done');

        $this->handle(new PullRequestSnapshot(), $this->failed(self::SHA));

        self::assertSame(['pull_request.checks_concluded'], array_column($this->outbox(), 'type'));
    }

    public function test_it_writes_no_decision_and_no_card_history(): void
    {
        $card = $this->linkedCard();
        $current = new PullRequestSnapshot(headSha: self::SHA, checks: PullRequestChecks::Failed, checksSha: self::SHA, mergeability: PullRequestMergeability::Conflicting, readyToMerge: true);

        $this->handler()(new WritePullRequestFactEventsCommand($this->pullRequest, new PullRequestSnapshot(), $current, PullRequestReview::ChangesRequested));

        self::assertSame(['pull_request.review_submitted', 'pull_request.checks_concluded', 'pull_request.conflicted'], array_column($this->outbox(), 'type'));
        self::assertSame(0, $this->countForCard('board_card_automations', $card));
        self::assertSame(0, $this->countForCard('board_card_events', $card));
    }

    public function test_every_card_linking_the_pull_request_gets_its_events_once(): void
    {
        $first = $this->linkedCard();
        $second = $this->linkedCard();
        $this->em->persist(new CardPullRequest($second, 'https://github.com/acme/widgets/pull/5', Forge::GitHub, 'acme/widgets', 5));
        $this->em->flush();

        $this->handle(new PullRequestSnapshot(), new PullRequestSnapshot(state: PullRequestState::Closed, headSha: self::SHA, checks: PullRequestChecks::Failed, checksSha: self::SHA));

        self::assertSame(
            [(string) $first->id, (string) $first->id, (string) $second->id, (string) $second->id],
            array_column($this->outbox(), 'cardId'),
        );
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

    public function test_a_push_that_makes_the_approval_outdated_tells_the_card(): void
    {
        $card = $this->linkedCard();
        $changes = $this->cardChanges();

        $this->handle(
            new PullRequestSnapshot(headSha: 'approved1', review: PullRequestReview::Approved, approvalSha: 'approved1', approvalId: 'review1', coveredSha: 'approved1'),
            new PullRequestSnapshot(headSha: 'pushed1', review: PullRequestReview::Approved, approvalSha: 'approved1', approvalId: 'review1', coveredSha: 'approved1'),
        );

        self::assertSame([[(string) $card->id, CardChanged::UPDATED, false]], $changes->getArrayCopy());
    }

    public function test_a_covered_head_that_ends_an_outdated_approval_tells_the_card(): void
    {
        $card = $this->linkedCard();
        $changes = $this->cardChanges();
        $previous = new PullRequestSnapshot(headSha: 'pushed1', review: PullRequestReview::Approved, approvalSha: 'approved1', approvalId: 'review1', coveredSha: 'approved1');

        $this->handle($previous, new PullRequestSnapshot(headSha: 'pushed1', review: PullRequestReview::Approved, approvalSha: 'approved1', approvalId: 'review1', coveredSha: 'pushed1'));

        self::assertSame([], $this->outbox());
        self::assertSame([[(string) $card->id, CardChanged::UPDATED, false]], $changes->getArrayCopy());
    }

    /** A new push clears failed checks, and neither this nor a solved conflict writes a fact. */
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

    public function test_a_verdict_on_an_unchanged_snapshot_tells_no_card(): void
    {
        $this->linkedCard();
        $changes = $this->cardChanges();

        $this->review(PullRequestReview::Approved);

        self::assertSame(['pull_request.review_submitted'], array_column($this->outbox(), 'type'));
        self::assertSame([], $changes->getArrayCopy());
    }

    public function test_the_listener_writes_while_the_board_is_on(): void
    {
        $this->linkedCard();

        $this->dispatch(new PullRequestStateChanged($this->pullRequest, new PullRequestSnapshot(), $this->failed(self::SHA), PullRequestReview::Approved));

        self::assertSame(['pull_request.review_submitted', 'pull_request.checks_concluded'], array_column($this->outbox(), 'type'));
    }

    private function handle(PullRequestSnapshot $previous, PullRequestSnapshot $current): void
    {
        $this->handler()(new WritePullRequestFactEventsCommand($this->pullRequest, $previous, $current));
    }

    private function review(PullRequestReview $verdict, PullRequestSnapshot $snapshot = new PullRequestSnapshot()): void
    {
        $this->handler()(new WritePullRequestFactEventsCommand($this->pullRequest, $snapshot, $snapshot, $verdict));
    }

    private function trackRepository(string $repository): void
    {
        $this->pullRequest = new ForgePullRequest($this->project, 'github', $repository, 5);
        $this->em->persist($this->pullRequest);
        $this->em->flush();
    }

    private function handler(): WritePullRequestFactEventsHandler
    {
        $handler = self::getContainer()->get(WritePullRequestFactEventsHandler::class);
        self::assertInstanceOf(WritePullRequestFactEventsHandler::class, $handler);

        return $handler;
    }

    /** Forge dispatches the event inside its own transaction. */
    private function dispatch(object $event): void
    {
        $events = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $events);
        $this->em->wrapInTransaction(static fn (): object => $events->dispatch($event));
    }

    /** @param list<string> $failedChecks */
    private function failed(string $sha, array $failedChecks = ['phpunit']): PullRequestSnapshot
    {
        return new PullRequestSnapshot(headSha: $sha, checks: PullRequestChecks::Failed, checksSha: $sha, failedChecks: $failedChecks);
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

    private function countForCard(string $table, Card $card): int
    {
        return (int) $this->em->getConnection()->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE card_id = :card', $table), ['card' => (string) $card->id]);
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
