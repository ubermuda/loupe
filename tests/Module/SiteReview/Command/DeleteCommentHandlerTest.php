<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Command\AddCommentCommand;
use App\Module\SiteReview\Command\AddCommentHandler;
use App\Module\SiteReview\Command\CommentNotFound;
use App\Module\SiteReview\Command\DeleteCommentCommand;
use App\Module\SiteReview\Command\DeleteCommentHandler;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\AuditActorProviderInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final class DeleteCommentHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AddCommentHandler $addHandler;
    private DeleteCommentHandler $handler;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $addHandler = self::getContainer()->get(AddCommentHandler::class);
        self::assertInstanceOf(AddCommentHandler::class, $addHandler);
        $this->addHandler = $addHandler;
        $comments = self::getContainer()->get(SiteReviewCommentRepository::class);
        self::assertInstanceOf(SiteReviewCommentRepository::class, $comments);
        $actors = self::getContainer()->get(AuditActorProviderInterface::class);
        self::assertInstanceOf(AuditActorProviderInterface::class, $actors);
        $this->audit = new RecordingAuditor($actors);
        $this->handler = new DeleteCommentHandler($comments, $this->em, $this->audit->auditor);
    }

    public function test_deletes_a_pending_comment(): void
    {
        $project = $this->project('del-a@example.com');
        $comment = ($this->addHandler)(new AddCommentCommand($project, 'to delete', '', '', 'https://app/x'));
        $id = $comment->id ?? throw new \LogicException('comment id must not be null');

        ($this->handler)(new DeleteCommentCommand($project, $id));

        $this->em->clear();
        self::assertNull($this->em->find(SiteReviewComment::class, $id));
    }

    public function test_addressed_comment_throws_comment_not_found(): void
    {
        $project = $this->project('del-b@example.com');
        $comment = ($this->addHandler)(new AddCommentCommand($project, 'orig', '', '', 'https://app/x'));
        $comment->status = SiteReviewCommentStatus::Addressed;
        $this->em->flush();

        $this->expectException(CommentNotFound::class);
        ($this->handler)(new DeleteCommentCommand($project, $comment->id ?? throw new \LogicException('comment id must not be null')));
    }

    public function test_other_sites_comment_throws_comment_not_found(): void
    {
        $siteA = $this->project('del-c@example.com', 'site-a');
        $siteB = $this->project('del-d@example.com', 'site-b');
        $comment = ($this->addHandler)(new AddCommentCommand($siteA, 'orig', '', '', 'https://app/x'));

        $this->expectException(CommentNotFound::class);
        ($this->handler)(new DeleteCommentCommand($siteB, $comment->id ?? throw new \LogicException('comment id must not be null')));
    }

    public function test_a_deleted_comment_is_recorded_on_the_domain_channel(): void
    {
        $project = $this->project('del-audit@example.com');
        $comment = ($this->addHandler)(new AddCommentCommand($project, 'to delete', '', '', 'https://app/x'));
        $commentId = $comment->id ?? throw new \LogicException('comment id must not be null');

        ($this->handler)(new DeleteCommentCommand($project, $commentId));

        $record = $this->audit->record('site_review.comment_deleted');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('site_review_comment', $record->subject->type);
        self::assertSame((string) $commentId, $record->subject->id);
        self::assertSame([
            'projectId' => (string) $project->id,
            'commentId' => (string) $commentId,
        ], $record->context);

        self::assertSame(['site_review.comment_deleted'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
    }

    public function test_an_unknown_comment_records_nothing(): void
    {
        $siteA = $this->project('del-audit-miss-a@example.com', 'site-a');
        $siteB = $this->project('del-audit-miss-b@example.com', 'site-b');
        $comment = ($this->addHandler)(new AddCommentCommand($siteA, 'orig', '', '', 'https://app/x'));
        $commentId = $comment->id ?? throw new \LogicException('comment id must not be null');

        try {
            ($this->handler)(new DeleteCommentCommand($siteB, $commentId));
            self::fail('Expected CommentNotFound for a comment of another project.');
        } catch (CommentNotFound) {
        }

        self::assertSame([], $this->audit->operations());
    }

    public function test_the_handler_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(DeleteCommentHandler::class);
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
