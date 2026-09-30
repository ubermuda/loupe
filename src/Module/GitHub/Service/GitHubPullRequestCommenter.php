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

    private const int PAGE_SIZE = 100;
    private const int MAX_PAGES = 10;

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
        $sinceUtc = $since->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        // The list runs oldest first and has no sort. `since` keeps it to the
        // comments after the queue, and the page cap bounds a very busy pull request.
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            try {
                $comments = $this->api->get($installationId, $this->commentsPath($path, $pullRequest), [
                    'since' => $sinceUtc,
                    'per_page' => self::PAGE_SIZE,
                    'page' => $page,
                ]);
            } catch (GitHubAppApiFailed $e) {
                throw self::failed($e);
            }

            if (array_any($comments, static fn (mixed $comment): bool => \is_array($comment) && \is_string($comment['body'] ?? null) && str_contains($comment['body'], $marker))) {
                return true;
            }
            if (\count($comments) < self::PAGE_SIZE) {
                return false;
            }
        }

        return false;
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
