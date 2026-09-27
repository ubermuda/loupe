<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\PullRequestSnapshot;
use App\Module\Forge\Service\PullRequestUnreadable;

/** Turns the GraphQL `pullRequest` node of GitHubPullRequestStateReader into a snapshot. */
final readonly class GitHubPullRequestStateMapper
{
    private const array MERGEABLE_STATES = ['CLEAN', 'HAS_HOOKS', 'UNSTABLE'];

    /**
     * @param array<mixed> $pullRequest
     * @param ?int         $behindBy    the commits the head lacks from the base, or null when not compared
     *
     * @throws PullRequestUnreadable
     */
    public function map(array $pullRequest, ?GitHubBranchRules $rules, ?int $behindBy): PullRequestSnapshot
    {
        $state = match ($pullRequest['state'] ?? null) {
            'OPEN' => PullRequestState::Open,
            'MERGED' => PullRequestState::Merged,
            'CLOSED' => PullRequestState::Closed,
            default => null,
        };
        $headSha = $pullRequest['headRefOid'] ?? null;
        $baseBranch = $pullRequest['baseRefName'] ?? null;
        if (null === $state || !\is_string($headSha) || '' === $headSha || !\is_string($baseBranch) || '' === $baseBranch) {
            throw new PullRequestUnreadable('malformed_body');
        }

        $draft = true === ($pullRequest['isDraft'] ?? null);
        $mergeable = $pullRequest['mergeable'] ?? null;
        $mergeStateStatus = $pullRequest['mergeStateStatus'] ?? null;
        [$checks, $failedChecks] = $this->checks($pullRequest, $rules, 'BLOCKED' === $mergeStateStatus);
        $mergeability = match (true) {
            'CONFLICTING' === $mergeable, 'DIRTY' === $mergeStateStatus => PullRequestMergeability::Conflicting,
            'BEHIND' === $mergeStateStatus, $behindBy > 0 => PullRequestMergeability::Behind,
            'BLOCKED' === $mergeStateStatus => PullRequestMergeability::Blocked,
            'UNKNOWN' === $mergeable => PullRequestMergeability::Unknown,
            \in_array($mergeStateStatus, self::MERGEABLE_STATES, true) => PullRequestMergeability::Mergeable,
            default => PullRequestMergeability::Unknown,
        };

        return new PullRequestSnapshot(
            state: $state,
            draft: $draft,
            headSha: $headSha,
            baseBranch: $baseBranch,
            checks: $checks,
            checksSha: PullRequestChecks::Pending === $checks ? null : $headSha,
            failedChecks: $failedChecks,
            mergeability: $mergeability,
            review: match ($pullRequest['reviewDecision'] ?? null) {
                'APPROVED' => PullRequestReview::Approved,
                'CHANGES_REQUESTED' => PullRequestReview::ChangesRequested,
                'REVIEW_REQUIRED' => PullRequestReview::Required,
                default => PullRequestReview::None,
            },
            readyToMerge: PullRequestState::Open === $state && !$draft && PullRequestChecks::Passed === $checks && PullRequestMergeability::Mergeable === $mergeability,
        );
    }

    /**
     * The ruleset names the required checks when it was read. Otherwise the
     * head's own `isRequired` flags do, and those miss a check that never ran.
     *
     * @param array<mixed> $pullRequest
     *
     * @return array{PullRequestChecks, list<string>}
     */
    private function checks(array $pullRequest, ?GitHubBranchRules $rules, bool $blocked): array
    {
        $verdicts = [];
        $flaggedRequired = [];
        foreach ($this->contexts($pullRequest) as $context) {
            [$name, $verdict] = match ($context['__typename'] ?? null) {
                'CheckRun' => [$context['name'] ?? null, $this->checkRunVerdict($context)],
                'StatusContext' => [$context['context'] ?? null, $this->statusVerdict($context)],
                default => [null, null],
            };
            if (!\is_string($name) || '' === $name || null === $verdict) {
                continue;
            }

            $verdicts[$name] = $verdict;
            if (true === ($context['isRequired'] ?? null)) {
                $flaggedRequired[$name] = true;
            } else {
                unset($flaggedRequired[$name]);
            }
        }

        $required = $rules->requiredChecks ?? array_map(strval(...), array_keys($flaggedRequired));
        if ([] === $required) {
            return [$blocked ? PullRequestChecks::Pending : PullRequestChecks::Passed, []];
        }

        $failed = [];
        foreach ($required as $name) {
            $verdict = $verdicts[$name] ?? PullRequestChecks::Pending;
            if (PullRequestChecks::Pending === $verdict) {
                return [PullRequestChecks::Pending, []];
            }
            if (PullRequestChecks::Failed === $verdict) {
                $failed[] = $name;
            }
        }
        sort($failed);

        return [[] === $failed ? PullRequestChecks::Passed : PullRequestChecks::Failed, $failed];
    }

    /**
     * @param array<mixed> $pullRequest
     *
     * @return list<array<mixed>>
     */
    private function contexts(array $pullRequest): array
    {
        $nodes = $pullRequest['commits']['nodes'][0]['commit']['statusCheckRollup']['contexts']['nodes'] ?? [];

        return \is_array($nodes) ? array_values(array_filter($nodes, \is_array(...))) : [];
    }

    /** @param array<mixed> $checkRun */
    private function checkRunVerdict(array $checkRun): PullRequestChecks
    {
        if ('COMPLETED' !== ($checkRun['status'] ?? null)) {
            return PullRequestChecks::Pending;
        }

        return \in_array($checkRun['conclusion'] ?? null, ['SUCCESS', 'NEUTRAL', 'SKIPPED'], true) ? PullRequestChecks::Passed : PullRequestChecks::Failed;
    }

    /**
     * A state GitHub has not documented stays pending rather than reads as a failure.
     *
     * @param array<mixed> $status
     */
    private function statusVerdict(array $status): PullRequestChecks
    {
        return match ($status['state'] ?? null) {
            'SUCCESS' => PullRequestChecks::Passed,
            'FAILURE', 'ERROR' => PullRequestChecks::Failed,
            default => PullRequestChecks::Pending,
        };
    }
}
