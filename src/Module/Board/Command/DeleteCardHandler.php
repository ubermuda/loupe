<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Event\CardParentChanged;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\CardGroupOrder;
use App\Module\Board\Service\CardParentPolicy;
use App\Module\Bridge\Service\InteractiveRuns;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Deletes a card and its pull request links, which orphanRemoval takes with it. */
final readonly class DeleteCardHandler
{
    public function __construct(
        private CardRepository $cards,
        private CardGroupOrder $groupOrder,
        private CardParentPolicy $parentPolicy,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private EventDispatcherInterface $events,
        private InteractiveRuns $interactiveRuns,
    ) {
    }

    public function __invoke(DeleteCardCommand $command): void
    {
        $card = $command->card;

        // Read before the remove: the flush clears the id. The number and the
        // project are readonly, so no re-read can change them.
        $cardUuid = $card->id ?? throw new \LogicException('Card has no id.');
        $projectUuid = $card->project->id ?? throw new \LogicException('Project has no id.');
        $cardId = (string) $cardUuid;
        $cardNumber = $card->number;
        $projectId = (string) $projectUuid;

        $refusal = $this->em->wrapInTransaction(function () use ($card): ?DomainErrors {
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

            // Before the remove, so the delete and the renumbering it causes
            // reach the database in one flush.
            $this->groupOrder->compact($card->column, $card);

            // No tool and no page can reach the runs of a deleted card to close them.
            if (null !== $card->id) {
                $this->interactiveRuns->closeOnMove($card->project, [$card->id]);
            }

            $this->em->remove($card);
            $this->em->flush();

            // After the flush, so the epic counts its children without this
            // one. A listener must not read the card, which is gone. Only a
            // person deletes a card.
            if (null !== $parent) {
                $this->events->dispatch(new CardParentChanged($card, $parent, null, CardReporter::Human));
            }

            return null;
        });

        // A refusal leaves the closure as a value, for the reason in AddBoardColumnHandler.
        if (null !== $refusal) {
            throw $refusal;
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
            ],
            new AuditSubject('card', $cardId),
        );

        $this->events->dispatch(new CardChanged($projectUuid, $cardUuid, CardChanged::DELETED, false));
    }
}
