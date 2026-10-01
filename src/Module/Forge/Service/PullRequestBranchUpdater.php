<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Brings the branch of a pull request up to date with its base, on one forge. A forge module implements it. */
#[AutoconfigureTag('app.pull_request_branch_updater')]
interface PullRequestBranchUpdater
{
    /** @param string $forge the forge's slug, such as `github` */
    public function supports(string $forge): bool;

    /**
     * @param string $expectedHeadSha the forge refuses the update when the branch no longer points here
     *
     * @throws PullRequestSyncFailed when the forge does not take the update
     */
    public function update(ForgePullRequest $pullRequest, string $expectedHeadSha): void;
}
