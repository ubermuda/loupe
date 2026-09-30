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

    public function test_find_newest_settled_answers_the_row_that_posted_or_failed_last(): void
    {
        $project = $this->makeProject('comment-settled');
        $other = $this->makeProject('comment-settled-other');
        $this->comment($project, PullRequestCommentState::Failed, '2026-09-01 10:00:00');
        $failed = $this->comment($project, PullRequestCommentState::Failed, '2026-09-02 10:00:00');
        $this->comment($project, PullRequestCommentState::Pending, null);
        $this->comment($other, PullRequestCommentState::Posted, '2026-09-04 10:00:00');

        self::assertSame($failed, $this->comments->findNewestSettled($project));

        $posted = $this->comment($project, PullRequestCommentState::Posted, '2026-09-03 10:00:00');

        self::assertSame($posted, $this->comments->findNewestSettled($project));
        self::assertNull($this->comments->findNewestSettled($this->makeProject('comment-none')));
    }

    public function test_insert_if_missing_stores_the_fix_round(): void
    {
        $project = $this->makeProject('comment-round');

        $id = $this->insert($project, Uuid::v7(), fixRound: 2);

        self::assertNotNull($id);
        $comment = $this->comments->find($id);
        self::assertInstanceOf(PullRequestComment::class, $comment);
        self::assertSame(2, $comment->fixRound);
    }

    private function insert(Project $project, Uuid $runId, ?int $fixRound = null): ?Uuid
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
            $fixRound,
            null,
            new \DateTimeImmutable('2026-09-29 12:00:00'),
        );
    }

    private function comment(Project $project, PullRequestCommentState $state, ?string $settledAt): PullRequestComment
    {
        $comment = new PullRequestComment($project, Uuid::v7(), Uuid::v7(), 'github', 'acme/widgets', 5, null, null);
        $comment->state = $state;
        $at = null === $settledAt ? null : new \DateTimeImmutable($settledAt);
        if (PullRequestCommentState::Posted === $state) {
            $comment->postedAt = $at;
        } else {
            $comment->failedAt = $at;
        }
        $this->em->persist($comment);
        $this->em->flush();

        return $comment;
    }
}
