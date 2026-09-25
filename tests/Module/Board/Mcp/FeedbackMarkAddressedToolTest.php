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

    private function statusOf(SiteReviewComment $comment): SiteReviewCommentStatus
    {
        $this->em->clear();
        $fresh = $this->em->find(SiteReviewComment::class, $comment->id);
        self::assertInstanceOf(SiteReviewComment::class, $fresh);

        return $fresh->status;
    }

    private function feedback(Project $project, SiteReviewCommentStatus $status): SiteReviewComment
    {
        $comment = new SiteReviewComment($project, 0, 'Feedback', 'https://app.example/page');
        $comment->status = $status;
        $this->em->persist($comment);

        return $comment;
    }
}
