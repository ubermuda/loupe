<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
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

    /**
     * A hook claim never refuses, because a hook signature proves nothing about
     * who owns the repository. It keeps this project's row alone. An
     * installation claim refuses when another project holds an installation
     * row, and otherwise turns this project's row into an installation row.
     */
    public function claim(Project $project, string $forge, string $externalId, string $path, ForgeRepositorySource $source, ?string $sourceRef = null): ForgeClaim
    {
        // Two first claims of one repository would otherwise both miss the read
        // and one would trip the unique key.
        return $this->em->wrapInTransaction(function () use ($project, $forge, $externalId, $path, $source, $sourceRef): ForgeClaim {
            $this->forgeRepositories->lockForClaim($forge, $externalId);

            if (ForgeRepositorySource::Installation === $source) {
                $installed = $this->forgeRepositories->findInstallationRow($forge, $externalId);
                if (null !== $installed && !$this->isOwnedBy($installed, $project)) {
                    // The owning project stays out of the log, so the refusal tells nobody who owns it.
                    $this->logger->info('forge.repository_claim_refused', [
                        'forge' => $forge,
                        'externalId' => $externalId,
                        'projectId' => (string) $project->id,
                    ]);

                    return ForgeClaim::refused();
                }
            }

            $existing = $this->forgeRepositories->findOneForProject($project, $forge, $externalId);
            if (null === $existing) {
                $repository = new ForgeRepository($project, $forge, $externalId, $path, $source, ForgeRepositorySource::Installation === $source ? $sourceRef : null);
                $this->em->persist($repository);

                return ForgeClaim::owned($repository);
            }

            if (ForgeRepositorySource::Installation === $source) {
                $existing->source = ForgeRepositorySource::Installation;
                $existing->sourceRef = $sourceRef;
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

    /** Removes this project's row, whatever its source. */
    public function release(Project $project, string $forge, string $externalId): void
    {
        $existing = $this->forgeRepositories->findOneForProject($project, $forge, $externalId);
        if (null === $existing) {
            return;
        }

        $this->em->remove($existing);
        $this->em->flush();
    }

    /**
     * Removes the rows one installation holds, or its row for one repository.
     * A later hook delivery creates a hook row again.
     */
    public function releaseInstallation(string $forge, string $sourceRef, ?string $externalId = null): void
    {
        foreach ($this->forgeRepositories->findByInstallation($forge, $sourceRef) as $repository) {
            if (null === $externalId || $repository->externalId === $externalId) {
                $this->em->remove($repository);
            }
        }

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

    /** The one row that makes a repository exclusive, when an installation holds it. */
    public function installationOwnerOf(string $forge, string $externalId): ?ForgeRepository
    {
        return $this->forgeRepositories->findInstallationRow($forge, $externalId);
    }

    private function isOwnedBy(ForgeRepository $repository, Project $project): bool
    {
        return null !== $project->id && true === $repository->project->id?->equals($project->id);
    }
}
