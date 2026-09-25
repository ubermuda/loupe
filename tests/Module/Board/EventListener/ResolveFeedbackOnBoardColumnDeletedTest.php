<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Command\DeleteBoardColumnCommand;
use App\Module\Board\Command\DeleteBoardColumnHandler;
use App\Module\Board\Entity\CardReporter;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Driven through a real column delete, so the listener runs where DeleteBoardColumnHandler dispatches BoardColumnDeleted. */
final class ResolveFeedbackOnBoardColumnDeletedTest extends KernelTestCase
{
    use ResolveFeedbackScenario;

    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->audit = RecordingAuditor::installedIn(self::getContainer());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_a_delete_into_a_terminal_column_resolves_the_feedback_of_every_moved_card(): void
    {
        $project = $this->feedbackProject('feedback-delete-done');
        $first = $this->feedback($this->cardIn($project, 'in-progress', 1), SiteReviewCommentStatus::Pending);
        $second = $this->feedback($this->cardIn($project, 'in-progress', 2), SiteReviewCommentStatus::Addressed);
        $elsewhere = $this->feedback($this->cardIn($project, 'backlog', 3), SiteReviewCommentStatus::Pending);
        $this->em->flush();

        $this->delete($project, 'in-progress', 'done');

        self::assertSame(SiteReviewCommentStatus::Resolved, $this->statusOf($first));
        self::assertSame(SiteReviewCommentStatus::Resolved, $this->statusOf($second));
        self::assertSame(SiteReviewCommentStatus::Pending, $this->statusOf($elsewhere));

        $records = $this->audit->records('site_review.comment_resolved');
        self::assertCount(2, $records);
        foreach ($records as $record) {
            self::assertSame('card_moved', $record->context['trigger']);
            self::assertSame('human', $record->context['actor']);
        }
    }

    public function test_a_delete_into_an_open_column_resolves_nothing(): void
    {
        $project = $this->feedbackProject('feedback-delete-open');
        $comment = $this->feedback($this->cardIn($project, 'in-progress'), SiteReviewCommentStatus::Pending);
        $this->em->flush();

        $this->delete($project, 'in-progress', 'next');

        self::assertSame(SiteReviewCommentStatus::Pending, $this->statusOf($comment));
        self::assertSame([], $this->audit->records('site_review.comment_resolved'));
    }

    public function test_a_delete_of_a_terminal_column_into_another_resolves_nothing(): void
    {
        $project = $this->feedbackProject('feedback-delete-terminal');
        $comment = $this->feedback($this->cardIn($project, 'done'), SiteReviewCommentStatus::Addressed);
        $this->em->flush();

        $this->delete($project, 'done', 'shipped');

        self::assertSame(SiteReviewCommentStatus::Addressed, $this->statusOf($comment));
        self::assertSame([], $this->audit->records('site_review.comment_resolved'));
    }

    private function delete(Project $project, string $slug, string $target): void
    {
        $handler = self::getContainer()->get(DeleteBoardColumnHandler::class);
        self::assertInstanceOf(DeleteBoardColumnHandler::class, $handler);

        $handler(new DeleteBoardColumnCommand($this->column($project, $slug), CardReporter::Human, $this->column($project, $target)));
    }
}
