<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Asks the forge to merge a pull request or to change its base, and records
 * the expected effect on the row first, so a later read can tell the effect
 * apart. The forge call runs outside any transaction, so a slow forge holds no
 * lock. A failed call clears the marker it set.
 */
final readonly class ForgePullRequestWrites
{
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

        // The closure answers false for a row that is gone. A throw inside it would close the entity manager.
        $sha = $this->em->wrapInTransaction(function () use ($id): string|false|null {
            $row = $this->forgePullRequests->findForUpdate($id);
            if (null === $row) {
                return false;
            }
            if (null === $row->headSha) {
                return null;
            }
            $row->mergeRequestedSha = $row->headSha;
            $row->mergeRequestedAt = $this->clock->now();

            return $row->headSha;
        });
        if (false === $sha) {
            throw new PullRequestWriteFailed('not_found', permanent: true);
        }
        if (null === $sha) {
            throw new PullRequestWriteFailed('no_head', permanent: true);
        }

        try {
            $merger->merge($pullRequest, $method, $sha);
        } catch (PullRequestWriteFailed $e) {
            $this->em->wrapInTransaction(function () use ($id, $sha): void {
                $row = $this->forgePullRequests->findForUpdate($id);
                if (null !== $row && $row->mergeRequestedSha === $sha) {
                    $row->mergeRequestedSha = null;
                    $row->mergeRequestedAt = null;
                }
            });

            throw $e;
        }
    }

    /** @throws PullRequestWriteFailed */
    public function changeBase(ForgePullRequest $pullRequest, string $base): void
    {
        $changer = $this->baseChangers->for($pullRequest->forge) ?? throw new PullRequestWriteFailed('no_writer', permanent: true);
        $id = $pullRequest->id ?? throw new \LogicException('A stored pull request has an id.');

        $marked = $this->em->wrapInTransaction(function () use ($id, $base): bool {
            $row = $this->forgePullRequests->findForUpdate($id);
            if (null === $row) {
                return false;
            }
            $row->baseChangeRequestedTo = $base;
            $row->baseChangeRequestedAt = $this->clock->now();

            return true;
        });
        if (!$marked) {
            throw new PullRequestWriteFailed('not_found', permanent: true);
        }

        try {
            $changer->changeBase($pullRequest, $base);
        } catch (PullRequestWriteFailed $e) {
            $this->em->wrapInTransaction(function () use ($id, $base): void {
                $row = $this->forgePullRequests->findForUpdate($id);
                if (null !== $row && $row->baseChangeRequestedTo === $base) {
                    $row->baseChangeRequestedTo = null;
                    $row->baseChangeRequestedAt = null;
                }
            });

            throw $e;
        }
    }
}
