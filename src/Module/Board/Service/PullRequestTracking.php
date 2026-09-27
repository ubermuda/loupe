<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Forge\Service\PullRequestTracker;
use App\Module\Project\Entity\Project;

/**
 * Keeps the tracked pull requests of a project in step with its card links.
 * Call it under the project lock, so no other card write runs between the
 * two reads.
 */
final readonly class PullRequestTracking
{
    public function __construct(
        private CardPullRequestRepository $cardPullRequests,
        private PullRequestTracker $tracker,
    ) {
    }

    /**
     * The GitHub pull requests a card links, keyed so two spellings of one
     * repository are one entry.
     *
     * @return array<string, array{string, int}>
     */
    public function referencesOf(Card $card): array
    {
        $references = [];
        foreach ($this->cardPullRequests->findGitHubReferences($card) as $row) {
            $repository = mb_strtolower($row['repository']);
            $references[$repository.'#'.$row['number']] = [$repository, $row['number']];
        }

        return $references;
    }

    /**
     * Call it after the flush, so a pull request that another card still links
     * reads as linked, and one this card dropped does not.
     *
     * @param array<string, array{string, int}> $before
     * @param array<string, array{string, int}> $after
     */
    public function apply(Project $project, array $before, array $after): void
    {
        foreach (array_diff_key($after, $before) as [$repository, $number]) {
            $this->tracker->track($project, Forge::GitHub->value, $repository, $number);
        }

        $projectId = $project->id ?? throw new \LogicException('A card project is persisted.');
        foreach (array_diff_key($before, $after) as [$repository, $number]) {
            if ([] === $this->cardPullRequests->findForPullRequest($projectId, Forge::GitHub, $repository, $number)) {
                $this->tracker->untrack($project, Forge::GitHub->value, $repository, $number);
            }
        }
    }
}
