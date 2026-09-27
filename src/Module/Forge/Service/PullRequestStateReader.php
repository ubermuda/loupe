<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\PullRequestSnapshot;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Reads the current state of a pull request from one forge. A forge module implements it. */
#[AutoconfigureTag('app.pull_request_state_reader')]
interface PullRequestStateReader
{
    /** @param string $forge the forge's slug, such as `github` */
    public function supports(string $forge): bool;

    /** @throws PullRequestUnreadable when the forge cannot answer for this pull request now */
    public function read(ForgePullRequest $pullRequest): PullRequestSnapshot;
}
