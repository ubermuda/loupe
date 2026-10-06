<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Opens a draft pull request in the repository of another pull request. A forge module implements it.
 * An open pull request from the same head to the same base counts as opened, so a retry opens no second one.
 */
#[AutoconfigureTag('app.pull_request_opener')]
interface PullRequestOpener
{
    /** @param string $forge the forge's slug, such as `github` */
    public function supports(string $forge): bool;

    /**
     * @param ForgePullRequest $from a pull request of the repository, which names the repository and the access to it
     *
     * @return int the number of the pull request
     *
     * @throws PullRequestWriteFailed when the forge does not take the change
     */
    public function open(ForgePullRequest $from, string $head, string $base, string $title, string $body): int;

    /** The address of a pull request in the repository of $from, in the form a card link takes. */
    public function url(ForgePullRequest $from, int $number): string;
}
