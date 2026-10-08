<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Account\Repository\UserRepository;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PullRequestReviewFailed;
use App\Module\Forge\Service\PullRequestReviewKind;
use App\Module\Forge\Service\PullRequestReviewPoster;
use App\Module\GitHub\GitHubDelivery;
use Symfony\Component\Uid\Uuid;

/** Posts a review as the GitHub account that a user connected, never as the App. */
final readonly class GitHubPullRequestReviewPoster implements PullRequestReviewPoster
{
    private const array REFUSED_STATUSES = [401, 403, 404, 422];

    /** GitHub caps a review body at 65536 characters. */
    private const int MAX_BODY_LENGTH = 60000;

    public function __construct(
        private UserRepository $users,
        private GitHubUserTokenRefresher $tokens,
        private GitHubUserApi $api,
    ) {
    }

    #[\Override]
    public function supports(string $forge): bool
    {
        return GitHubDelivery::FORGE === $forge;
    }

    #[\Override]
    public function post(ForgePullRequest $pullRequest, PullRequestReviewKind $kind, string $body, string $userId): ?string
    {
        $user = Uuid::isValid($userId) ? $this->users->find(Uuid::fromString($userId)) : null;
        if (null === $user) {
            throw new PullRequestReviewFailed('not_connected', permanent: true);
        }

        $token = $this->tokens->accessTokenFor($user);
        $accessToken = $token->accessToken;
        if (GitHubUserTokenStatus::Fresh !== $token->status || null === $accessToken) {
            throw match ($token->status) {
                GitHubUserTokenStatus::Expired => new PullRequestReviewFailed('connection_expired', permanent: true),
                GitHubUserTokenStatus::Unavailable => new PullRequestReviewFailed('token_unavailable', permanent: false),
                default => new PullRequestReviewFailed('not_connected', permanent: true),
            };
        }

        $event = match ($kind) {
            PullRequestReviewKind::Approve => 'APPROVE',
            PullRequestReviewKind::RequestChanges => 'REQUEST_CHANGES',
            PullRequestReviewKind::Comment => 'COMMENT',
        };

        try {
            return $this->api->postReview(
                $accessToken,
                GitHubPullRequestInstallations::repositoryPath($pullRequest->repository),
                $pullRequest->number,
                $event,
                mb_substr($body, 0, self::MAX_BODY_LENGTH),
            );
        } catch (GitHubUserApiFailed $e) {
            throw self::failed($e);
        }
    }

    private static function failed(GitHubUserApiFailed $e): PullRequestReviewFailed
    {
        if ('http_status' === $e->reason && \in_array($e->status, self::REFUSED_STATUSES, true)) {
            return new PullRequestReviewFailed('permission', permanent: true, previous: $e);
        }

        $cause = 'http_status' === $e->reason ? 'http_status_'.$e->status : $e->reason;

        return new PullRequestReviewFailed('api_failed_'.$cause, permanent: false, previous: $e);
    }
}
