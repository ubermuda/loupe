<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Exception\DomainErrors;
use App\Module\SiteReview\Entity\SiteReviewReply;
use App\Module\SiteReview\Repository\SiteReviewReplyRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class ReplyToSiteReviewCommentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private SiteReviewReplyRepository $siteReviewReplies,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(ReplyToSiteReviewCommentCommand $command): SiteReviewReply
    {
        $body = trim($command->body);
        if ('' === $body || mb_strlen($body) > SiteReviewReply::MAX_BODY_LENGTH) {
            throw new DomainErrors(['body' => 'site_review.reply.error.body']);
        }
        if (!Uuid::isValid($command->submissionId)) {
            throw new DomainErrors(['submissionId' => 'site_review.reply.error.submission']);
        }
        $submissionId = Uuid::fromString($command->submissionId);
        $created = false;
        $result = $this->em->wrapInTransaction(function () use ($command, $body, $submissionId, &$created): SiteReviewReply|DomainErrors {
            $this->em->lock($command->comment, LockMode::PESSIMISTIC_WRITE);
            $existing = $this->siteReviewReplies->findOneBy(['comment' => $command->comment, 'submissionId' => $submissionId]);
            if (null !== $existing) {
                if ($existing->body !== $body || !$existing->author->id?->equals($command->author->id)) {
                    return new DomainErrors(['submissionId' => 'site_review.reply.error.submission']);
                }

                return $existing;
            }
            $reply = new SiteReviewReply($command->comment, $command->author, $body, $submissionId);
            $this->em->persist($reply);
            $created = true;

            return $reply;
        });
        if ($result instanceof DomainErrors) {
            throw $result;
        }
        if ($created) {
            $this->auditor->record('site_review.reply_added', AuditOutcome::Success,
                ['commentId' => (string) $command->comment->id, 'replyId' => (string) $result->id],
                new AuditSubject('site_review_comment', (string) $command->comment->id),
            );
        }

        return $result;
    }
}
