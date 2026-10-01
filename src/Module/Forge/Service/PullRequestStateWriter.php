<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Changes the state of a pull request of one forge. A forge module implements it.
 * A write does nothing when the pull request is not open, or is already in the target state.
 */
#[AutoconfigureTag('app.pull_request_state_writer')]
interface PullRequestStateWriter
{
    /** @param string $forge the forge's slug, such as `github` */
    public function supports(string $forge): bool;

    /** @throws PullRequestWriteFailed when the forge does not take the change */
    public function setDraft(ForgePullRequest $pullRequest, bool $draft): void;

    /** @throws PullRequestWriteFailed when the forge does not take the change */
    public function close(ForgePullRequest $pullRequest): void;
}
