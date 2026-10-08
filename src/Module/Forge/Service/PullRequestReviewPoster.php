<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Posts a review on a pull request of one forge, as a Loupe user. A forge module implements it. */
#[AutoconfigureTag('app.pull_request_review_poster')]
interface PullRequestReviewPoster
{
    /** @param string $forge the forge's slug, such as `github` */
    public function supports(string $forge): bool;

    /**
     * @param string $body   may be empty for an approval only. The forge refuses the other kinds without one, with the permanent cause `empty_body`.
     * @param string $userId the id of the Loupe user whose forge account posts the review
     *
     * @return ?string the forge's URL of the review, when it gives one
     *
     * @throws PullRequestReviewFailed when the forge does not take the review. The causes `not_connected` and `connection_expired` mean the user holds no working forge connection.
     */
    public function post(ForgePullRequest $pullRequest, PullRequestReviewKind $kind, string $body, string $userId): ?string;
}
