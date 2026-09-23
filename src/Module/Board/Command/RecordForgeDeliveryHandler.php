<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Forge\ForgeDelivery;
use App\Module\Forge\ForgeEventType;
use App\Outbox\OutboxWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

/**
 * Turns what a forge said into outbox rows an agent can act on.
 *
 * A delivery names a repository path and a number, and CardPullRequest holds
 * both, so a card resolves with no mapping table of its own. Only the cards of
 * the project that owns the repository match, because another project can link
 * the same pull request.
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
                $this->repoint($command->projectId, $delivery);

                continue;
            }

            $this->publish($command->projectId, $delivery);
        }

        $this->em->flush();
    }

    /**
     * The stored path is the key every later delivery joins on, so a rename
     * nobody applied silently orphans every card of that repository.
     */
    private function repoint(Uuid $projectId, ForgeDelivery $delivery): void
    {
        if (null === $delivery->movedTo) {
            return;
        }

        $forge = Forge::tryFrom($delivery->forge) ?? Forge::Other;
        $moved = $this->cardPullRequests->repoint($projectId, $forge, $delivery->repository, $delivery->movedTo);
        if ($moved > 0) {
            $this->auditor->record('board.forge_repository_moved', AuditOutcome::Success, [
                'projectId' => (string) $projectId,
                'forge' => $forge->value,
                'from' => $delivery->repository,
                'to' => $delivery->movedTo,
                'links' => $moved,
            ]);
        }
    }

    private function publish(Uuid $projectId, ForgeDelivery $delivery): void
    {
        if (null === $delivery->number) {
            return;
        }

        $forge = Forge::tryFrom($delivery->forge) ?? Forge::Other;
        foreach ($this->cardPullRequests->findForPullRequest($projectId, $forge, $delivery->repository, $delivery->number) as $link) {
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
