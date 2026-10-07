<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Fact;

use App\Module\Workflow\Contract\CardFacts;
use App\Module\Workflow\Contract\ChecksState;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\PullRequestFacts;
use App\Module\Workflow\Contract\PullRequestState;
use App\Module\Workflow\Contract\RunFacts;
use App\Module\Workflow\Contract\Unreadable;
use Symfony\Component\Uid\Uuid;

/** Builds facts for a neutral card. Each named argument overrides one default. */
final class FactsMother
{
    /**
     * @param list<PullRequestFacts>                 $pullRequests
     * @param array<class-string, object|Unreadable> $provided
     * @param array<class-string, mixed>             $fingerprints
     */
    public static function facts(
        ?CardFacts $card = null,
        ?PullRequestFacts $pullRequest = null,
        array $pullRequests = [],
        ?RunFacts $run = null,
        \DateTimeImmutable $now = new \DateTimeImmutable('2026-10-01 12:00:00'),
        array $provided = [],
        array $fingerprints = [],
    ): Facts {
        return new Facts(
            now: $now,
            card: $card ?? self::card(),
            pullRequest: $pullRequest,
            pullRequests: $pullRequests,
            run: $run ?? self::run(),
            provided: $provided,
            fingerprints: $fingerprints,
        );
    }

    /** @param list<DocumentFacts> $documents */
    public static function card(
        ?string $slot = null,
        string $type = 'feature',
        bool $hasOpenBlocker = false,
        bool $isChild = false,
        int $childCount = 0,
        int $openChildCount = 0,
        array $documents = [],
        bool $childMergedIntoEpicBranch = false,
    ): CardFacts {
        return new CardFacts(
            slot: $slot,
            type: $type,
            hasOpenBlocker: $hasOpenBlocker,
            isChild: $isChild,
            childCount: $childCount,
            openChildCount: $openChildCount,
            documents: $documents,
            childMergedIntoEpicBranch: $childMergedIntoEpicBranch,
        );
    }

    public static function pullRequest(
        PullRequestState $state = PullRequestState::Open,
        bool $draft = false,
        ChecksState $checks = ChecksState::None,
        bool $conflicting = false,
        bool $behind = false,
        int $approvalsCoveringHead = 0,
        bool $changesRequested = false,
        bool $baseIsMergeTarget = true,
        bool $baseIsEpicBranch = false,
        bool $stacked = false,
        bool $parentMerged = false,
        ?\DateTimeImmutable $closedAt = null,
        ?Uuid $id = null,
    ): PullRequestFacts {
        return new PullRequestFacts(
            state: $state,
            draft: $draft,
            checks: $checks,
            conflicting: $conflicting,
            behind: $behind,
            approvalsCoveringHead: $approvalsCoveringHead,
            changesRequested: $changesRequested,
            baseIsMergeTarget: $baseIsMergeTarget,
            baseIsEpicBranch: $baseIsEpicBranch,
            stacked: $stacked,
            parentMerged: $parentMerged,
            closedAt: $closedAt,
            id: $id,
        );
    }

    /**
     * @param list<string> $activeWorkKinds
     * @param list<string> $activeWorkerKinds
     * @param list<string> $parentActiveKinds
     */
    public static function run(array $activeWorkKinds = [], ?string $lastRefusalCode = null, array $activeWorkerKinds = [], array $parentActiveKinds = []): RunFacts
    {
        return new RunFacts(activeWorkKinds: $activeWorkKinds, lastRefusalCode: $lastRefusalCode, activeWorkerKinds: $activeWorkerKinds, parentActiveKinds: $parentActiveKinds);
    }
}
