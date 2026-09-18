<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Command\ReplyToSiteReviewCommentCommand;
use App\Module\SiteReview\Command\ReplyToSiteReviewCommentHandler;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentAnchor;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Repository\SiteReviewReplyRepository;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ReplyToSiteReviewCommentHandlerTest extends KernelTestCase
{
    #[TestWith(['pending'])]
    #[TestWith(['addressed'])]
    #[TestWith(['resolved'])]
    public function test_replies_append_once_and_preserve_the_original_feedback(string $status): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = new User('Riley Chen', 'site-reply@example.com', 'x');
        $project = new Project($owner, 'Site replies');
        $comment = new SiteReviewComment($project, 0, 'Original feedback.', 'https://example.com/page', 'card:original');
        $comment->addAnchor('.hero', 'Original heading');
        $comment->status = SiteReviewCommentStatus::from($status);
        foreach ([$owner, $project, $comment] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $repository = self::getContainer()->get(SiteReviewReplyRepository::class);
        self::assertInstanceOf(SiteReviewReplyRepository::class, $repository);
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $handler = new ReplyToSiteReviewCommentHandler($em, $repository, $audit->auditor);
        $submissionId = (string) Uuid::v4();
        $command = new ReplyToSiteReviewCommentCommand($comment, $owner, '  More context.  ', $submissionId);
        $first = $handler($command);
        self::assertSame((string) $first->id, (string) $handler($command)->id);
        $audit->record('site_review.reply_added');
        try {
            $handler(new ReplyToSiteReviewCommentCommand($comment, $owner, 'Changed draft.', $submissionId));
            self::fail('A repeated submission must not replace its saved reply.');
        } catch (DomainErrors $error) {
            self::assertSame(['submissionId' => 'site_review.reply.error.submission'], $error->errors);
        }
        self::assertTrue($em->isOpen());
        $em->clear();
        $stored = $em->find(SiteReviewComment::class, $comment->id);
        self::assertInstanceOf(SiteReviewComment::class, $stored);
        self::assertSame($status, $stored->status->value);
        self::assertSame('Original feedback.', $stored->body);
        self::assertSame('https://example.com/page', $stored->url);
        self::assertSame('card:original', $stored->context);
        self::assertCount(1, $stored->anchors);
        $anchor = $stored->anchors->first();
        self::assertInstanceOf(SiteReviewCommentAnchor::class, $anchor);
        self::assertSame('.hero', $anchor->selector);
        $replies = $repository->findForComments([$stored]);
        self::assertCount(1, $replies);
        self::assertSame('More context.', $replies[0]->body);
        self::assertSame((string) $owner->id, (string) $replies[0]->author->id);
        $em->remove($stored);
        $em->flush();
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM site_review_replies WHERE comment_id = ?', [(string) $comment->id]));
    }
}
