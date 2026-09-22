<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\CardReporter;
use App\Forge\ForgeDelivery;
use App\Forge\ForgeEventType;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Outbox\OutboxWriter;
use App\Module\Board\Entity\Forge;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\Auditor;

/**
 * Turns what a forge said into outbox rows an agent can act on.
 *
 * A delivery names a repository path and a number, and CardPullRequest holds
 * both, so a card resolves with no mapping table of its own.
 */
final readonly class RecordForgeDeliveryHandler
{
    public function __construct(
        private CardPullRequestRepository $cardPullRequests,
        private EntityManagerInterface $em,
        private OutboxWriter $outbox,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(RecordForgeDeliveryCommand $command): void
    {
        foreach ($command->deliveries as $delivery) {
            if (ForgeEventType::REPOSITORY_MOVED === $delivery->type) {
                $this->repoint($delivery);

                continue;
            }

            $this->publish($delivery);
        }

        $this->em->flush();
    }

    /**
     * The stored path is the key every later delivery joins on, so a rename
     * nobody applied silently orphans every card of that repository.
     */
    private function repoint(ForgeDelivery $delivery): void
    {
        if (null === $delivery->movedTo) {
            return;
        }

        $forge = Forge::tryFrom($delivery->forge) ?? Forge::Other;
        $moved = $this->cardPullRequests->repoint($forge, $delivery->repository, $delivery->movedTo);
        if ($moved > 0) {
            $this->auditor->record('board.forge_repository_moved', AuditOutcome::Success, [
                'forge' => $forge->value,
                'from' => $delivery->repository,
                'to' => $delivery->movedTo,
                'links' => $moved,
            ]);
        }
    }

    private function publish(ForgeDelivery $delivery): void
    {
        if (null === $delivery->number) {
            return;
        }

        $forge = Forge::tryFrom($delivery->forge) ?? Forge::Other;
        foreach ($this->cardPullRequests->findForPullRequest($forge, $delivery->repository, $delivery->number) as $link) {
            $card = $link->card;
            $project = $card->project;

            // Identifiers only. A review note and a commit message are text a
            // person wrote, and the outbox never carries that to an agent. The
            // actor is `system`, because the fact arrived from outside Loupe
            // and nobody here judged the card.
            $this->outbox->write($project, $delivery->type, [
                'type' => $delivery->type,
                'subject' => ['type' => 'card', 'id' => (string) $card->id],
                'projectId' => (string) $project->id,
                'cardNumber' => $card->number,
                'forge' => $forge->value,
                'actor' => CardReporter::System->value,
            ]);
        }
    }
}
