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
     * A check is required when the ruleset names it or the head flags it. The
     * ruleset finds a check that never ran, and the flag finds one that classic
     * branch protection requires.
     *
     * @param array<mixed> $pullRequest
     *
     * @return array{PullRequestChecks, list<string>}
     */
    private function checks(array $pullRequest, ?GitHubBranchRules $rules, bool $blocked): array
    {
        $verdicts = [];
        $flags = [];
        foreach ($this->contexts($pullRequest) as $context) {
            [$type, $name, $verdict] = match ($context['__typename'] ?? null) {
                'CheckRun' => ['CheckRun', $context['name'] ?? null, $this->checkRunVerdict($context)],
                'StatusContext' => ['StatusContext', $context['context'] ?? null, $this->statusVerdict($context)],
                default => [null, null, null],
            };
            if (null === $type || !\is_string($name) || '' === $name || null === $verdict) {
                continue;
            }

            // A check run and a commit status can share a name, and each must pass.
            $verdicts[$name][$type] = $verdict;
            $flags[$name][$type] = true === ($context['isRequired'] ?? null);
        }

        $flaggedRequired = array_keys(array_filter($flags, static fn (array $byType): bool => \in_array(true, $byType, true)));
        $required = array_values(array_unique([...$rules->requiredChecks ?? [], ...array_map(strval(...), $flaggedRequired)]));
        if ([] === $required) {
            return [$blocked ? PullRequestChecks::Pending : PullRequestChecks::Passed, []];
        }

        $failed = [];
        foreach ($required as $name) {
            $named = $verdicts[$name] ?? [PullRequestChecks::Pending];
            if (\in_array(PullRequestChecks::Pending, $named, true)) {
                return [PullRequestChecks::Pending, []];
            }
            if (\in_array(PullRequestChecks::Failed, $named, true)) {
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
