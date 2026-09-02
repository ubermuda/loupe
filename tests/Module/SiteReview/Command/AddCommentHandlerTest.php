<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Command;

use App\Module\Account\Entity\User;
use App\Module\Audit\AuditActorProviderInterface;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Command\AddCommentCommand;
use App\Module\SiteReview\Command\AddCommentHandler;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AddCommentHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AddCommentHandler $handler;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $comments = self::getContainer()->get(SiteReviewCommentRepository::class);
        self::assertInstanceOf(SiteReviewCommentRepository::class, $comments);
        $actors = self::getContainer()->get(AuditActorProviderInterface::class);
        self::assertInstanceOf(AuditActorProviderInterface::class, $actors);
        $this->audit = new RecordingAuditor($actors);
        $this->handler = new AddCommentHandler($comments, $this->em, $this->audit->auditor);
    }

    public function test_first_comment_is_pending_at_position_zero(): void
    {
        $project = $this->project('add-a@example.com');
        $comment = ($this->handler)(new AddCommentCommand($project, 'hello', '.a', 'A', 'https://app/x'));

        self::assertNotNull($comment->id);
        self::assertSame(SiteReviewCommentStatus::Pending, $comment->status);
        self::assertSame(0, $comment->position);
    }

    public function test_second_comment_increments_position(): void
    {
        $project = $this->project('add-b@example.com');
        ($this->handler)(new AddCommentCommand($project, 'one', '', '', 'https://app/x'));
        $second = ($this->handler)(new AddCommentCommand($project, 'two', '', '', 'https://app/y'));

        self::assertSame(1, $second->position);
    }

    public function test_position_keeps_incrementing_after_a_send(): void
    {
        $project = $this->project('add-c@example.com');
        ($this->handler)(new AddCommentCommand($project, 'one', '', '', 'https://app/x'));
        $this->em->getConnection()->executeStatement(
            'UPDATE site_review_comments SET status = :status WHERE project_id = :project',
            ['status' => 'pending', 'project' => (string) $project->id],
        );

        // No batch boundary anymore: position is a project-wide monotonic
        // counter, so a comment added after a send simply continues it.
        $next = ($this->handler)(new AddCommentCommand($project, 'two', '', '', 'https://app/y'));

        self::assertSame(1, $next->position);
    }

    public function test_an_added_comment_is_recorded_on_the_domain_channel(): void
    {
        $project = $this->project('add-audit@example.com');

        $comment = ($this->handler)(new AddCommentCommand($project, 'hello', '.a', 'A', 'https://app/x'));

        $record = $this->audit->record('site_review.comment_added');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('site_review_comment', $record->subject->type);
        self::assertSame((string) $comment->id, $record->subject->id);
        self::assertSame([
            'projectId' => (string) $project->id,
            'commentId' => (string) $comment->id,
        ], $record->context);

        self::assertSame(['site_review.comment_added'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
    }

    public function test_the_handler_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(AddCommentHandler::class);
    }

    /** @param non-empty-string $email */
    private function project(string $email, string $name = 'handler-site'): Project
    {
        $user = new User(fullName: 'U', email: $email, password: 'x');
        $this->em->persist($user);
        $project = new Project($user, $name);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
