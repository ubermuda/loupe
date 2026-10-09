<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PullRequestCheckConclusion;
use App\Module\Forge\Service\PullRequestCheckFailed;
use App\Module\Forge\Service\PullRequestCheckWriter;
use App\Module\GitHub\GitHubDelivery;

/** Reports a check run as the App installation that delivers the repository to the project. */
final readonly class GitHubPullRequestCheckWriter implements PullRequestCheckWriter
{
    private const array REFUSED_STATUSES = [401, 403, 404];

    /** An operator must change the App settings, so a retry cannot succeed. */
    private const array CONFIGURATION_REASONS = ['not_configured', 'bad_key'];

    /** GitHub caps the summary of a check run at 65535 characters. */
    private const int MAX_SUMMARY_LENGTH = 60000;

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
    public function publish(ForgePullRequest $pullRequest, string $name, string $sha, PullRequestCheckConclusion $conclusion, string $title, string $summary, ?int $runId): int
    {
        try {
            [$installationId, $path] = $this->installations->for($pullRequest);
        } catch (GitHubInstallationUnavailable $e) {
            throw new PullRequestCheckFailed($e->reason, permanent: true, previous: $e);
        }

        $runs = GitHubPullRequestInstallations::repositoryPath($path).'/check-runs';
        $output = ['title' => $title, 'summary' => mb_substr($summary, 0, self::MAX_SUMMARY_LENGTH)];

        try {
            if (null !== $runId) {
                $answer = $this->api->patch($installationId, $runs.'/'.$runId, [
                    'status' => 'completed',
                    'conclusion' => $conclusion->value,
                    'output' => $output,
                ]);
            } else {
                $answer = $this->api->post($installationId, $runs, [
                    'name' => $name,
                    'head_sha' => $sha,
                    'status' => 'completed',
                    'conclusion' => $conclusion->value,
                    'output' => $output,
                ]);
            }
        } catch (GitHubAppApiFailed $e) {
            throw self::failed($e);
        }

        $id = $answer['id'] ?? null;
        if (!\is_int($id)) {
            throw new PullRequestCheckFailed('api_failed_malformed_body', permanent: false);
        }

        return $id;
    }

    private static function failed(GitHubAppApiFailed $e): PullRequestCheckFailed
    {
        if ($e->rateLimited) {
            return new PullRequestCheckFailed('api_failed_rate_limited', permanent: false, previous: $e, retryAfterSeconds: $e->retryAfterSeconds);
        }
        if ('http_status' === $e->reason && \in_array($e->status, self::REFUSED_STATUSES, true)) {
            return new PullRequestCheckFailed('permission', permanent: true, previous: $e);
        }

        $cause = 'http_status' === $e->reason ? 'http_status_'.$e->status : $e->reason;

        return new PullRequestCheckFailed('api_failed_'.$cause, permanent: \in_array($e->reason, self::CONFIGURATION_REASONS, true), previous: $e);
    }
}
