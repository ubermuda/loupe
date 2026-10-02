<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Changes the base branch of a pull request on one forge. A forge module implements it. */
#[AutoconfigureTag('app.pull_request_base_changer')]
interface PullRequestBaseChanger
{
    /** @param string $forge the forge's slug, such as `github` */
    public function supports(string $forge): bool;

    /**
     * Does nothing when the pull request is not open, or already targets the base.
     *
     * @throws PullRequestWriteFailed when the forge does not take the change
     */
    public function changeBase(ForgePullRequest $pullRequest, string $base): void;
}
