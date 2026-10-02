<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PullRequestMerger;
use App\Module\Forge\Service\PullRequestWriteFailed;
use App\Module\GitHub\GitHubDelivery;

/** Merges a pull request as the App installation that delivers its repository to the project. */
final readonly class GitHubPullRequestMerger implements PullRequestMerger
{
    private const array REFUSED_STATUSES = [401, 403, 404];

    /** GitHub answers 405 to a pull request that is not mergeable, and 422 to a request it cannot process. */
    private const array NOT_MERGEABLE_STATUSES = [405, 422];

    /** GitHub answers 409 when the head no longer matches the expected sha. */
    private const int HEAD_MOVED_STATUS = 409;

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
    public function merge(ForgePullRequest $pullRequest, string $method, string $expectedHeadSha): void
    {
        if (!\in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException('Unknown merge method: '.$method);
        }

        try {
            [$installationId, $path] = $this->installations->for($pullRequest);
        } catch (GitHubInstallationUnavailable $e) {
            throw new PullRequestWriteFailed($e->reason, permanent: true, previous: $e);
        }

        try {
            $this->api->put(
                $installationId,
                GitHubPullRequestInstallations::repositoryPath($path).'/pulls/'.$pullRequest->number.'/merge',
                ['merge_method' => $method, 'sha' => $expectedHeadSha],
            );
        } catch (GitHubAppApiFailed $e) {
            throw self::failed($e);
        }
    }

    private static function failed(GitHubAppApiFailed $e): PullRequestWriteFailed
    {
        if ($e->rateLimited) {
            return new PullRequestWriteFailed('api_failed_rate_limited', permanent: false, previous: $e, retryAfterSeconds: $e->retryAfterSeconds);
        }
        if ('http_status' === $e->reason && \in_array($e->status, self::REFUSED_STATUSES, true)) {
            return new PullRequestWriteFailed('permission', permanent: true, previous: $e);
        }
        if ('http_status' === $e->reason && \in_array($e->status, self::NOT_MERGEABLE_STATUSES, true)) {
            return new PullRequestWriteFailed('refused', permanent: true, previous: $e);
        }
        if ('http_status' === $e->reason && self::HEAD_MOVED_STATUS === $e->status) {
            return new PullRequestWriteFailed('head_moved', permanent: true, previous: $e);
        }

        $cause = 'http_status' === $e->reason ? 'http_status_'.$e->status : $e->reason;

        return new PullRequestWriteFailed('api_failed_'.$cause, permanent: \in_array($e->reason, self::CONFIGURATION_REASONS, true), previous: $e);
    }
}
