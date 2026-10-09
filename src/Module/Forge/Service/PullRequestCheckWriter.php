<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Reports a check on a commit of a pull request, as Loupe itself. A forge module implements it. */
#[AutoconfigureTag('app.pull_request_check_writer')]
interface PullRequestCheckWriter
{
    /** @param string $forge the forge's slug, such as `github` */
    public function supports(string $forge): bool;

    /**
     * Reports a finished check on $sha. A $runId updates the run the forge gave a past call, and null makes a new run.
     *
     * @param list<PullRequestCheckAnnotation> $annotations the line notes to add to the run; the forge adds them to the notes the run already has
     *
     * @return int the forge's id of the run, to pass as $runId on the next call for the same commit
     *
     * @throws PullRequestCheckFailed when the forge does not take the check
     */
    public function publish(ForgePullRequest $pullRequest, string $name, string $sha, PullRequestCheckConclusion $conclusion, string $title, string $summary, ?int $runId, array $annotations): int;
}
