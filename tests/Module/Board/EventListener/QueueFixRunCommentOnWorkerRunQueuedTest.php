<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Entity\PullRequestComment;
use App\Module\Board\Entity\PullRequestCommentState;
use App\Module\Board\EventListener\QueueFixRunCommentOnWorkerRunQueued;
use App\Module\Board\Messenger\PostFixRunComment;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\PullRequestCommentRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Bridge\Event\WorkerRunQueued;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Forge\Service\PullRequestCommenters;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use App\Tests\Support\RecordingLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LogLevel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class QueueFixRunCommentOnWorkerRunQueuedTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private InMemoryTransport $transport;
    private Project $project;

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

    public function test_a_queued_fix_run_stores_one_pending_comment_for_the_open_pull_request_of_its_card(): void
    {
        $this->commentOnFixQueued(true);
        $card = $this->card();
        $this->link($card, Forge::GitHub, 'Acme/Widgets', 5);
        $pullRequest = $this->tracked('acme/widgets', 5, headSha: 'abc1234', checks: PullRequestChecks::Failed);
        $event = $this->event(cardId: $card->id);

        $this->listener()($event);

        $rows = $this->comments();
        self::assertCount(1, $rows);
        $comment = $rows[0];
        self::assertEquals($event->runId, $comment->runId);
        self::assertEquals($event->cardId, $comment->cardId);
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

    public function test_the_comment_picks_the_open_pull_request_over_a_merged_one(): void
    {
        $this->commentOnFixQueued(true);
        $card = $this->card();
        $this->link($card, Forge::GitHub, 'acme/widgets', 4);
        $this->link($card, Forge::GitHub, 'acme/widgets', 5);
        $merged = $this->tracked('acme/widgets', 4);
        $merged->state = PullRequestState::Merged;
        $this->em->flush();
        $this->tracked('acme/widgets', 5, conflicting: true);

        $this->listener()($this->event(cardId: $card->id));

        $rows = $this->comments();
        self::assertCount(1, $rows);
        self::assertSame(5, $rows[0]->number);
        self::assertSame('conflict', $rows[0]->reason);
    }

    public function test_the_comment_goes_to_the_pull_request_whose_url_the_event_names(): void
    {
        $this->commentOnFixQueued(true);
        $card = $this->stackedCard();

        $this->listener()($this->event(cardId: $card->id, pullRequestNumber: 4, pullRequestUrl: 'https://EXAMPLE.com/acme/widgets/pull/5'));

        $rows = $this->comments();
        self::assertCount(1, $rows);
        self::assertSame(5, $rows[0]->number);
        self::assertSame('upper', $rows[0]->headSha);
        self::assertSame('checks-failed', $rows[0]->reason);
    }

    public function test_the_comment_goes_to_the_pull_request_whose_number_the_event_names_when_no_url_matches(): void
    {
        $this->commentOnFixQueued(true);
        $card = $this->stackedCard();

        $this->listener()($this->event(cardId: $card->id, pullRequestNumber: 5, pullRequestUrl: 'https://example.com/other/pull/5'));

        $rows = $this->comments();
        self::assertCount(1, $rows);
        self::assertSame(5, $rows[0]->number);
        self::assertSame('upper', $rows[0]->headSha);
    }

    public function test_a_number_that_two_repositories_share_names_no_link_and_falls_back_to_the_first_open_pull_request(): void
    {
        $this->commentOnFixQueued(true);
        $card = $this->card();
        $this->link($card, Forge::GitHub, 'acme/widgets', 5);
        $this->link($card, Forge::GitHub, 'acme/gadgets', 5);
        $closed = $this->tracked('acme/widgets', 5);
        $closed->state = PullRequestState::Closed;
        $this->em->flush();
        $this->tracked('acme/gadgets', 5, headSha: 'gadget');

        $this->listener()($this->event(cardId: $card->id, pullRequestNumber: 5));

        $rows = $this->comments();
        self::assertCount(1, $rows);
        self::assertSame('acme/gadgets', $rows[0]->repository);
        self::assertSame('gadget', $rows[0]->headSha);
    }

    public function test_an_event_that_names_no_link_of_the_card_falls_back_to_the_first_open_pull_request(): void
    {
        $this->commentOnFixQueued(true);
        $card = $this->stackedCard();

        $this->listener()($this->event(cardId: $card->id, pullRequestNumber: 99, pullRequestUrl: 'https://example.com/acme/widgets/pull/99'));

        $rows = $this->comments();
        self::assertCount(1, $rows);
        self::assertSame(4, $rows[0]->number);
        self::assertSame('conflict', $rows[0]->reason);
    }

    public function test_an_untracked_pull_request_gets_a_comment_with_no_head_and_no_reason(): void
    {
        $this->commentOnFixQueued(true);
        $card = $this->card();
        $this->link($card, Forge::GitHub, 'Acme/Widgets', 5);

        $this->listener()($this->event(cardId: $card->id));

        $rows = $this->comments();
        self::assertCount(1, $rows);
        self::assertSame('Acme/Widgets', $rows[0]->repository);
        self::assertNull($rows[0]->headSha);
        self::assertNull($rows[0]->reason);
        self::assertNull($rows[0]->forgePullRequestId);
    }

    public function test_the_same_run_queued_twice_stores_and_queues_once(): void
    {
        $this->commentOnFixQueued(true);
        $event = $this->linkedEvent();

        $this->listener()($event);
        $this->listener()($event);

        self::assertCount(1, $this->comments());
        self::assertCount(1, $this->transport->getSent());
    }

    public function test_nothing_is_queued_while_the_setting_is_off(): void
    {
        $this->commentOnFixQueued(false);

        $this->listener()($this->linkedEvent());

        self::assertSame([], $this->comments());
        self::assertSame([], $this->transport->getSent());
    }

    public function test_nothing_is_queued_for_a_card_without_a_numbered_pull_request(): void
    {
        $this->commentOnFixQueued(true);
        $card = $this->card();
        $this->em->persist(new CardPullRequest($card, 'https://example.com/pr', Forge::Other));
        $this->em->flush();

        $this->listener()($this->event(cardId: $card->id));

        self::assertSame([], $this->comments());
        self::assertSame([], $this->transport->getSent());
    }

    public function test_nothing_is_queued_for_a_forge_that_cannot_comment(): void
    {
        $this->commentOnFixQueued(true);
        $card = $this->card();
        $this->link($card, Forge::GitLab, 'acme/widgets', 5);

        $this->listener()($this->event(cardId: $card->id));

        self::assertSame([], $this->comments());
        self::assertSame([], $this->transport->getSent());
    }

    public function test_nothing_is_queued_for_an_unknown_project(): void
    {
        $this->commentOnFixQueued(true);

        $this->listener()($this->event(projectId: Uuid::v7(), cardId: $this->linkedEvent()->cardId));

        self::assertSame([], $this->comments());
        self::assertSame([], $this->transport->getSent());
    }

    public function test_a_failure_is_logged_and_never_reaches_the_bridge_report(): void
    {
        $this->commentOnFixQueued(true);
        $event = $this->linkedEvent();
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('transport down'));
        $logger = new RecordingLogger();
        $container = self::getContainer();

        $listener = new QueueFixRunCommentOnWorkerRunQueued(
            $container->get(ProjectRepository::class),
            $container->get(BoardAutomation::class),
            $container->get(PullRequestCommenters::class),
            $container->get(PullRequestCommentRepository::class),
            $container->get(CardPullRequestRepository::class),
            $container->get(ForgePullRequestRepository::class),
            $this->em,
            $bus,
            $container->get(ClockInterface::class),
            $logger,
        );
        $listener($event);

        self::assertTrue($this->em->isOpen());
        $errors = array_values(array_filter($logger->records, static fn (array $record): bool => LogLevel::ERROR === $record['level']));
        self::assertCount(1, $errors);
        self::assertSame('board.fix_run_comment_queue_failed', $errors[0]['message']);
        self::assertSame((string) $event->projectId, $errors[0]['context']['projectId']);
        self::assertSame((string) $event->runId, $errors[0]['context']['runId']);
        self::assertSame('transport down', $errors[0]['context']['error']);
        self::assertSame([], $this->comments());
    }

    private function commentOnFixQueued(bool $on): void
    {
        $settings = new BoardAutomationSettings($this->project);
        $settings->commentOnFixQueued = $on;
        $this->em->persist($settings);
        $this->em->flush();
    }

    private function event(?Uuid $projectId = null, ?Uuid $cardId = null, ?int $pullRequestNumber = null, ?string $pullRequestUrl = null): WorkerRunQueued
    {
        return new WorkerRunQueued(
            projectId: $projectId ?? $this->project->id ?? throw new \LogicException('A persisted project has an id.'),
            runId: Uuid::v7(),
            cardId: $cardId ?? Uuid::v7(),
            pullRequestNumber: $pullRequestNumber,
            pullRequestUrl: $pullRequestUrl,
        );
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

    private function linkedEvent(): WorkerRunQueued
    {
        $card = $this->card();
        $this->link($card, Forge::GitHub, 'acme/widgets', 5);

        return $this->event(cardId: $card->id);
    }

    private function card(): Card
    {
        $card = new Card($this->project, $this->column($this->project, 'backlog'), 'Fix it', '', 1);
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

    private function listener(): QueueFixRunCommentOnWorkerRunQueued
    {
        $listener = self::getContainer()->get(QueueFixRunCommentOnWorkerRunQueued::class);
        self::assertInstanceOf(QueueFixRunCommentOnWorkerRunQueued::class, $listener);

        return $listener;
    }

    /** @return list<PullRequestComment> */
    private function comments(): array
    {
        $this->em->clear();
        $comments = self::getContainer()->get(PullRequestCommentRepository::class);
        self::assertInstanceOf(PullRequestCommentRepository::class, $comments);

        return array_values($comments->findBy(['project' => $this->project->id]));
    }
}
