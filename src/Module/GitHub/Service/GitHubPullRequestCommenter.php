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
        [$installationId, $path] = $this->installation($pullRequest);

        try {
            $this->api->post($installationId, $this->commentsPath($path, $pullRequest), ['body' => $body]);
        } catch (GitHubAppApiFailed $e) {
            throw self::failed($e);
        }
    }

    #[\Override]
    public function hasComment(ForgePullRequest $pullRequest, string $marker, \DateTimeImmutable $since): bool
    {
        [$installationId, $path] = $this->installation($pullRequest);

        try {
            // The list runs oldest first and has no sort. `since` keeps it to the comments after the queue, so one page is enough.
            $comments = $this->api->get($installationId, $this->commentsPath($path, $pullRequest), [
                'since' => $since->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'per_page' => 100,
            ]);
        } catch (GitHubAppApiFailed $e) {
            throw self::failed($e);
        }

        return array_any($comments, static fn (mixed $comment): bool => \is_array($comment) && \is_string($comment['body'] ?? null) && str_contains($comment['body'], $marker));
    }

    /** @return array{int, string} */
    private function installation(ForgePullRequest $pullRequest): array
    {
        try {
            return $this->installations->for($pullRequest);
        } catch (GitHubInstallationUnavailable $e) {
            throw new PullRequestCommentFailed($e->reason, permanent: true, previous: $e);
        }
    }

    /** A pull request is an issue to the REST API, and its conversation comments live there. */
    private function commentsPath(string $path, ForgePullRequest $pullRequest): string
    {
        return GitHubPullRequestInstallations::repositoryPath($path).'/issues/'.$pullRequest->number.'/comments';
    }

    private static function failed(GitHubAppApiFailed $e): PullRequestCommentFailed
    {
        if ($e->rateLimited) {
            return new PullRequestCommentFailed('api_failed_rate_limited', permanent: false, previous: $e, retryAfterSeconds: $e->retryAfterSeconds);
        }
        if ('http_status' === $e->reason && \in_array($e->status, self::REFUSED_STATUSES, true)) {
            return new PullRequestCommentFailed('permission', permanent: true, previous: $e);
        }

        $cause = 'http_status' === $e->reason ? 'http_status_'.$e->status : $e->reason;

        return new PullRequestCommentFailed('api_failed_'.$cause, permanent: \in_array($e->reason, self::CONFIGURATION_REASONS, true), previous: $e);
    }
}
