<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\PullRequestCommentState;
use App\Module\Board\Entity\PullRequestNotice;
use App\Module\Board\EventListener\QueueStaleApprovalNoticeOnPullRequestStateChanged;
use App\Module\Board\Messenger\PostPullRequestNotice;
use App\Module\Board\Repository\PullRequestNoticeRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Event\PullRequestStateChanged;
use App\Module\Forge\PullRequestSnapshot;
use App\Module\Forge\Service\PullRequestCommenters;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\FakePullRequestCommenter;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use App\Tests\Support\RecordingLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LogLevel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class QueueStaleApprovalNoticeOnPullRequestStateChangedTest extends KernelTestCase
{
    use BoardToolScenario;

    private const string APPROVED = 'aaaaaaa1111111111111111111111111111111aa';
    private const string HEAD = 'bbbbbbb2222222222222222222222222222222bb';

    private EntityManagerInterface $em;
    private InMemoryTransport $transport;
    private Project $project;
    private ForgePullRequest $pullRequest;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $this->transport = $transport;

        $this->project = $this->makeProject('stale-approval-notice');
        $this->pullRequest = new ForgePullRequest($this->project, 'github', 'Acme/Widgets', 5);
        $this->pullRequest->headSha = self::HEAD;
        $this->pullRequest->approvalId = 'review-1';
        $this->pullRequest->approvalSha = self::APPROVED;
        $this->pullRequest->coveredSha = self::APPROVED;
        $this->pullRequest->uncoveredSha = self::HEAD;
        $this->em->persist($this->pullRequest);
        $this->em->flush();
    }

    public function test_a_stale_approval_stores_one_pending_notice_and_queues_one_message(): void
    {
        $this->settings(enabled: true, commentOnStaleApproval: true);

        $this->dispatchChange();

        $notices = $this->notices();
        self::assertCount(1, $notices);
        self::assertEquals($this->pullRequest->id, $notices[0]->forgePullRequestId);
        self::assertSame('stale-approval:'.self::HEAD, $notices[0]->noticeKey);
        self::assertSame(PullRequestCommentState::Pending, $notices[0]->state);
        $messages = $this->messages();
        self::assertCount(1, $messages);
        self::assertEquals($notices[0]->id, $messages[0]->noticeId);
    }

    public function test_a_second_event_for_the_same_head_inserts_nothing(): void
    {
        $this->settings(enabled: true, commentOnStaleApproval: true);

        $this->dispatchChange();
        $this->dispatchChange();

        self::assertCount(1, $this->notices());
        self::assertCount(1, $this->messages());
    }

    public function test_a_pull_request_linked_again_under_a_new_row_inserts_nothing_for_the_same_head(): void
    {
        $this->settings(enabled: true, commentOnStaleApproval: true);
        $this->dispatchChange();

        $this->em->remove($this->pullRequest);
        $this->em->flush();
        $relinked = new ForgePullRequest($this->project, 'github', 'Acme/Widgets', 5);
        $relinked->headSha = $relinked->uncoveredSha = self::HEAD;
        $relinked->approvalId = 'review-1';
        $relinked->approvalSha = $relinked->coveredSha = self::APPROVED;
        $this->em->persist($relinked);
        $this->em->flush();
        $this->pullRequest = $relinked;
        $this->dispatchChange();

        self::assertCount(1, $this->notices());
        self::assertCount(1, $this->messages());
    }

    public function test_a_new_head_gets_its_own_notice(): void
    {
        $this->settings(enabled: true, commentOnStaleApproval: true);
        $this->dispatchChange();

        $this->pullRequest->headSha = $this->pullRequest->uncoveredSha = 'ccccccc3333333333333333333333333333333cc';
        $this->em->flush();
        $this->dispatchChange();

        self::assertCount(2, $this->notices());
        self::assertCount(2, $this->messages());
    }

    public function test_nothing_queues_while_the_setting_is_off(): void
    {
        $this->settings(enabled: true, commentOnStaleApproval: false);

        $this->dispatchChange();

        self::assertSame([], $this->notices());
        self::assertSame([], $this->messages());
    }

    public function test_nothing_queues_while_the_automation_is_off(): void
    {
        $this->settings(enabled: false, commentOnStaleApproval: true);

        $this->dispatchChange();

        self::assertSame([], $this->notices());
    }

    public function test_nothing_queues_when_the_approval_covers_the_head(): void
    {
        $this->settings(enabled: true, commentOnStaleApproval: true);
        $this->pullRequest->coveredSha = self::HEAD;
        $this->em->flush();

        $this->dispatchChange();

        self::assertSame([], $this->notices());
    }

    public function test_nothing_queues_while_the_coverage_of_the_head_is_unknown(): void
    {
        $this->settings(enabled: true, commentOnStaleApproval: true);
        $this->pullRequest->uncoveredSha = null;
        $this->em->flush();

        $this->dispatchChange();

        self::assertSame([], $this->notices());
    }

    public function test_nothing_queues_for_a_closed_pull_request(): void
    {
        $this->settings(enabled: true, commentOnStaleApproval: true);
        $this->pullRequest->state = PullRequestState::Closed;
        $this->em->flush();

        $this->dispatchChange();

        self::assertSame([], $this->notices());
    }

    public function test_a_failed_insert_is_logged_and_leaves_the_forge_transaction_usable(): void
    {
        $this->settings(enabled: true, commentOnStaleApproval: true);
        $connection = $this->em->getConnection();
        $notices = $this->createStub(PullRequestNoticeRepository::class);
        $notices->method('insertIfMissing')->willReturnCallback(static function () use ($connection): never {
            $connection->executeStatement('SELECT * FROM no_such_table');
            throw new \LogicException('The statement above throws.');
        });
        $logger = new RecordingLogger();
        $listener = $this->listener($notices, $logger);

        $answer = $this->em->wrapInTransaction(function () use ($listener, $connection): mixed {
            $listener($this->event());

            return $connection->fetchOne('SELECT 1');
        });

        self::assertSame(1, $answer);
        self::assertSame([], $this->messages());
        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::ERROR, $logger->records[0]['level']);
        self::assertSame('board.pull_request_notice_queue_failed', $logger->records[0]['message']);
    }

    private function settings(bool $enabled, bool $commentOnStaleApproval): void
    {
        $this->em->persist(new BoardAutomationSettings($this->project, enabled: $enabled, commentOnStaleApproval: $commentOnStaleApproval));
        $this->em->flush();
    }

    private function event(): PullRequestStateChanged
    {
        return new PullRequestStateChanged(
            $this->pullRequest,
            new PullRequestSnapshot(headSha: self::APPROVED),
            $this->pullRequest->snapshot(),
        );
    }

    /** Forge dispatches the event inside the transaction that stores the new state. */
    private function dispatchChange(): void
    {
        $events = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $events);
        $event = $this->event();
        $this->em->wrapInTransaction(static fn (): object => $events->dispatch($event));
    }

    private function listener(PullRequestNoticeRepository $notices, RecordingLogger $logger): QueueStaleApprovalNoticeOnPullRequestStateChanged
    {
        $container = self::getContainer();
        $automation = $container->get(BoardAutomation::class);
        self::assertInstanceOf(BoardAutomation::class, $automation);
        $bus = $container->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $clock = $container->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);

        return new QueueStaleApprovalNoticeOnPullRequestStateChanged(
            $automation,
            new PullRequestCommenters([new FakePullRequestCommenter()]),
            $notices,
            $this->em,
            $bus,
            $clock,
            $logger,
        );
    }

    /** @return list<PullRequestNotice> */
    private function notices(): array
    {
        $repository = self::getContainer()->get(PullRequestNoticeRepository::class);
        self::assertInstanceOf(PullRequestNoticeRepository::class, $repository);

        return array_values($repository->findBy(['project' => $this->project], ['createdAt' => 'ASC', 'id' => 'ASC']));
    }

    /** @return list<PostPullRequestNotice> */
    private function messages(): array
    {
        $messages = [];
        foreach ($this->transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof PostPullRequestNotice) {
                $messages[] = $message;
            }
        }

        return $messages;
    }
}
