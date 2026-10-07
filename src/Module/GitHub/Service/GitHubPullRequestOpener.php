<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PullRequestOpener;
use App\Module\Forge\Service\PullRequestWriteFailed;
use App\Module\GitHub\GitHubDelivery;

/** Opens a pull request as the App installation that delivers the repository to the project. */
final readonly class GitHubPullRequestOpener implements PullRequestOpener
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
    public function open(ForgePullRequest $from, string $head, string $base, string $title, string $body): int
    {
        try {
            [$installationId, $path] = $this->installations->for($from);
        } catch (GitHubInstallationUnavailable $e) {
            throw new PullRequestWriteFailed($e->reason, permanent: true, previous: $e);
        }
        $pulls = GitHubPullRequestInstallations::repositoryPath($path).'/pulls';
        $owner = explode('/', $path, 2)[0];

        try {
            $open = $this->api->get($installationId, $pulls, ['head' => $owner.':'.$head, 'base' => $base, 'state' => 'open']);
            if ([] !== $open) {
                return self::number($open[0] ?? null);
            }

            return self::number($this->api->post($installationId, $pulls, ['title' => $title, 'head' => $head, 'base' => $base, 'body' => $body, 'draft' => true]));
        } catch (GitHubAppApiFailed $e) {
            throw self::failed($e);
        }
    }

    #[\Override]
    public function url(ForgePullRequest $from, int $number): string
    {
        return 'https://github.com/'.$from->repository.'/pull/'.$number;
    }

    /** @throws PullRequestWriteFailed */
    private static function number(mixed $pullRequest): int
    {
        $number = \is_array($pullRequest) ? ($pullRequest['number'] ?? null) : null;

        return \is_int($number) ? $number : throw new PullRequestWriteFailed('malformed_body', permanent: true);
    }

    private static function failed(GitHubAppApiFailed $e): PullRequestWriteFailed
    {
        if ($e->rateLimited) {
            return new PullRequestWriteFailed('api_failed_rate_limited', permanent: false, previous: $e, retryAfterSeconds: $e->retryAfterSeconds);
        }
        if ('http_status' === $e->reason && \in_array($e->status, self::REFUSED_STATUSES, true)) {
            return new PullRequestWriteFailed('permission', permanent: true, previous: $e);
        }

        $cause = 'http_status' === $e->reason ? 'http_status_'.$e->status : $e->reason;

        return new PullRequestWriteFailed('api_failed_'.$cause, permanent: \in_array($e->reason, self::CONFIGURATION_REASONS, true), previous: $e);
    }
}
