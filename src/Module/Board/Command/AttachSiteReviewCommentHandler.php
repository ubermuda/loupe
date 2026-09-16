<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class AttachSiteReviewCommentHandler
{
    public function __construct(
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(AttachSiteReviewCommentCommand $command): CardSiteReviewComment
    {
        if ($command->card->project !== $command->comment->project) {
            throw new DomainErrors(['card' => 'site_review.attach.error.foreign_card']);
        }
        if (null !== $this->cardSiteReviewComments->findOneBy(['comment' => $command->comment])) {
            throw new DomainErrors(['card' => 'site_review.attach.error.already_linked']);
        }

        $link = new CardSiteReviewComment($command->card, $command->comment);
        $this->em->persist($link);
        $this->em->flush();

        $this->auditor->record(
            'board.site_review_attached',
            AuditOutcome::Success,
            [
                'cardId' => (string) $command->card->id,
                'commentId' => (string) $command->comment->id,
                'projectId' => (string) $command->card->project->id,
            ],
            new AuditSubject('card', (string) $command->card->id),
        );

        return $link;
    }
}
