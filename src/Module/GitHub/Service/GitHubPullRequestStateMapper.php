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
        [$approvedAt, $approvalSha, $approvalId] = $this->approval($pullRequest);
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
            review: $this->review($pullRequest),
            readyToMerge: PullRequestState::Open === $state && !$draft && PullRequestChecks::Passed === $checks && PullRequestMergeability::Mergeable === $mergeability,
            changesRequestedSha: $this->changesRequestedSha($pullRequest, $headSha),
            openedAt: $this->time($pullRequest['createdAt'] ?? null),
            mergedAt: $this->time($pullRequest['mergedAt'] ?? null),
            approvedAt: $approvedAt,
            approvalSha: $approvalSha,
            defaultBranch: $this->defaultBranch($pullRequest),
            headParents: $this->headParents($pullRequest),
            approvalId: $approvalId,
        );
    }

    /**
     * The time, commit and review id of the oldest approval that stands.
     *
     * @param array<mixed> $pullRequest
     *
     * @return array{?\DateTimeImmutable, ?string, ?string}
     */
    private function approval(array $pullRequest): array
    {
        $nodes = $pullRequest['latestOpinionatedReviews']['nodes'] ?? [];
        $oldest = [null, null, null];
        foreach (\is_array($nodes) ? $nodes : [] as $node) {
            if (!\is_array($node) || 'APPROVED' !== ($node['state'] ?? null)) {
                continue;
            }
            $time = $this->time($node['submittedAt'] ?? null);
            $oid = $node['commit']['oid'] ?? null;
            $id = $node['id'] ?? null;
            if (null !== $time && \is_string($oid) && '' !== $oid && \is_string($id) && '' !== $id && (null === $oldest[0] || $time < $oldest[0])) {
                $oldest = [$time, $oid, $id];
            }
        }

        return $oldest;
    }

    /** @param array<mixed> $pullRequest */
    private function defaultBranch(array $pullRequest): ?string
    {
        $name = $pullRequest['baseRepository']['defaultBranchRef']['name'] ?? null;

        return \is_string($name) && '' !== $name ? $name : null;
    }

    /**
     * @param array<mixed> $pullRequest
     *
     * @return list<string>
     */
    private function headParents(array $pullRequest): array
    {
        $nodes = $pullRequest['commits']['nodes'][0]['commit']['parents']['nodes'] ?? [];
        $oids = [];
        foreach (\is_array($nodes) ? $nodes : [] as $node) {
            $oid = \is_array($node) ? ($node['oid'] ?? null) : null;
            if (\is_string($oid) && '' !== $oid) {
                $oids[] = $oid;
            }
        }

        return $oids;
    }

    /** A malformed time is null, because the times feed reports only and must not make the read unreadable. */
    private function time(mixed $value): ?\DateTimeImmutable
    {
        $time = \is_string($value) ? \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $value) : false;

        return false === $time ? null : $time->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * GitHub gives no review decision on a repository without a required
     * review, so the latest review of each writer decides there.
     *
     * @param array<mixed> $pullRequest
     */
    private function review(array $pullRequest): PullRequestReview
    {
        $decision = $pullRequest['reviewDecision'] ?? null;
        if (null !== $decision) {
            return match ($decision) {
                'APPROVED' => PullRequestReview::Approved,
                'CHANGES_REQUESTED' => PullRequestReview::ChangesRequested,
                'REVIEW_REQUIRED' => PullRequestReview::Required,
                default => PullRequestReview::None,
            };
        }

        $nodes = $pullRequest['latestOpinionatedReviews']['nodes'] ?? [];
        $states = \is_array($nodes) ? array_map(static fn (mixed $node): mixed => \is_array($node) ? ($node['state'] ?? null) : null, $nodes) : [];

        return match (true) {
            \in_array('CHANGES_REQUESTED', $states, true) => PullRequestReview::ChangesRequested,
            \in_array('APPROVED', $states, true) => PullRequestReview::Approved,
            default => PullRequestReview::None,
        };
    }

    /**
     * The head sha when a change request sits on the head, else the commit of an active change request.
     *
     * @param array<mixed> $pullRequest
     */
    private function changesRequestedSha(array $pullRequest, string $headSha): ?string
    {
        $nodes = $pullRequest['latestOpinionatedReviews']['nodes'] ?? [];
        $shas = [];
        foreach (\is_array($nodes) ? $nodes : [] as $node) {
            $oid = \is_array($node) && 'CHANGES_REQUESTED' === ($node['state'] ?? null) ? ($node['commit']['oid'] ?? null) : null;
            if (\is_string($oid) && '' !== $oid) {
                $shas[] = $oid;
            }
        }

        return \in_array($headSha, $shas, true) ? $headSha : array_last($shas);
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
