<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Merges a pull request on one forge. A forge module implements it. */
#[AutoconfigureTag('app.pull_request_merger')]
interface PullRequestMerger
{
    public const array METHODS = ['merge', 'squash', 'rebase'];

    /** @param string $forge the forge's slug, such as `github` */
    public function supports(string $forge): bool;

    /**
     * @param string $method          one of METHODS
     * @param string $expectedHeadSha the forge refuses the merge when the branch no longer points here
     *
     * @throws \InvalidArgumentException when the method is not one of METHODS
     * @throws PullRequestWriteFailed    when the forge does not take the merge
     */
    public function merge(ForgePullRequest $pullRequest, string $method, string $expectedHeadSha): void;
}
