<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Posts a comment on a pull request of one forge. A forge module implements it. */
#[AutoconfigureTag('app.pull_request_commenter')]
interface PullRequestCommenter
{
    /** @param string $forge the forge's slug, such as `github` */
    public function supports(string $forge): bool;

    /** @throws PullRequestCommentFailed when the forge does not take the comment */
    public function comment(ForgePullRequest $pullRequest, string $body): void;

    /**
     * Whether a comment created or edited at or after $since holds $marker.
     *
     * @throws PullRequestCommentFailed when the forge does not answer, classified as for comment()
     */
    public function hasComment(ForgePullRequest $pullRequest, string $marker, \DateTimeImmutable $since): bool;
}
