<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PullRequestBranchUpdater;
use App\Module\Forge\Service\PullRequestSyncFailed;
use App\Module\GitHub\GitHubDelivery;

/** Merges the base into a pull request branch as the App installation that delivers its repository to the project. */
final readonly class GitHubPullRequestBranchUpdater implements PullRequestBranchUpdater
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
    public function update(ForgePullRequest $pullRequest, string $expectedHeadSha): void
    {
        try {
            [$installationId, $path] = $this->installations->for($pullRequest);
        } catch (GitHubInstallationUnavailable $e) {
            throw new PullRequestSyncFailed($e->reason, permanent: true, previous: $e);
        }

        try {
            $this->api->put(
                $installationId,
                GitHubPullRequestInstallations::repositoryPath($path).'/pulls/'.$pullRequest->number.'/update-branch',
                ['expected_head_sha' => $expectedHeadSha],
            );
        } catch (GitHubAppApiFailed $e) {
            throw self::failed($e);
        }
    }

    private static function failed(GitHubAppApiFailed $e): PullRequestSyncFailed
    {
        if ($e->rateLimited) {
            return new PullRequestSyncFailed('api_failed_rate_limited', permanent: false, previous: $e, retryAfterSeconds: $e->retryAfterSeconds);
        }
        if ('http_status' === $e->reason && \in_array($e->status, self::REFUSED_STATUSES, true)) {
            return new PullRequestSyncFailed('permission', permanent: true, previous: $e);
        }
        // GitHub answers 422 to a conflict with the base and to a head that moved past the expected sha.
        if ('http_status' === $e->reason && 422 === $e->status) {
            return new PullRequestSyncFailed('refused', permanent: true, previous: $e);
        }

        $cause = 'http_status' === $e->reason ? 'http_status_'.$e->status : $e->reason;

        return new PullRequestSyncFailed('api_failed_'.$cause, permanent: \in_array($e->reason, self::CONFIGURATION_REASONS, true), previous: $e);
    }
}
