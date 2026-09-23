<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;

/** The one entry point a forge module uses to write repository ownership. */
final readonly class ForgeRepositories
{
    private const int ACCEPTED_STAMP_INTERVAL_SECONDS = 60;

    public function __construct(
        private ForgeRepositoryRepository $forgeRepositories,
        private EntityManagerInterface $em,
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
                return ForgeClaim::refused();
            }

            $movedFrom = null;
            if (0 !== strcasecmp($existing->path, $path)) {
                $movedFrom = $existing->path;
                $existing->path = $path;
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
