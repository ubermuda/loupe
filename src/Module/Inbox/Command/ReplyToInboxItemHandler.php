<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Inbox\Entity\InboxReply;
use App\Module\Inbox\Repository\InboxReplyRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class ReplyToInboxItemHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private InboxReplyRepository $inboxReplies,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(ReplyToInboxItemCommand $command): InboxReply
    {
        $body = trim($command->body);
        if ('' === $body || mb_strlen($body) > InboxReply::MAX_BODY_LENGTH) {
            throw new DomainErrors(['body' => 'inbox.reply.error.body']);
        }
        if (!Uuid::isValid($command->submissionId)) {
            throw new DomainErrors(['submissionId' => 'inbox.reply.error.submission']);
        }
        $submissionId = Uuid::fromString($command->submissionId);
        $created = false;
        $result = $this->em->wrapInTransaction(function () use ($command, $body, $submissionId, &$created): InboxReply|DomainErrors {
            $this->em->lock($command->item->project, LockMode::PESSIMISTIC_WRITE);
            $existing = $this->inboxReplies->findOneBy(['item' => $command->item, 'submissionId' => $submissionId]);
            if (null !== $existing) {
                if ($existing->body !== $body || !$existing->author->id?->equals($command->author->id)) {
                    return new DomainErrors(['submissionId' => 'inbox.reply.error.submission']);
                }

                return $existing;
            }
            $reply = new InboxReply($command->item, $command->author, $body, $submissionId);
            $this->em->persist($reply);
            $created = true;

            return $reply;
        });
        if ($result instanceof DomainErrors) {
            throw $result;
        }
        if ($created) {
            $this->auditor->record('inbox.reply_added', AuditOutcome::Success,
                ['itemId' => (string) $command->item->id, 'replyId' => (string) $result->id],
                new AuditSubject('inbox_item', (string) $command->item->id),
            );
        }

        return $result;
    }
}
