<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PullRequestBaseChanger;
use App\Module\Forge\Service\PullRequestWriteFailed;
use App\Module\GitHub\GitHubDelivery;

/** Changes the base branch of a pull request as the App installation that delivers its repository to the project. */
final readonly class GitHubPullRequestBaseChanger implements PullRequestBaseChanger
{
    private const string QUERY = 'query($owner:String!,$name:String!,$number:Int!){repository(owner:$owner,name:$name){pullRequest(number:$number){id state baseRefName}}}';
    private const string CHANGE_BASE = 'mutation($id:ID!,$base:String!){updatePullRequest(input:{pullRequestId:$id,baseRefName:$base}){pullRequest{baseRefName}}}';

    private const array REFUSED_STATUSES = [401, 403, 404];

    /** An operator must change the App settings, so a retry cannot succeed. */
    private const array CONFIGURATION_REASONS = ['not_configured', 'bad_key'];

    /** GitHub sends a GraphQL rate limit as an HTTP 200 error body, so the API client reads no delay for it. */
    private const int GRAPHQL_RATE_LIMIT_RETRY_SECONDS = 60;

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
    public function changeBase(ForgePullRequest $pullRequest, string $base): void
    {
        try {
            [$installationId, $path] = $this->installations->for($pullRequest);
        } catch (GitHubInstallationUnavailable $e) {
            throw new PullRequestWriteFailed($e->reason, permanent: true, previous: $e);
        }
        [$owner, $name] = explode('/', $path, 2) + [1 => ''];

        try {
            $data = $this->api->graphql($installationId, self::QUERY, ['owner' => $owner, 'name' => $name, 'number' => $pullRequest->number]);
            $node = $data['repository']['pullRequest'] ?? null;
            if (!\is_array($node) || !\is_string($node['id'] ?? null) || '' === $node['id']) {
                throw new PullRequestWriteFailed('not_found', permanent: true);
            }
            if ('OPEN' !== ($node['state'] ?? null) || $base === ($node['baseRefName'] ?? null)) {
                return;
            }

            $changed = $this->api->graphql($installationId, self::CHANGE_BASE, ['id' => $node['id'], 'base' => $base]);
        } catch (GitHubAppApiFailed $e) {
            throw self::failed($e);
        }
        // The API client lets a NOT_FOUND error through with a null node.
        if (!\is_array($changed['updatePullRequest']['pullRequest'] ?? null)) {
            throw new PullRequestWriteFailed('not_found', permanent: true);
        }
    }

    private static function failed(GitHubAppApiFailed $e): PullRequestWriteFailed
    {
        if ($e->rateLimited) {
            return new PullRequestWriteFailed('api_failed_rate_limited', permanent: false, previous: $e, retryAfterSeconds: $e->retryAfterSeconds);
        }
        if ('graphql_error' === $e->reason && 'RATE_LIMITED' === $e->graphqlType) {
            return new PullRequestWriteFailed('api_failed_rate_limited', permanent: false, previous: $e, retryAfterSeconds: self::GRAPHQL_RATE_LIMIT_RETRY_SECONDS);
        }
        if (('http_status' === $e->reason && \in_array($e->status, self::REFUSED_STATUSES, true))
            || ('graphql_error' === $e->reason && 'FORBIDDEN' === $e->graphqlType)) {
            return new PullRequestWriteFailed('permission', permanent: true, previous: $e);
        }

        $cause = 'http_status' === $e->reason ? 'http_status_'.$e->status : $e->reason;

        return new PullRequestWriteFailed('api_failed_'.$cause, permanent: \in_array($e->reason, self::CONFIGURATION_REASONS, true), previous: $e);
    }
}
