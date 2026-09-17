<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxReviewTargetKind;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Repository\InboxReviewRepository;
use App\Module\Inbox\Service\InboxItemCloser;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Repository\DocumentRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class SubmitInboxDocumentReviewHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private InboxItemRepository $inboxItems,
        private InboxReviewRepository $inboxReviews,
        private DocumentRepository $documents,
        private SubmitReviewHandler $submitReview,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(SubmitInboxDocumentReviewCommand $command): InboxItem
    {
        $item = $command->item;
        if (InboxItemKind::Review !== $item->kind) {
            throw new DomainErrors(['verdict' => 'inbox.review.error.not_a_review']);
        }
        $error = $this->em->wrapInTransaction(function () use ($command, $item): ?DomainErrors {
            $this->em->lock($item->project, LockMode::PESSIMISTIC_WRITE);
            $this->inboxItems->reloadChangeableColumns([$item]);
            if (InboxItemState::Open !== $item->state) {
                return new DomainErrors(['verdict' => InboxItemCloser::ERROR_FINAL]);
            }
            $review = $this->inboxReviews->findOneBy(['item' => $item]);
            if (null === $review || InboxReviewTargetKind::Document !== $review->targetKind) {
                return new DomainErrors(['verdict' => 'inbox.review.error.not_a_document']);
            }
            $document = null === $review->document ? null : $this->documents->findForUpdate($review->document, $item->project);
            if (null === $document) {
                return new DomainErrors(['versionNumber' => 'inbox.review.error.target_unavailable']);
            }
            try {
                ($this->submitReview)(new SubmitReviewCommand(
                    reviewer: $command->reviewer,
                    document: $document,
                    verdict: $command->verdict,
                    versionNumber: $command->versionNumber,
                    note: $command->note,
                    expectedReviewId: $command->expectedReviewId,
                ));
            } catch (DomainErrors $error) {
                return $error;
            }

            return null;
        });
        if (null !== $error) {
            throw $error;
        }

        $this->auditor->record(
            'inbox.review_submitted',
            AuditOutcome::Success,
            ['itemId' => (string) $item->id, 'projectId' => (string) $item->project->id, 'verdict' => $command->verdict],
            new AuditSubject('inbox_item', (string) $item->id),
        );

        return $item;
    }
}
