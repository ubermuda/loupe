<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardAutomation;
use App\Module\Board\Entity\PullRequestComment;
use App\Module\Board\Entity\PullRequestCommentState;
use App\Module\Board\EventListener\QueueFixRunCommentOnWorkerRunQueued;
use App\Module\Board\Messenger\PostFixRunComment;
use App\Module\Board\Repository\CardAutomationRepository;
use App\Module\Board\Repository\PullRequestCommentRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Bridge\Event\WorkerRunQueued;
use App\Module\Forge\Entity\ForgePullRequest;
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

        $this->enableBoard();
        $this->project = $this->makeProject('fix-run-comment');
    }

    public function test_a_queued_fix_run_stores_one_pending_comment_and_queues_one_message(): void
    {
        $this->commentOnFixQueued(true);
        $event = $this->event();

        $this->listener()($event);

        $rows = $this->comments();
        self::assertCount(1, $rows);
        $comment = $rows[0];
        self::assertEquals($event->runId, $comment->runId);
        self::assertEquals($event->cardId, $comment->cardId);
        self::assertSame('github', $comment->forge);
        self::assertSame('Acme/Widgets', $comment->repository);
        self::assertSame(5, $comment->number);
        self::assertSame('abc1234', $comment->headSha);
        self::assertSame('checks-failed', $comment->reason);
        self::assertSame(PullRequestCommentState::Pending, $comment->state);
        self::assertSame(0, $comment->attempts);
        self::assertNull($comment->fixRound);
        self::assertNull($comment->forgePullRequestId);

        $sent = $this->transport->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(PostFixRunComment::class, $message);
        self::assertEquals($comment->id, $message->commentId);
    }

    public function test_the_comment_stores_the_id_of_the_tracked_pull_request(): void
    {
        $this->commentOnFixQueued(true);
        $pullRequest = new ForgePullRequest($this->project, 'github', 'acme/widgets', 5);
        $this->em->persist($pullRequest);
        $this->em->flush();

        $this->listener()($this->event());

        self::assertEquals($pullRequest->id, $this->comments()[0]->forgePullRequestId);
    }

    public function test_the_comment_stores_the_fix_round_of_the_card(): void
    {
        $this->commentOnFixQueued(true);
        $card = new Card($this->project, $this->column($this->project, 'backlog'), 'Fix it', '', 1);
        $this->em->persist($card);
        $automation = new CardAutomation($card);
        $automation->fixRounds = 2;
        $this->em->persist($automation);
        $this->em->flush();

        $this->listener()($this->event(cardId: $card->id));

        $rows = $this->comments();
        self::assertCount(1, $rows);
        self::assertSame(2, $rows[0]->fixRound);
    }

    public function test_the_same_run_queued_twice_stores_and_queues_once(): void
    {
        $this->commentOnFixQueued(true);
        $event = $this->event();

        $this->listener()($event);
        $this->listener()($event);

        self::assertCount(1, $this->comments());
        self::assertCount(1, $this->transport->getSent());
    }

    public function test_nothing_is_queued_while_the_setting_is_off(): void
    {
        $this->commentOnFixQueued(false);

        $this->listener()($this->event());

        self::assertSame([], $this->comments());
        self::assertSame([], $this->transport->getSent());
    }

    public function test_nothing_is_queued_while_the_board_is_off(): void
    {
        $this->commentOnFixQueued(true);
        $this->disableBoard();

        $this->listener()($this->event());

        self::assertSame([], $this->comments());
        self::assertSame([], $this->transport->getSent());
    }

    public function test_nothing_is_queued_for_a_run_without_a_pull_request(): void
    {
        $this->commentOnFixQueued(true);

        $this->listener()($this->event(repository: null, number: null));

        self::assertSame([], $this->comments());
        self::assertSame([], $this->transport->getSent());
    }

    public function test_nothing_is_queued_for_a_forge_that_cannot_comment(): void
    {
        $this->commentOnFixQueued(true);

        $this->listener()($this->event(forge: 'gitlab'));

        self::assertSame([], $this->comments());
        self::assertSame([], $this->transport->getSent());
    }

    public function test_nothing_is_queued_for_an_unknown_project(): void
    {
        $this->commentOnFixQueued(true);

        $this->listener()($this->event(projectId: Uuid::v7()));

        self::assertSame([], $this->comments());
        self::assertSame([], $this->transport->getSent());
    }

    public function test_a_failure_is_logged_and_never_reaches_the_bridge_report(): void
    {
        $this->commentOnFixQueued(true);
        $event = $this->event();
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('transport down'));
        $logger = new RecordingLogger();
        $container = self::getContainer();

        $listener = new QueueFixRunCommentOnWorkerRunQueued(
            $container->get(BoardAvailability::class),
            $container->get(ProjectRepository::class),
            $container->get(BoardAutomation::class),
            $container->get(PullRequestCommenters::class),
            $container->get(PullRequestCommentRepository::class),
            $container->get(CardAutomationRepository::class),
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

    private function event(?Uuid $projectId = null, ?Uuid $cardId = null, ?string $forge = 'github', ?string $repository = 'Acme/Widgets', ?int $number = 5): WorkerRunQueued
    {
        return new WorkerRunQueued(
            projectId: $projectId ?? $this->project->id ?? throw new \LogicException('A persisted project has an id.'),
            runId: Uuid::v7(),
            cardId: $cardId ?? Uuid::v7(),
            eventType: 'pull_request.fix_requested',
            forge: $forge,
            repository: $repository,
            pullRequestNumber: $number,
            headSha: 'abc1234',
            reason: 'checks-failed',
        );
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
