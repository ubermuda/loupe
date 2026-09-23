<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The one entry point a forge module uses to write repository ownership. Each
 * write flushes. A claim() that throws closes the EntityManager, because it
 * runs in wrapInTransaction().
 */
final readonly class ForgeRepositories
{
    private const int ACCEPTED_STAMP_INTERVAL_SECONDS = 60;

    public function __construct(
        private ForgeRepositoryRepository $forgeRepositories,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function claim(Project $project, string $forge, string $externalId, string $path): ForgeClaim
    {
        // Two first claims of one repository would otherwise both miss the read
        // and one would trip the unique key.
        return $this->em->wrapInTransaction(function () use ($project, $forge, $externalId, $path): ForgeClaim {
            $this->forgeRepositories->lockForClaim($forge, $externalId);

            $existing = $this->forgeRepositories->findOneByForgeAndExternalId($forge, $externalId);
            if (null === $existing) {
                $repository = new ForgeRepository($project, $forge, $externalId, $path);
                $this->em->persist($repository);

                return ForgeClaim::owned($repository);
            }

            if (!$this->isOwnedBy($existing, $project)) {
                // The owning project stays out of the log, so the refusal tells nobody who owns it.
                $this->logger->info('forge.repository_claim_refused', [
                    'forge' => $forge,
                    'externalId' => $externalId,
                    'projectId' => (string) $project->id,
                ]);

                return ForgeClaim::refused();
            }

            $movedFrom = null;
            if (0 !== strcasecmp($existing->path, $path)) {
                $movedFrom = $existing->path;
                $existing->path = $path;
                $this->logger->info('forge.repository_moved', [
                    'forge' => $forge,
                    'externalId' => $externalId,
                    'projectId' => (string) $project->id,
                    'from' => $movedFrom,
                    'to' => $path,
                ]);
            }

            return ForgeClaim::alreadyOwned($existing, $movedFrom);
        });
    }

    public function release(Project $project, string $forge, string $externalId): void
    {
        $existing = $this->forgeRepositories->findOneByForgeAndExternalId($forge, $externalId);
        if (null === $existing || !$this->isOwnedBy($existing, $project)) {
            return;
        }

        $this->em->remove($existing);
        $this->em->flush();
    }

    /** Stamps at most once a minute, because every accepted delivery calls it. */
    public function accepted(ForgeRepository $repository, \DateTimeImmutable $now): void
    {
        $last = $repository->lastAcceptedAt;
        if (null !== $last && $now->getTimestamp() - $last->getTimestamp() < self::ACCEPTED_STAMP_INTERVAL_SECONDS) {
            return;
        }

        $repository->lastAcceptedAt = $now;
        $this->em->flush();
    }

    public function ownerOf(string $forge, string $externalId): ?ForgeRepository
    {
        return $this->forgeRepositories->findOneByForgeAndExternalId($forge, $externalId);
    }

    private function isOwnedBy(ForgeRepository $repository, Project $project): bool
    {
        return null !== $project->id && true === $repository->project->id?->equals($project->id);
    }
}
