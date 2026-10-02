<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Asks the forge to merge a pull request or to change its base. A marker on
 * the row first says that a write by Loupe is in flight, and blocks a second write of its kind.
 * A read that shows any such change settles it. The forge call holds no lock. A permanent
 * failure clears the marker, and a transient one keeps it, because the write may have landed.
 */
final readonly class ForgePullRequestWrites
{
    /** A transient failure keeps a marker that a read may never clear, so an older marker is stale. */
    public const int MARKER_LIFETIME_SECONDS = 600;

    public function __construct(
        private PullRequestMergers $mergers,
        private PullRequestBaseChangers $baseChangers,
        private ForgePullRequestRepository $forgePullRequests,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param string $method one of PullRequestMerger::METHODS
     *
     * @throws \InvalidArgumentException when the method is not one of PullRequestMerger::METHODS
     * @throws PullRequestWriteFailed
     */
    public function merge(ForgePullRequest $pullRequest, string $method): void
    {
        if (!\in_array($method, PullRequestMerger::METHODS, true)) {
            throw new \InvalidArgumentException('Unknown merge method: '.$method);
        }
        $merger = $this->mergers->for($pullRequest->forge) ?? throw new PullRequestWriteFailed('no_writer', permanent: true);
        $id = $pullRequest->id ?? throw new \LogicException('A stored pull request has an id.');

        // The closure answers its failure, because a throw inside it would close the entity manager.
        $sha = $this->em->wrapInTransaction(function () use ($id): string|PullRequestWriteFailed {
            $row = $this->forgePullRequests->findForUpdate($id);
            if (null === $row) {
                return new PullRequestWriteFailed('not_found', permanent: true);
            }
            $now = $this->clock->now();
            if (null !== $row->mergeRequestedSha && self::fresh($row->mergeRequestedAt, $now)) {
                return new PullRequestWriteFailed('in_flight', permanent: false);
            }
            if (null === $row->headSha) {
                return new PullRequestWriteFailed('no_head', permanent: true);
            }
            $row->mergeRequestedSha = $row->headSha;
            $row->mergeRequestedAt = $now;

            return $row->headSha;
        });
        if ($sha instanceof PullRequestWriteFailed) {
            throw $sha;
        }

        try {
            $merger->merge($pullRequest, $method, $sha);
        } catch (PullRequestWriteFailed $e) {
            if ($e->permanent) {
                $this->em->wrapInTransaction(function () use ($id, $sha): void {
                    $row = $this->forgePullRequests->findForUpdate($id);
                    if (null !== $row && $row->mergeRequestedSha === $sha) {
                        $row->mergeRequestedSha = null;
                        $row->mergeRequestedAt = null;
                    }
                });
            }

            throw $e;
        }
    }

    /** @throws PullRequestWriteFailed */
    public function changeBase(ForgePullRequest $pullRequest, string $base): void
    {
        $changer = $this->baseChangers->for($pullRequest->forge) ?? throw new PullRequestWriteFailed('no_writer', permanent: true);
        $id = $pullRequest->id ?? throw new \LogicException('A stored pull request has an id.');

        $refused = $this->em->wrapInTransaction(function () use ($id, $base): ?PullRequestWriteFailed {
            $row = $this->forgePullRequests->findForUpdate($id);
            if (null === $row) {
                return new PullRequestWriteFailed('not_found', permanent: true);
            }
            $now = $this->clock->now();
            if (null !== $row->baseChangeRequestedTo && self::fresh($row->baseChangeRequestedAt, $now)) {
                return new PullRequestWriteFailed('in_flight', permanent: false);
            }
            $row->baseChangeRequestedTo = $base;
            $row->baseChangeRequestedAt = $now;

            return null;
        });
        if (null !== $refused) {
            throw $refused;
        }

        try {
            $changer->changeBase($pullRequest, $base);
        } catch (PullRequestWriteFailed $e) {
            if ($e->permanent) {
                $this->em->wrapInTransaction(function () use ($id, $base): void {
                    $row = $this->forgePullRequests->findForUpdate($id);
                    if (null !== $row && $row->baseChangeRequestedTo === $base) {
                        $row->baseChangeRequestedTo = null;
                        $row->baseChangeRequestedAt = null;
                    }
                });
            }

            throw $e;
        }
    }

    private static function fresh(?\DateTimeImmutable $requestedAt, \DateTimeImmutable $now): bool
    {
        return null !== $requestedAt && $requestedAt > $now->modify(\sprintf('-%d seconds', self::MARKER_LIFETIME_SECONDS));
    }
}
