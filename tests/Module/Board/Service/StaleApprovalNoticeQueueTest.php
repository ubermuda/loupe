<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Entity\PullRequestCommentState;
use App\Module\Board\Entity\PullRequestNotice;
use App\Module\Board\Messenger\PostPullRequestNotice;
use App\Module\Board\Repository\PullRequestNoticeRepository;
use App\Module\Board\Service\CardPullRequests;
use App\Module\Board\Service\StaleApprovalNoticeQueue;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Service\PullRequestCommenters;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class StaleApprovalNoticeQueueTest extends KernelTestCase
{
    use BoardToolScenario;

    private const string APPROVED = 'aaaaaaa1111111111111111111111111111111aa';
    private const string HEAD = 'bbbbbbb2222222222222222222222222222222bb';

    private EntityManagerInterface $em;
    private InMemoryTransport $transport;
    private Project $project;
    private Card $card;
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
        $this->card = new Card($this->project, $this->column($this->project, 'backlog'), 'Ship it', '', 1);
        $this->em->persist($this->card);
        $this->em->persist(new CardPullRequest($this->card, 'https://github.com/acme/widgets/pull/5', Forge::GitHub, 'Acme/Widgets', 5));
        $this->pullRequest = $this->stale('github');
    }

    public function test_a_stale_approval_stores_one_pending_notice_and_queues_one_message(): void
    {
        self::assertSame([$this->pullRequest], $this->queue()->unnoticed($this->card));

        self::assertTrue($this->queue()->queue($this->card));

        $notices = $this->notices();
        self::assertCount(1, $notices);
        self::assertEquals($this->pullRequest->id, $notices[0]->forgePullRequestId);
        self::assertSame('stale-approval:'.self::HEAD, $notices[0]->noticeKey);
        self::assertSame(PullRequestCommentState::Pending, $notices[0]->state);
        $messages = $this->messages();
        self::assertCount(1, $messages);
        self::assertEquals($notices[0]->id, $messages[0]->noticeId);
        self::assertSame([], $this->queue()->unnoticed($this->card));
    }

    public function test_a_second_queue_for_the_same_head_inserts_nothing(): void
    {
        $this->queue()->queue($this->card);

        self::assertFalse($this->queue()->queue($this->card));

        self::assertCount(1, $this->notices());
        self::assertCount(1, $this->messages());
    }

    public function test_a_pull_request_tracked_again_under_a_new_row_inserts_nothing_for_the_same_head(): void
    {
        $this->queue()->queue($this->card);

        $this->em->remove($this->pullRequest);
        $this->em->flush();
        $this->stale('github');

        self::assertSame([], $this->queue()->unnoticed($this->card));
        self::assertFalse($this->queue()->queue($this->card));
        self::assertCount(1, $this->notices());
    }

    public function test_a_new_head_gets_its_own_notice(): void
    {
        $this->queue()->queue($this->card);

        $this->pullRequest->headSha = $this->pullRequest->uncoveredSha = 'ccccccc3333333333333333333333333333333cc';
        $this->em->flush();

        self::assertTrue($this->queue()->queue($this->card));
        self::assertCount(2, $this->notices());
        self::assertCount(2, $this->messages());
    }

    public function test_an_approval_that_covers_the_head_gets_no_notice(): void
    {
        $this->pullRequest->coveredSha = self::HEAD;
        $this->em->flush();

        self::assertSame([], $this->queue()->unnoticed($this->card));
        self::assertFalse($this->queue()->queue($this->card));
        self::assertSame([], $this->notices());
    }

    public function test_a_head_whose_coverage_is_unknown_gets_no_notice(): void
    {
        $this->pullRequest->uncoveredSha = null;
        $this->em->flush();

        self::assertSame([], $this->queue()->unnoticed($this->card));
    }

    public function test_a_closed_pull_request_gets_no_notice(): void
    {
        $this->pullRequest->state = PullRequestState::Closed;
        $this->em->flush();

        self::assertSame([], $this->queue()->unnoticed($this->card));
    }

    public function test_a_forge_that_cannot_comment_gets_no_notice(): void
    {
        $this->em->persist(new CardPullRequest($this->card, 'https://gitlab.com/acme/widgets/-/merge_requests/6', Forge::GitLab, 'acme/widgets', 6));
        $this->em->remove($this->pullRequest);
        $this->em->flush();
        $gitLab = $this->stale('gitlab', 6);
        // The guard: Forge tracks the pull request of the card, so only the missing commenter leaves it out.
        $tracked = self::getContainer()->get(CardPullRequests::class);
        self::assertInstanceOf(CardPullRequests::class, $tracked);
        self::assertSame([$gitLab], $tracked->forCard($this->card));

        self::assertSame([], $this->queue()->unnoticed($this->card));
        self::assertFalse($this->queue()->queue($this->card));
    }

    public function test_a_message_that_fails_to_queue_leaves_no_pending_row(): void
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('transport down'));
        $container = self::getContainer();
        $queue = new StaleApprovalNoticeQueue(
            $container->get(CardPullRequests::class),
            $container->get(PullRequestCommenters::class),
            $container->get(PullRequestNoticeRepository::class),
            $this->em,
            $bus,
            $container->get(ClockInterface::class),
            new NullLogger(),
        );

        try {
            $queue->queue($this->card);
            self::fail('The failed dispatch reaches the caller.');
        } catch (\RuntimeException $e) {
            self::assertSame('transport down', $e->getMessage());
        }

        self::assertSame([], $this->notices());
    }

    private function stale(string $forge, int $number = 5): ForgePullRequest
    {
        $pullRequest = new ForgePullRequest($this->project, $forge, 'acme/widgets', $number);
        $pullRequest->headSha = self::HEAD;
        $pullRequest->approvalId = 'review-1';
        $pullRequest->approvalSha = self::APPROVED;
        $pullRequest->coveredSha = self::APPROVED;
        $pullRequest->uncoveredSha = self::HEAD;
        $this->em->persist($pullRequest);
        $this->em->flush();

        return $pullRequest;
    }

    private function queue(): StaleApprovalNoticeQueue
    {
        $queue = self::getContainer()->get(StaleApprovalNoticeQueue::class);
        self::assertInstanceOf(StaleApprovalNoticeQueue::class, $queue);

        return $queue;
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
