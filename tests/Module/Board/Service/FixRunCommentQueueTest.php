<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Entity\PullRequestComment;
use App\Module\Board\Entity\PullRequestCommentState;
use App\Module\Board\Messenger\PostFixRunComment;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\PullRequestCommentRepository;
use App\Module\Board\Service\FixRunCommentQueue;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Forge\Service\PullRequestCommenters;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\RuleBudgets;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class FixRunCommentQueueTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private InMemoryTransport $transport;
    private Project $project;
    private int $cardNumber = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $this->transport = $transport;

        $this->project = $this->makeProject('fix-run-comment');
    }

    public function test_an_open_fix_run_stores_one_pending_comment_for_the_open_pull_request_of_its_card(): void
    {
        $card = $this->card();
        $this->link($card, Forge::GitHub, 'Acme/Widgets', 5);
        $pullRequest = $this->tracked('acme/widgets', 5, headSha: 'abc1234', checks: PullRequestChecks::Failed);
        $run = $this->workerRun($card);

        self::assertTrue($this->queue()->queue($card));

        $rows = $this->comments();
        self::assertCount(1, $rows);
        $comment = $rows[0];
        self::assertEquals($run->id, $comment->runId);
        self::assertEquals($card->id, $comment->cardId);
        self::assertSame('github', $comment->forge);
        self::assertSame('acme/widgets', $comment->repository);
        self::assertSame(5, $comment->number);
        self::assertSame('abc1234', $comment->headSha);
        self::assertSame('checks-failed', $comment->reason);
        self::assertSame(PullRequestCommentState::Pending, $comment->state);
        self::assertSame(0, $comment->attempts);
        self::assertNull($comment->fixRound);
        self::assertEquals($pullRequest->id, $comment->forgePullRequestId);

        $sent = $this->transport->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(PostFixRunComment::class, $message);
        self::assertEquals($comment->id, $message->commentId);
    }

    public function test_the_comment_stores_the_fires_of_the_rule_of_the_work_request_as_its_round(): void
    {
        $card = $this->card();
        $this->link($card, Forge::GitHub, 'acme/widgets', 5);
        $this->fires($card, 'fix-in-review', 2);
        $this->workerRun($card, request: $this->request($card, ruleId: 'fix-in-review'));

        $this->queue()->queue($card);

        self::assertSame(2, $this->comments()[0]->fixRound);
    }

    public function test_a_rule_whose_count_started_again_stores_no_round(): void
    {
        $card = $this->card();
        $this->link($card, Forge::GitHub, 'acme/widgets', 5);
        $this->fires($card, 'fix-in-review', 0);
        $this->workerRun($card, request: $this->request($card, ruleId: 'fix-in-review'));

        $this->queue()->queue($card);

        self::assertNull($this->comments()[0]->fixRound);
    }

    public function test_the_comment_picks_the_open_pull_request_over_a_merged_one(): void
    {
        $card = $this->card();
        $this->link($card, Forge::GitHub, 'acme/widgets', 4);
        $this->link($card, Forge::GitHub, 'acme/widgets', 5);
        $merged = $this->tracked('acme/widgets', 4);
        $merged->state = PullRequestState::Merged;
        $this->em->flush();
        $this->tracked('acme/widgets', 5, conflicting: true);
        $this->workerRun($card);

        $this->queue()->queue($card);

        $rows = $this->comments();
        self::assertCount(1, $rows);
        self::assertSame(5, $rows[0]->number);
        self::assertSame('conflict', $rows[0]->reason);
    }

    public function test_the_comment_goes_to_the_pull_request_whose_url_the_work_request_names(): void
    {
        $card = $this->stackedCard();
        $this->workerRun($card, request: $this->request($card, new WorkRequestContext(pullRequestNumber: 4, pullRequestUrl: 'https://EXAMPLE.com/acme/widgets/pull/5')));

        $this->queue()->queue($card);

        $rows = $this->comments();
        self::assertCount(1, $rows);
        self::assertSame(5, $rows[0]->number);
        self::assertSame('upper', $rows[0]->headSha);
        self::assertSame('checks-failed', $rows[0]->reason);
    }

    public function test_the_reason_of_the_work_request_wins_over_the_state_of_the_pull_request(): void
    {
        $card = $this->stackedCard();
        $this->workerRun($card, request: $this->request($card, new WorkRequestContext(pullRequestUrl: 'https://example.com/acme/widgets/pull/5', reason: 'agent-review')));

        $this->queue()->queue($card);

        $rows = $this->comments();
        self::assertCount(1, $rows);
        self::assertSame(5, $rows[0]->number);
        self::assertSame('agent-review', $rows[0]->reason);
    }

    public function test_the_comment_goes_to_the_pull_request_whose_number_the_work_request_names_when_no_url_matches(): void
    {
        $card = $this->stackedCard();
        $this->workerRun($card, request: $this->request($card, new WorkRequestContext(pullRequestNumber: 5, pullRequestUrl: 'https://example.com/other/pull/5')));

        $this->queue()->queue($card);

        $rows = $this->comments();
        self::assertCount(1, $rows);
        self::assertSame(5, $rows[0]->number);
        self::assertSame('upper', $rows[0]->headSha);
    }

    public function test_a_number_that_two_repositories_share_names_no_link_and_falls_back_to_the_first_open_pull_request(): void
    {
        $card = $this->card();
        $this->link($card, Forge::GitHub, 'acme/widgets', 5);
        $this->link($card, Forge::GitHub, 'acme/gadgets', 5);
        $closed = $this->tracked('acme/widgets', 5);
        $closed->state = PullRequestState::Closed;
        $this->em->flush();
        $this->tracked('acme/gadgets', 5, headSha: 'gadget');
        $this->workerRun($card, request: $this->request($card, new WorkRequestContext(pullRequestNumber: 5)));

        $this->queue()->queue($card);

        $rows = $this->comments();
        self::assertCount(1, $rows);
        self::assertSame('acme/gadgets', $rows[0]->repository);
        self::assertSame('gadget', $rows[0]->headSha);
    }

    public function test_a_work_request_that_names_no_link_of_the_card_falls_back_to_the_first_open_pull_request(): void
    {
        $card = $this->stackedCard();
        $this->workerRun($card, request: $this->request($card, new WorkRequestContext(pullRequestNumber: 99, pullRequestUrl: 'https://example.com/acme/widgets/pull/99')));

        $this->queue()->queue($card);

        $rows = $this->comments();
        self::assertCount(1, $rows);
        self::assertSame(4, $rows[0]->number);
        self::assertSame('conflict', $rows[0]->reason);
    }

    public function test_the_work_request_of_another_card_names_no_pull_request(): void
    {
        $card = $this->stackedCard();
        $other = $this->card();
        $this->workerRun($card, request: $this->request($other, new WorkRequestContext(pullRequestNumber: 5)));

        $this->queue()->queue($card);

        self::assertSame(4, $this->comments()[0]->number);
    }

    public function test_an_untracked_pull_request_gets_a_comment_with_no_head_and_no_reason(): void
    {
        $card = $this->card();
        $this->link($card, Forge::GitHub, 'Acme/Widgets', 5);
        $this->workerRun($card);

        $this->queue()->queue($card);

        $rows = $this->comments();
        self::assertCount(1, $rows);
        self::assertSame('Acme/Widgets', $rows[0]->repository);
        self::assertNull($rows[0]->headSha);
        self::assertNull($rows[0]->reason);
        self::assertNull($rows[0]->forgePullRequestId);
    }

    public function test_each_open_fix_run_gets_its_own_comment_once(): void
    {
        $card = $this->linkedCard();
        $first = $this->workerRun($card);
        $second = $this->workerRun($card, state: WorkerRunState::Running);
        self::assertSame([$first, $second], $this->queue()->uncommentedRuns($card));

        self::assertTrue($this->queue()->queue($card));
        self::assertFalse($this->queue()->queue($card));

        self::assertCount(2, $this->comments());
        self::assertCount(2, $this->transport->getSent());
        self::assertSame([], $this->queue()->uncommentedRuns($card));
    }

    public function test_an_old_closed_run_or_a_run_of_another_kind_gets_no_comment(): void
    {
        $card = $this->linkedCard();
        $this->workerRun($card, state: WorkerRunState::Succeeded, receivedAt: $this->ago('2 hours'));
        $this->workerRun($card, workKind: 'implement');
        // The guard: an open fix run of the same card is found, so the query reads the card.
        $open = $this->workerRun($card);

        self::assertSame([$open], $this->queue()->uncommentedRuns($card));
    }

    public function test_a_recent_run_that_ended_before_the_evaluation_still_gets_its_comment(): void
    {
        $card = $this->linkedCard();
        $receivedAt = $this->ago('20 minutes');
        $ended = $this->workerRun($card, state: WorkerRunState::Succeeded, receivedAt: $receivedAt, endedAt: $receivedAt->modify('+10 minutes'));

        self::assertSame([$ended], $this->queue()->uncommentedRuns($card));
        self::assertTrue($this->queue()->queue($card));
        self::assertEquals($ended->id, $this->comments()[0]->runId);
    }

    public function test_a_long_run_that_ended_recently_still_gets_its_comment(): void
    {
        $card = $this->linkedCard();
        $ended = $this->workerRun($card, state: WorkerRunState::Succeeded, receivedAt: $this->ago('3 hours'), endedAt: $this->ago('5 minutes'));
        $this->workerRun($card, state: WorkerRunState::Succeeded, receivedAt: $this->ago('3 hours'), endedAt: $this->ago('2 hours'));

        self::assertSame([$ended], $this->queue()->uncommentedRuns($card));
    }

    public function test_an_open_run_received_long_ago_still_gets_its_comment(): void
    {
        $card = $this->linkedCard();
        $open = $this->workerRun($card, state: WorkerRunState::Running, receivedAt: $this->ago('3 hours'));

        self::assertSame([$open], $this->queue()->uncommentedRuns($card));
    }

    public function test_a_card_without_a_numbered_pull_request_has_no_uncommented_run(): void
    {
        $card = $this->card();
        $this->em->persist(new CardPullRequest($card, 'https://example.com/pr', Forge::Other));
        $this->em->flush();
        $this->workerRun($card);

        self::assertSame([], $this->queue()->uncommentedRuns($card));
        self::assertFalse($this->queue()->queue($card));
        self::assertSame([], $this->comments());
    }

    public function test_a_forge_that_cannot_comment_has_no_uncommented_run(): void
    {
        $card = $this->card();
        $this->link($card, Forge::GitLab, 'acme/widgets', 5);
        $this->workerRun($card);
        // The guard: the card links the pull request, so only the missing commenter leaves it out.
        $links = self::getContainer()->get(CardPullRequestRepository::class);
        self::assertInstanceOf(CardPullRequestRepository::class, $links);
        self::assertCount(1, $links->findNumberedKeysOfCard($this->project->id ?? throw new \LogicException('A flushed project has an id.'), $card->id ?? throw new \LogicException('A flushed card has an id.')));

        self::assertSame([], $this->queue()->uncommentedRuns($card));
        self::assertFalse($this->queue()->queue($card));
        self::assertSame([], $this->transport->getSent());
    }

    public function test_a_message_that_fails_to_queue_leaves_no_pending_row(): void
    {
        $card = $this->linkedCard();
        $this->workerRun($card);
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('transport down'));
        $container = self::getContainer();
        $queue = new FixRunCommentQueue(
            $container->get(PullRequestCommenters::class),
            $container->get(PullRequestCommentRepository::class),
            $container->get(CardPullRequestRepository::class),
            $container->get(ForgePullRequestRepository::class),
            $container->get(WorkRequestRepository::class),
            $this->createStub(RuleBudgets::class),
            $this->em,
            $bus,
            $container->get(ClockInterface::class),
            new NullLogger(),
        );

        try {
            $queue->queue($card);
            self::fail('The failed dispatch reaches the caller.');
        } catch (\RuntimeException $e) {
            self::assertSame('transport down', $e->getMessage());
        }

        self::assertTrue($this->em->isOpen());
        self::assertSame([], $this->comments());
    }

    /** A card with two open pull requests: number 4 conflicts and number 5 fails its checks. */
    private function stackedCard(): Card
    {
        $card = $this->card();
        $this->link($card, Forge::GitHub, 'acme/widgets', 4);
        $this->link($card, Forge::GitHub, 'acme/widgets', 5);
        $this->tracked('acme/widgets', 4, headSha: 'base', conflicting: true);
        $this->tracked('acme/widgets', 5, headSha: 'upper', checks: PullRequestChecks::Failed);

        return $card;
    }

    private function linkedCard(): Card
    {
        $card = $this->card();
        $this->link($card, Forge::GitHub, 'acme/widgets', 5);

        return $card;
    }

    private function card(): Card
    {
        $card = new Card($this->project, $this->column($this->project, 'backlog'), 'Fix it', '', ++$this->cardNumber);
        $this->em->persist($card);
        $this->em->flush();

        return $card;
    }

    private function link(Card $card, Forge $forge, string $repository, int $number): void
    {
        $this->em->persist(new CardPullRequest($card, 'https://example.com/'.$repository.'/pull/'.$number, $forge, $repository, $number));
        $this->em->flush();
    }

    private function tracked(string $repository, int $number, ?string $headSha = null, PullRequestChecks $checks = PullRequestChecks::Pending, bool $conflicting = false): ForgePullRequest
    {
        $pullRequest = new ForgePullRequest($this->project, 'github', $repository, $number);
        $pullRequest->headSha = $headSha;
        $pullRequest->checks = $checks;
        if ($conflicting) {
            $pullRequest->mergeability = PullRequestMergeability::Conflicting;
        }
        $this->em->persist($pullRequest);
        $this->em->flush();

        return $pullRequest;
    }

    private function request(Card $card, WorkRequestContext $context = new WorkRequestContext(), string $ruleId = 'fix-in-review'): WorkRequest
    {
        $request = new WorkRequest(
            project: $this->project,
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('A flushed card has an id.'),
            cardNumber: $card->number,
            kind: 'fix',
            capability: null,
            ruleId: $ruleId,
            createdAt: new \DateTimeImmutable('2026-10-09 12:00:00'),
        );
        $request->context = $context;
        $this->em->persist($request);
        $this->em->flush();

        return $request;
    }

    private function ago(string $interval): \DateTimeImmutable
    {
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);

        return $clock->now()->modify('-'.$interval);
    }

    private function workerRun(Card $card, WorkerRunState $state = WorkerRunState::Queued, string $workKind = 'fix', ?WorkRequest $request = null, ?\DateTimeImmutable $receivedAt = null, ?\DateTimeImmutable $endedAt = null): WorkerRun
    {
        $run = new WorkerRun(
            project: $this->project,
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('A flushed card has an id.'),
            cardNumber: $card->number,
            workKind: $workKind,
            state: $state,
            runKey: Uuid::v4(),
            endedAt: $endedAt,
            receivedAt: $receivedAt ?? new \DateTimeImmutable(),
            workRequestId: $request?->id,
            ruleId: $request?->ruleId,
        );
        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    private function fires(Card $card, string $ruleId, int $fires): void
    {
        $state = new WorkflowRuleState($card->id ?? throw new \LogicException('A flushed card has an id.'), $this->project, $ruleId);
        $state->fires = $fires;
        $this->em->persist($state);
        $this->em->flush();
    }

    private function queue(): FixRunCommentQueue
    {
        $queue = self::getContainer()->get(FixRunCommentQueue::class);
        self::assertInstanceOf(FixRunCommentQueue::class, $queue);

        return $queue;
    }

    /** @return list<PullRequestComment> */
    private function comments(): array
    {
        $comments = self::getContainer()->get(PullRequestCommentRepository::class);
        self::assertInstanceOf(PullRequestCommentRepository::class, $comments);

        return array_values($comments->findBy(['project' => $this->project->id], ['runId' => 'ASC']));
    }
}
