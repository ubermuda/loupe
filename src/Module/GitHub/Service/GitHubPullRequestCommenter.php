<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PullRequestCommenter;
use App\Module\Forge\Service\PullRequestCommentFailed;
use App\Module\GitHub\GitHubDelivery;

/** Comments on a pull request as the App installation that delivers its repository to the project. */
final readonly class GitHubPullRequestCommenter implements PullRequestCommenter
{
    private const array REFUSED_STATUSES = [401, 403, 404];

    /** An operator must change the App settings, so a retry cannot succeed. */
    private const array CONFIGURATION_REASONS = ['not_configured', 'bad_key'];

    public function __construct(
        private GitHubAppApi $api,
        private GitHubPullRequestInstallations $installations,
    ) {
    }

    #[\Override]
    public function supports(string $forge): bool
    {
        return GitHubDelivery::FORGE === $forge;
    }

    #[\Override]
    public function comment(ForgePullRequest $pullRequest, string $body): void
    {
        try {
            [$installationId, $path] = $this->installations->for($pullRequest);
        } catch (GitHubInstallationUnavailable $e) {
            throw new PullRequestCommentFailed($e->reason, permanent: true, previous: $e);
        }

        try {
            // A pull request is an issue to the REST API, and its conversation comments live there.
            $this->api->post($installationId, GitHubPullRequestInstallations::repositoryPath($path).'/issues/'.$pullRequest->number.'/comments', ['body' => $body]);
        } catch (GitHubAppApiFailed $e) {
            if ($e->rateLimited) {
                throw new PullRequestCommentFailed('api_failed_rate_limited', permanent: false, previous: $e, retryAfterSeconds: $e->retryAfterSeconds);
            }
            if ('http_status' === $e->reason && \in_array($e->status, self::REFUSED_STATUSES, true)) {
                throw new PullRequestCommentFailed('permission', permanent: true, previous: $e);
            }

            $cause = 'http_status' === $e->reason ? 'http_status_'.$e->status : $e->reason;
            throw new PullRequestCommentFailed('api_failed_'.$cause, permanent: \in_array($e->reason, self::CONFIGURATION_REASONS, true), previous: $e);
        }
    }
}
