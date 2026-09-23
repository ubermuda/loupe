<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\GitHub\GitHubDelivery;
use App\Module\GitHub\InvalidGitHubDelivery;
use App\Module\GitHub\Service\DeliveryAnnouncer;
use App\Module\GitHub\Service\RefusedDeliveries;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A repository hook feeds its own project alone. The project owner knows the
 * secret, so a hook delivery never refuses and never blocks another project.
 * The hook records whether its last delivery verified, which is what tells a
 * person that a secret was pasted wrong.
 */
final readonly class ReceiveHookDeliveryHandler
{
    public function __construct(
        private DeliveryAnnouncer $announcer,
        private RefusedDeliveries $refusals,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ReceiveHookDeliveryCommand $command): GitHubDeliveryOutcome
    {
        $hook = $command->hook;

        try {
            $delivery = GitHubDelivery::fromRequest($command->request, $hook->secret);
        } catch (InvalidGitHubDelivery $e) {
            $hook->refused(new \DateTimeImmutable(), $e->reason);
            $this->em->flush();
            $this->refusals->record($e, ['hookId' => (string) $hook->id]);

            return GitHubDeliveryOutcome::Refused;
        }

        $hook->accepted(new \DateTimeImmutable());
        $this->em->flush();

        $repository = $delivery->repository();
        if ('ping' !== $delivery->event && null !== $repository) {
            $this->announcer->announce($hook->project, $repository, $delivery, ForgeRepositorySource::Hook);
        }

        return GitHubDeliveryOutcome::Received;
    }
}
