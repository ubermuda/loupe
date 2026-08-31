<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Command;

use App\Module\Account\Entity\User;
use App\Module\Audit\AuditActorProviderInterface;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Command\ReopenSiteReviewCommentCommand;
use App\Module\SiteReview\Command\ReopenSiteReviewCommentHandler;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReopenSiteReviewCommentHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ReopenSiteReviewCommentHandler $handler;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $actors = self::getContainer()->get(AuditActorProviderInterface::class);
        self::assertInstanceOf(AuditActorProviderInterface::class, $actors);
        $this->audit = new RecordingAuditor($actors);
        $this->handler = new ReopenSiteReviewCommentHandler($this->em, $this->audit->auditor);
    }

    public function test_puts_the_comment_back_to_pending(): void
    {
        $comment = $this->comment('reopen-a@example.com');

        ($this->handler)(new ReopenSiteReviewCommentCommand($comment));

        self::assertSame(SiteReviewCommentStatus::Pending, $comment->status);
    }

    public function test_a_reopened_comment_is_recorded_on_the_domain_channel(): void
    {
        $comment = $this->comment('reopen-audit@example.com');

        ($this->handler)(new ReopenSiteReviewCommentCommand($comment));

        $record = $this->audit->record('site_review.comment.reopened');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('site_review_comment', $record->subject->type);
        self::assertSame((string) $comment->id, $record->subject->id);
        self::assertSame(['commentId' => (string) $comment->id], $record->context);

        self::assertSame(['site_review.comment.reopened'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
    }

    public function test_the_handler_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(ReopenSiteReviewCommentHandler::class);
    }

    /** @param non-empty-string $email */
    private function comment(string $email): SiteReviewComment
    {
        $user = new User(fullName: 'U', email: $email, password: 'x');
        $this->em->persist($user);
        $project = new Project($user, 'reopen-site');
        $this->em->persist($project);
        $comment = new SiteReviewComment($project, 0, 'Fix this', '.a', 'A', 'https://app/x');
        $comment->status = SiteReviewCommentStatus::Resolved;
        $this->em->persist($comment);
        $this->em->flush();

        return $comment;
    }
}
