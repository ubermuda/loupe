<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Mcp\FeedbackMarkAddressedTool;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FeedbackMarkAddressedToolTest extends KernelTestCase
{
    use BoardToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private FeedbackMarkAddressedTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(FeedbackMarkAddressedTool::class);
        self::assertInstanceOf(FeedbackMarkAddressedTool::class, $tool);
        $this->tool = $tool;
    }

    public function test_the_tool_refuses_while_the_flag_is_off(): void
    {
        $this->disableBoard();
        $project = $this->makeProject('feedback-mark-flag-off');
        $pending = $this->feedback($project, SiteReviewCommentStatus::Pending);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        try {
            ($this->tool)([(string) $pending->id]);
            self::fail('The tool ran with the board off.');
        } catch (ToolCallException $e) {
            self::assertSame('The board is switched off on this instance.', $e->getMessage());
        }

        self::assertSame(SiteReviewCommentStatus::Pending, $this->statusOf($pending));
    }

    public function test_a_batch_reports_one_outcome_per_id(): void
    {
        $this->enableBoard();
        $project = $this->makeProject('feedback-mark');
        $pending = $this->feedback($project, SiteReviewCommentStatus::Pending);
        $addressed = $this->feedback($project, SiteReviewCommentStatus::Addressed);
        $resolved = $this->feedback($project, SiteReviewCommentStatus::Resolved);
        $foreign = $this->feedback($this->makeProject('feedback-mark-theirs'), SiteReviewCommentStatus::Pending);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->tool)([
            (string) $pending->id,
            (string) $addressed->id,
            (string) $resolved->id,
            (string) $foreign->id,
            'not-a-uuid',
        ]);

        self::assertSame([(string) $pending->id], $result['addressed']);
        self::assertSame([
            ['id' => (string) $addressed->id, 'reason' => 'already_addressed'],
            ['id' => (string) $resolved->id, 'reason' => 'resolved'],
            ['id' => (string) $foreign->id, 'reason' => 'unknown'],
            ['id' => 'not-a-uuid', 'reason' => 'invalid_id'],
        ], $result['skipped']);

        self::assertSame(SiteReviewCommentStatus::Addressed, $this->statusOf($pending));
        self::assertSame(SiteReviewCommentStatus::Pending, $this->statusOf($foreign));
    }

    /**
     * A person resolves the note after the tool read it. The write goes straight
     * to the database, so the tool's own copy still says Pending.
     */
    public function test_a_resolve_the_identity_map_has_not_seen_is_not_overwritten(): void
    {
        $this->enableBoard();
        $project = $this->makeProject('feedback-mark-race');
        $pending = $this->feedback($project, SiteReviewCommentStatus::Pending);
        $this->em->flush();
        $this->setStatusBehindTheTool($pending, SiteReviewCommentStatus::Resolved);
        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->tool)([(string) $pending->id]);

        self::assertSame([], $result['addressed']);
        self::assertSame([['id' => (string) $pending->id, 'reason' => 'resolved']], $result['skipped']);
        self::assertSame(SiteReviewCommentStatus::Resolved, $this->statusOf($pending));
    }

    /** A second agent got there first, and the reason comes from the row it wrote. */
    public function test_a_concurrent_address_is_reported_as_addressed_not_resolved(): void
    {
        $this->enableBoard();
        $project = $this->makeProject('feedback-mark-concurrent');
        $pending = $this->feedback($project, SiteReviewCommentStatus::Pending);
        $this->em->flush();
        $this->setStatusBehindTheTool($pending, SiteReviewCommentStatus::Addressed);
        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->tool)([(string) $pending->id]);

        self::assertSame([], $result['addressed']);
        self::assertSame([['id' => (string) $pending->id, 'reason' => 'already_addressed']], $result['skipped']);
    }

    public function test_an_unbound_token_is_refused_even_for_an_empty_batch(): void
    {
        $this->enableBoard();
        $project = $this->makeProject('feedback-mark-unbound');
        $this->actAsUnboundMcpToken($project->owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('MCP token is not bound to a project. Mint a project token from the Connect page.');
        ($this->tool)([]);
    }

    private function statusOf(SiteReviewComment $comment): SiteReviewCommentStatus
    {
        $this->em->clear();
        $fresh = $this->em->find(SiteReviewComment::class, $comment->id);
        self::assertInstanceOf(SiteReviewComment::class, $fresh);

        return $fresh->status;
    }

    private function setStatusBehindTheTool(SiteReviewComment $comment, SiteReviewCommentStatus $status): void
    {
        $this->em->createQuery('UPDATE '.SiteReviewComment::class.' c SET c.status = :status WHERE c.id = :id')
            ->setParameter('status', $status)
            ->setParameter('id', $comment->id, 'uuid')
            ->execute();
        self::assertSame(SiteReviewCommentStatus::Pending, $comment->status);
    }

    private function feedback(Project $project, SiteReviewCommentStatus $status): SiteReviewComment
    {
        $comment = new SiteReviewComment($project, 0, 'Feedback', 'https://app.example/page');
        $comment->status = $status;
        $this->em->persist($comment);

        return $comment;
    }
}
