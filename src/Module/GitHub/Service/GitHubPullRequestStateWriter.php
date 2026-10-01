<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PullRequestStateWriter;
use App\Module\Forge\Service\PullRequestWriteFailed;
use App\Module\GitHub\GitHubDelivery;

/** Changes the state of a pull request as the App installation that delivers its repository to the project. */
final readonly class GitHubPullRequestStateWriter implements PullRequestStateWriter
{
    private const string QUERY = 'query($owner:String!,$name:String!,$number:Int!){repository(owner:$owner,name:$name){pullRequest(number:$number){id isDraft state}}}';
    private const string READY = 'mutation($id:ID!){markPullRequestReadyForReview(input:{pullRequestId:$id}){pullRequest{isDraft}}}';
    private const string DRAFT = 'mutation($id:ID!){convertPullRequestToDraft(input:{pullRequestId:$id}){pullRequest{isDraft}}}';
    private const string CLOSE = 'mutation($id:ID!){closePullRequest(input:{pullRequestId:$id}){pullRequest{state}}}';

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
    public function setDraft(ForgePullRequest $pullRequest, bool $draft): void
    {
        [$installationId, $node] = $this->openNode($pullRequest);
        if (null === $node || $draft === $node['isDraft']) {
            return;
        }

        $this->send($installationId, $draft ? self::DRAFT : self::READY, $node['id']);
    }

    #[\Override]
    public function close(ForgePullRequest $pullRequest): void
    {
        [$installationId, $node] = $this->openNode($pullRequest);
        if (null !== $node) {
            $this->send($installationId, self::CLOSE, $node['id']);
        }
    }

    /**
     * @return array{int, ?array{id: string, isDraft: bool}} the installation id, and the node when the pull request is open
     *
     * @throws PullRequestWriteFailed
     */
    private function openNode(ForgePullRequest $pullRequest): array
    {
        try {
            [$installationId, $path] = $this->installations->for($pullRequest);
        } catch (GitHubInstallationUnavailable $e) {
            throw new PullRequestWriteFailed($e->reason, permanent: true, previous: $e);
        }
        [$owner, $name] = explode('/', $path, 2) + [1 => ''];

        try {
            $data = $this->api->graphql($installationId, self::QUERY, ['owner' => $owner, 'name' => $name, 'number' => $pullRequest->number]);
        } catch (GitHubAppApiFailed $e) {
            throw self::failed($e);
        }

        $node = $data['repository']['pullRequest'] ?? null;
        if (!\is_array($node) || !\is_string($node['id'] ?? null) || '' === $node['id']) {
            throw new PullRequestWriteFailed('not_found', permanent: true);
        }
        if ('OPEN' !== ($node['state'] ?? null)) {
            return [$installationId, null];
        }

        return [$installationId, ['id' => $node['id'], 'isDraft' => true === ($node['isDraft'] ?? null)]];
    }

    /** @throws PullRequestWriteFailed */
    private function send(int $installationId, string $mutation, string $id): void
    {
        try {
            $this->api->graphql($installationId, $mutation, ['id' => $id]);
        } catch (GitHubAppApiFailed $e) {
            throw self::failed($e);
        }
    }

    private static function failed(GitHubAppApiFailed $e): PullRequestWriteFailed
    {
        if ($e->rateLimited) {
            return new PullRequestWriteFailed('api_failed_rate_limited', permanent: false, previous: $e, retryAfterSeconds: $e->retryAfterSeconds);
        }
        if (('http_status' === $e->reason && \in_array($e->status, self::REFUSED_STATUSES, true))
            || ('graphql_error' === $e->reason && 'FORBIDDEN' === $e->graphqlType)) {
            return new PullRequestWriteFailed('permission', permanent: true, previous: $e);
        }

        $cause = 'http_status' === $e->reason ? 'http_status_'.$e->status : $e->reason;

        return new PullRequestWriteFailed('api_failed_'.$cause, permanent: \in_array($e->reason, self::CONFIGURATION_REASONS, true), previous: $e);
    }
}
