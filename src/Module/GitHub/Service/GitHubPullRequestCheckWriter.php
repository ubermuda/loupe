<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PullRequestCheckAnnotation;
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

    /** GitHub takes at most 50 annotations in one request. */
    private const int ANNOTATIONS_PER_REQUEST = 50;

    private const int MAX_ANNOTATION_TITLE_LENGTH = 255;

    /** GitHub caps the message of an annotation at 64 KB, so the cut counts bytes. */
    private const int MAX_ANNOTATION_MESSAGE_BYTES = 60000;

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
    public function publish(ForgePullRequest $pullRequest, string $name, string $sha, PullRequestCheckConclusion $conclusion, string $title, string $summary, ?int $runId, array $annotations): int
    {
        try {
            [$installationId, $path] = $this->installations->for($pullRequest);
        } catch (GitHubInstallationUnavailable $e) {
            throw new PullRequestCheckFailed($e->reason, permanent: true, previous: $e);
        }

        $runs = GitHubPullRequestInstallations::repositoryPath($path).'/check-runs';
        $output = ['title' => $title, 'summary' => mb_substr($summary, 0, self::MAX_SUMMARY_LENGTH)];
        $pages = array_chunk(array_map(self::annotation(...), $annotations), self::ANNOTATIONS_PER_REQUEST);
        $firstPage = array_shift($pages) ?? [];
        $first = [] === $firstPage ? $output : [...$output, 'annotations' => $firstPage];
        $id = $runId;
        $sent = 0;

        try {
            if (null !== $runId) {
                $answer = $this->api->patch($installationId, $runs.'/'.$runId, [
                    'status' => 'completed',
                    'conclusion' => $conclusion->value,
                    'output' => $first,
                ]);
            } else {
                $answer = $this->api->post($installationId, $runs, [
                    'name' => $name,
                    'head_sha' => $sha,
                    'status' => 'completed',
                    'conclusion' => $conclusion->value,
                    'output' => $first,
                ]);
            }

            $id = $answer['id'] ?? null;
            if (!\is_int($id)) {
                throw new PullRequestCheckFailed('api_failed_malformed_body', permanent: false);
            }

            $sent = \count($firstPage);

            // GitHub appends the annotations of each update to the run, so the next pages go in more updates.
            foreach ($pages as $page) {
                try {
                    $this->api->patch($installationId, $runs.'/'.$id, ['output' => [...$output, 'annotations' => $page]]);
                } catch (GitHubAppApiFailed $e) {
                    // A body that does not decode follows a 2xx status, so GitHub holds the page already.
                    if ('malformed_body' !== $e->reason) {
                        throw $e;
                    }
                }
                $sent += \count($page);
            }
        } catch (GitHubAppApiFailed $e) {
            throw self::failed($e, $id, $sent);
        }

        return $id;
    }

    /** @return array{path: string, start_line: int, end_line: int, annotation_level: string, title: string, message: string} */
    private static function annotation(PullRequestCheckAnnotation $annotation): array
    {
        return [
            'path' => $annotation->path,
            'start_line' => $annotation->startLine,
            'end_line' => $annotation->endLine,
            'annotation_level' => $annotation->level->value,
            'title' => mb_substr($annotation->title, 0, self::MAX_ANNOTATION_TITLE_LENGTH),
            'message' => mb_strcut($annotation->message, 0, self::MAX_ANNOTATION_MESSAGE_BYTES, 'UTF-8'),
        ];
    }

    private static function failed(GitHubAppApiFailed $e, ?int $runId, int $sent): PullRequestCheckFailed
    {
        if ($e->rateLimited) {
            return new PullRequestCheckFailed('api_failed_rate_limited', permanent: false, previous: $e, retryAfterSeconds: $e->retryAfterSeconds, runId: $runId, annotationsSent: $sent);
        }
        if ('http_status' === $e->reason && \in_array($e->status, self::REFUSED_STATUSES, true)) {
            return new PullRequestCheckFailed('permission', permanent: true, previous: $e, runId: $runId, annotationsSent: $sent);
        }

        $cause = 'http_status' === $e->reason ? 'http_status_'.$e->status : $e->reason;

        return new PullRequestCheckFailed('api_failed_'.$cause, permanent: \in_array($e->reason, self::CONFIGURATION_REASONS, true), previous: $e, runId: $runId, annotationsSent: $sent);
    }
}
