<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Fact;

use App\Module\AgentReview\Workflow\AgentReviewFactProvider;
use App\Module\AgentReview\Workflow\AgentReviewFacts;
use App\Module\Board\Workflow\BlockerFactProvider;
use App\Module\Board\Workflow\BlockerFacts;
use App\Module\Board\Workflow\CardTypeFactProvider;
use App\Module\Board\Workflow\CardTypeFacts;
use App\Module\Board\Workflow\ChildrenFactProvider;
use App\Module\Board\Workflow\ChildrenFacts;
use App\Module\Board\Workflow\DocumentsFactProvider;
use App\Module\Board\Workflow\DocumentsFacts;
use App\Module\Board\Workflow\ParentDocumentsFactProvider;
use App\Module\Board\Workflow\ParentDocumentsFacts;
use App\Module\Board\Workflow\ParentFactProvider;
use App\Module\Board\Workflow\ParentFacts;
use App\Module\Board\Workflow\PullRequestListFactProvider;
use App\Module\Bridge\Workflow\ParentWorkFactProvider;
use App\Module\Bridge\Workflow\ParentWorkFacts;
use App\Module\Bridge\Workflow\RefusalFactProvider;
use App\Module\Bridge\Workflow\RefusalFacts;
use App\Module\Bridge\Workflow\WorkerRunFactProvider;
use App\Module\Bridge\Workflow\WorkerRunFacts;
use App\Module\Bridge\Workflow\WorkRequestFactProvider;
use App\Module\Bridge\Workflow\WorkRequestFacts;
use App\Module\Workflow\Contract\ChecksState;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\FactProvider;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\LegacyFingerprintGroup;
use App\Module\Workflow\Contract\PullRequestFacts;
use App\Module\Workflow\Contract\PullRequestList;
use App\Module\Workflow\Contract\PullRequestState;
use App\Module\Workflow\Contract\Unreadable;
use Symfony\Component\Uid\Uuid;

/** Builds facts for a neutral card. Each named argument overrides one default. */
final class FactsMother
{
    private const array PROVIDERS = [
        CardTypeFactProvider::class,
        BlockerFactProvider::class,
        ParentFactProvider::class,
        ChildrenFactProvider::class,
        DocumentsFactProvider::class,
        ParentDocumentsFactProvider::class,
        PullRequestListFactProvider::class,
        WorkRequestFactProvider::class,
        RefusalFactProvider::class,
        WorkerRunFactProvider::class,
        ParentWorkFactProvider::class,
    ];

    /**
     * The facts the providers of Board and Bridge give for the inputs. Their fingerprints come from the providers themselves.
     *
     * @param list<PullRequestFacts>                 $pullRequests
     * @param array<class-string, object|Unreadable> $provided     replaces what the inputs give, or adds a facts class
     * @param array<class-string, mixed>             $fingerprints
     */
    public static function facts(
        ?CardInputs $card = null,
        ?PullRequestFacts $pullRequest = null,
        array $pullRequests = [],
        ?RunInputs $run = null,
        \DateTimeImmutable $now = new \DateTimeImmutable('2026-10-01 12:00:00'),
        array $provided = [],
        array $fingerprints = [],
    ): Facts {
        $card ??= self::card();
        $run ??= self::run();
        $given = [
            ...self::byClass(new CardTypeFacts($card->type), new BlockerFacts($card->hasOpenBlocker), new ParentFacts($card->isChild)),
            ...self::byClass(new ChildrenFacts($card->childCount, $card->openChildCount, $card->childMergedIntoEpicBranch), new DocumentsFacts($card->documents), new ParentDocumentsFacts($card->parentDocuments)),
            ...self::byClass(new PullRequestList($pullRequests, $pullRequest)),
            ...self::byClass(new AgentReviewFacts([], enabled: false, epic: false, unposted: false)),
            ...self::byClass(new WorkRequestFacts($run->activeWorkKinds), new RefusalFacts($run->lastRefusalCode), new WorkerRunFacts($run->activeWorkerKinds), new ParentWorkFacts($run->parentActiveKinds)),
        ];
        $prints = [];
        $legacy = [];
        foreach (self::PROVIDERS as $providerClass) {
            $provider = new \ReflectionClass($providerClass)->newInstanceWithoutConstructor();
            \assert($provider instanceof FactProvider && $provider instanceof LegacyFingerprintGroup);
            $prints[$provider->factsClass()] = $provider->fingerprint($given[$provider->factsClass()]);
            $legacy[$provider->factsClass()] = $provider->legacyGroup();
        }

        $agentReview = [...$given, ...$provided][AgentReviewFacts::class];
        $prints[AgentReviewFacts::class] = new \ReflectionClass(AgentReviewFactProvider::class)->newInstanceWithoutConstructor()->fingerprint($agentReview);

        return new Facts(
            now: $now,
            slot: $card->slot,
            parentSlot: $card->parentSlot,
            pullRequest: $pullRequest,
            provided: [...$given, ...$provided],
            fingerprints: [...$prints, ...$fingerprints],
            legacyGroups: $legacy,
        );
    }

    /**
     * @param list<DocumentFacts> $documents
     * @param list<DocumentFacts> $parentDocuments
     */
    public static function card(
        ?string $slot = null,
        string $type = 'feature',
        bool $hasOpenBlocker = false,
        bool $isChild = false,
        int $childCount = 0,
        int $openChildCount = 0,
        array $documents = [],
        bool $childMergedIntoEpicBranch = false,
        array $parentDocuments = [],
        ?string $parentSlot = null,
    ): CardInputs {
        return new CardInputs($slot, $type, $hasOpenBlocker, $isChild, $childCount, $openChildCount, $documents, $childMergedIntoEpicBranch, $parentDocuments, $parentSlot);
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
    public static function run(array $activeWorkKinds = [], ?string $lastRefusalCode = null, array $activeWorkerKinds = [], array $parentActiveKinds = []): RunInputs
    {
        return new RunInputs($activeWorkKinds, $lastRefusalCode, $activeWorkerKinds, $parentActiveKinds);
    }

    /** @return array<class-string, object> */
    private static function byClass(object ...$facts): array
    {
        $byClass = [];
        foreach ($facts as $one) {
            $byClass[$one::class] = $one;
        }

        return $byClass;
    }
}
