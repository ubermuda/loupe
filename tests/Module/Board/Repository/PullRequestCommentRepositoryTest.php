<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Repository;

use App\Module\Board\Entity\PullRequestComment;
use App\Module\Board\Entity\PullRequestCommentState;
use App\Module\Board\Repository\PullRequestCommentRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class PullRequestCommentRepositoryTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private PullRequestCommentRepository $comments;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $comments = self::getContainer()->get(PullRequestCommentRepository::class);
        self::assertInstanceOf(PullRequestCommentRepository::class, $comments);
        $this->comments = $comments;
    }

    public function test_insert_if_missing_answers_the_new_id_once_per_run(): void
    {
        $project = $this->makeProject('comment-insert');
        $runId = Uuid::v7();

        $first = $this->insert($project, $runId);
        $second = $this->insert($project, $runId);

        self::assertNotNull($first);
        self::assertNull($second);
        $comment = $this->comments->find($first);
        self::assertInstanceOf(PullRequestComment::class, $comment);
        self::assertEquals($runId, $comment->runId);
        self::assertSame(PullRequestCommentState::Pending, $comment->state);
    }

    public function test_find_newest_failed_answers_the_latest_failure_of_the_project(): void
    {
        $project = $this->makeProject('comment-failed');
        $other = $this->makeProject('comment-failed-other');
        $this->comment($project, PullRequestCommentState::Failed, '2026-09-01 10:00:00');
        $newest = $this->comment($project, PullRequestCommentState::Failed, '2026-09-02 10:00:00');
        $this->comment($project, PullRequestCommentState::Posted, null);
        $this->comment($other, PullRequestCommentState::Failed, '2026-09-03 10:00:00');

        self::assertSame($newest, $this->comments->findNewestFailed($project));
        self::assertNull($this->comments->findNewestFailed($this->makeProject('comment-none')));
    }

    private function insert(Project $project, Uuid $runId): ?Uuid
    {
        return $this->comments->insertIfMissing(
            $project->id ?? throw new \LogicException('A persisted project has an id.'),
            $runId,
            Uuid::v7(),
            'github',
            'acme/widgets',
            5,
            null,
            'conflict',
            new \DateTimeImmutable('2026-09-29 12:00:00'),
        );
    }

    private function comment(Project $project, PullRequestCommentState $state, ?string $failedAt): PullRequestComment
    {
        $comment = new PullRequestComment($project, Uuid::v7(), Uuid::v7(), 'github', 'acme/widgets', 5, null, null);
        $comment->state = $state;
        $comment->failedAt = null === $failedAt ? null : new \DateTimeImmutable($failedAt);
        $this->em->persist($comment);
        $this->em->flush();

        return $comment;
    }
}
