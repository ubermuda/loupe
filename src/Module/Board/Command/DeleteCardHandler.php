<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Event\BoardColumnsChanged;
use App\Module\Board\Event\CardBlockersRemoved;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Event\CardDeleted;
use App\Module\Board\Event\CardParentChanged;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Board\Service\CardGroupOrder;
use App\Module\Board\Service\CardParentPolicy;
use App\Module\Board\Service\CardTypeCatalog;
use App\Module\Board\Service\PullRequestTracking;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\Service\InteractiveRuns;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Deletes a card, its pull request links, which orphanRemoval takes with it,
 * and the site-review comments linked to it.
 */
final readonly class DeleteCardHandler
{
    public function __construct(
        private CardRepository $cards,
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private CardGroupOrder $groupOrder,
        private CardParentPolicy $parentPolicy,
        private CardTypeCatalog $catalog,
        private PullRequestTracking $pullRequestTracking,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private EventDispatcherInterface $events,
        private InteractiveRuns $interactiveRuns,
        private CardHolds $cardHolds,
    ) {
    }

    public function __invoke(DeleteCardCommand $command): void
    {
        $card = $command->card;
        $actor = $command->actor;

        // Read before the remove: the flush clears the id. The number and the
        // project are readonly, so no re-read can change them.
        $cardUuid = $card->id ?? throw new \LogicException('Card has no id.');
        $projectUuid = $card->project->id ?? throw new \LogicException('Project has no id.');
        $cardId = (string) $cardUuid;
        $cardNumber = $card->number;
        $projectId = (string) $projectUuid;

        $drawsLane = $this->em->wrapInTransaction(function () use ($card, $actor): DomainErrors|bool {
            // The renumbering below reads the column first, so it takes the same
            // project lock a create or a move does.
            $this->em->lock($card->project, LockMode::PESSIMISTIC_WRITE);
            // lock() takes the project row and leaves the loaded card as the
            // request read it, which may be before the caller ahead of us in
            // the queue committed. Without the re-read, the gap is closed in
            // the column the card was in then rather than the one it is in now.
            $this->cards->refreshColumn($card);

            // Counted under the lock, so no child joins the epic between the
            // count and the delete.
            $refusal = $this->parentPolicy->deleteRefusal($card);
            if (null !== $refusal) {
                return $refusal;
            }

            $this->cards->refreshTypeAndParent($card);
            $parent = $card->parent;
            $drawsLane = $card->drawsLane($this->catalog->forProject($card->project));

            // Inside the transaction, so the delete and the renumbering it
            // causes commit together or not at all.
            $this->groupOrder->compact($card->column, $card);

            // The link rows cascade in the database, but the comments would
            // outlive the card. The link goes through the ORM too, or a later
            // flush finds it pointing at a removed comment.
            foreach ($this->cardSiteReviewComments->findForCard($card) as $link) {
                $this->em->remove($link);
                $this->em->remove($link->comment);
            }

            // No tool and no page can reach the runs or the hold of a deleted card.
            if (null !== $card->id) {
                $this->interactiveRuns->closeOnMove($card->project, [$card->id]);
                $this->cardHolds->release($card->project, [$card->id]);
            }

            // The link rows cascade in the database, so read the cards they block first.
            $unblocked = $this->cards->findBlockedBy($card);
            $trackedBefore = $this->pullRequestTracking->referencesOf($card);
            $deletedCardId = $card->id ?? throw new \LogicException('A persisted card has an id.');
            $deletedProjectId = $card->project->id ?? throw new \LogicException('A persisted project has an id.');
            $this->em->remove($card);
            $this->em->flush();
            $this->events->dispatch(new CardDeleted($deletedProjectId, $deletedCardId));
            $this->pullRequestTracking->apply($card->project, $trackedBefore, []);

            // After the flush, so the epic counts its children without this
            // one. A listener must not read the card, which is gone.
            if (null !== $parent) {
                $this->events->dispatch(new CardParentChanged($card, $parent, null, $actor));
            }
            if ([] !== $unblocked) {
                $this->events->dispatch(new CardBlockersRemoved($card->project, $unblocked, $actor));
            }

            return $drawsLane;
        });

        // A refusal leaves the closure as a value, for the reason in AddBoardColumnHandler.
        if ($drawsLane instanceof DomainErrors) {
            throw $drawsLane;
        }

        // After the commit, never inside it: the sink drains at kernel.terminate,
        // so a record written in the closure outlives a rollback. The column is
        // read here rather than above, because the re-read decides it.
        $this->auditor->record(
            'board.card_deleted',
            AuditOutcome::Success,
            [
                'cardId' => $cardId,
                'cardNumber' => $cardNumber,
                'projectId' => $projectId,
                'status' => $card->column->slug,
                'columnId' => (string) $card->column->id,
                'actor' => $actor->value,
            ],
            new AuditSubject('card', $cardId),
        );

        $this->events->dispatch(new CardChanged($projectUuid, $cardUuid, CardChanged::DELETED, false));
        if ($drawsLane) {
            $this->events->dispatch(new BoardColumnsChanged($card->project));
        }
    }
}
