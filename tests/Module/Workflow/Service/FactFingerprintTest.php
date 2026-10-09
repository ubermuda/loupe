<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Board\Workflow\BlockerFacts;
use App\Module\Board\Workflow\CardTypeFacts;
use App\Module\Board\Workflow\ChildrenFacts;
use App\Module\Board\Workflow\DocumentsFacts;
use App\Module\Board\Workflow\ParentDocumentsFacts;
use App\Module\Board\Workflow\ParentFacts;
use App\Module\Bridge\Workflow\ParentWorkFacts;
use App\Module\Bridge\Workflow\RefusalFacts;
use App\Module\Bridge\Workflow\WorkerRunFacts;
use App\Module\Bridge\Workflow\WorkRequestFacts;
use App\Module\Workflow\Contract\ChecksState;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\PullRequestFacts;
use App\Module\Workflow\Contract\PullRequestList;
use App\Module\Workflow\Contract\PullRequestState;
use App\Module\Workflow\Contract\Unreadable;
use App\Module\Workflow\Contract\UnreadableKind;
use App\Module\Workflow\Service\FactFingerprint;
use App\Tests\Module\Workflow\Fact\CardInputs;
use App\Tests\Module\Workflow\Fact\FactsMother;
use App\Tests\Module\Workflow\Fact\ProvidedFacts;
use App\Tests\Module\Workflow\Fact\RunInputs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FactFingerprintTest extends TestCase
{
    private const array WORKER_KINDS = ['implement', 'fix'];

    private const array PARENT_KINDS = ['breakdown', 'plan'];

    public function test_the_same_facts_give_the_same_sha256(): void
    {
        $keys = self::allKeys();

        $hash = new FactFingerprint()->of($this->facts(), $keys);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        self::assertSame($hash, new FactFingerprint()->of($this->facts(), $keys));
    }

    public function test_the_time_and_the_order_of_lists_do_not_count(): void
    {
        $keys = self::allKeys();
        $reordered = FactsMother::facts(
            card: FactsMother::card(slot: 'implementation', documents: [
                new DocumentFacts(['tech', 'design'], 'in_review', '01a10beb-ba65-736b-8626-a6e3fa59dfc5'),
                new DocumentFacts(['product'], 'approved', '01a10beb-ba65-736b-8626-a6e3fa59dfc5'),
            ]),
            pullRequest: FactsMother::pullRequest(checks: ChecksState::Passed),
            pullRequests: [FactsMother::pullRequest(state: PullRequestState::Closed), FactsMother::pullRequest(checks: ChecksState::Passed)],
            run: FactsMother::run(['review', 'implement'], 'no-capacity', ['fix', 'implement'], ['plan', 'breakdown']),
            now: new \DateTimeImmutable('2030-01-01'),
        );

        self::assertSame(new FactFingerprint()->of($this->facts(), $keys), new FactFingerprint()->of($reordered, $keys));
    }

    public function test_the_close_time_of_a_pull_request_does_not_count(): void
    {
        $closedAt = static function (string $time): Facts {
            $pullRequest = FactsMother::pullRequest(state: PullRequestState::Closed, closedAt: new \DateTimeImmutable($time));

            return FactsMother::facts(pullRequest: $pullRequest, pullRequests: [$pullRequest]);
        };
        $keys = [EngineFact::PullRequest, PullRequestList::class];

        self::assertSame(new FactFingerprint()->of($closedAt('2026-10-02 12:00:00'), $keys), new FactFingerprint()->of($closedAt('2026-10-02 12:05:00'), $keys));
    }

    /** @return iterable<string, array{EngineFact|class-string, Facts}> */
    public static function changes(): iterable
    {
        yield 'slot' => [EngineFact::Slot, FactsMother::facts(card: FactsMother::card(slot: 'in-review', documents: self::documents()), pullRequest: self::primary(), pullRequests: self::all(), run: self::workRun())];
        yield 'type' => [CardTypeFacts::class, FactsMother::facts(card: FactsMother::card(slot: 'implementation', type: 'bug', documents: self::documents()), pullRequest: self::primary(), pullRequests: self::all(), run: self::workRun())];
        yield 'blockers' => [BlockerFacts::class, FactsMother::facts(card: FactsMother::card(slot: 'implementation', hasOpenBlocker: true, documents: self::documents()), pullRequest: self::primary(), pullRequests: self::all(), run: self::workRun())];
        yield 'parent' => [ParentFacts::class, FactsMother::facts(card: FactsMother::card(slot: 'implementation', isChild: true, documents: self::documents()), pullRequest: self::primary(), pullRequests: self::all(), run: self::workRun())];
        yield 'children' => [ChildrenFacts::class, FactsMother::facts(card: FactsMother::card(slot: 'implementation', childCount: 2, openChildCount: 1, documents: self::documents()), pullRequest: self::primary(), pullRequests: self::all(), run: self::workRun())];
        yield 'a child merged into the epic branch' => [ChildrenFacts::class, FactsMother::facts(card: FactsMother::card(slot: 'implementation', documents: self::documents(), childMergedIntoEpicBranch: true), pullRequest: self::primary(), pullRequests: self::all(), run: self::workRun())];
        yield 'documents' => [DocumentsFacts::class, FactsMother::facts(card: FactsMother::card(slot: 'implementation', documents: [new DocumentFacts(['product'], 'approved', '01a10beb-ba65-736b-8626-a6e3fa59dfc5')]), pullRequest: self::primary(), pullRequests: self::all(), run: self::workRun())];
        yield 'parent documents' => [ParentDocumentsFacts::class, FactsMother::facts(card: FactsMother::card(slot: 'implementation', documents: self::documents(), parentDocuments: [new DocumentFacts(['tech-design'], 'approved', '01a10beb-ba65-736b-8626-a6e3fa59dfc5')]), pullRequest: self::primary(), pullRequests: self::all(), run: self::workRun())];
        yield 'pull request' => [EngineFact::PullRequest, FactsMother::facts(card: FactsMother::card(slot: 'implementation', documents: self::documents()), pullRequest: FactsMother::pullRequest(checks: ChecksState::Failed), pullRequests: self::all(), run: self::workRun())];
        yield 'pull requests' => [PullRequestList::class, FactsMother::facts(card: FactsMother::card(slot: 'implementation', documents: self::documents()), pullRequest: self::primary(), pullRequests: [self::primary()], run: self::workRun())];
        yield 'work requests' => [WorkRequestFacts::class, FactsMother::facts(card: FactsMother::card(slot: 'implementation', documents: self::documents()), pullRequest: self::primary(), pullRequests: self::all(), run: FactsMother::run(['implement'], 'no-capacity', self::WORKER_KINDS, self::PARENT_KINDS))];
        yield 'refusal' => [RefusalFacts::class, FactsMother::facts(card: FactsMother::card(slot: 'implementation', documents: self::documents()), pullRequest: self::primary(), pullRequests: self::all(), run: FactsMother::run(['implement', 'review'], 'no-bridge', self::WORKER_KINDS, self::PARENT_KINDS))];
        yield 'worker runs' => [WorkerRunFacts::class, FactsMother::facts(card: FactsMother::card(slot: 'implementation', documents: self::documents()), pullRequest: self::primary(), pullRequests: self::all(), run: FactsMother::run(['implement', 'review'], 'no-capacity', ['implement'], self::PARENT_KINDS))];
        yield 'parent slot' => [EngineFact::ParentSlot, FactsMother::facts(card: FactsMother::card(slot: 'implementation', documents: self::documents(), parentSlot: 'implementation'), pullRequest: self::primary(), pullRequests: self::all(), run: self::workRun())];
        yield 'parent work' => [ParentWorkFacts::class, FactsMother::facts(card: FactsMother::card(slot: 'implementation', documents: self::documents()), pullRequest: self::primary(), pullRequests: self::all(), run: FactsMother::run(['implement', 'review'], 'no-capacity', self::WORKER_KINDS, ['plan']))];
    }

    /** @param EngineFact|class-string $changed */
    #[DataProvider('changes')]
    public function test_a_change_counts_only_when_the_keys_read_its_group(EngineFact|string $changed, Facts $facts): void
    {
        $fingerprint = new FactFingerprint();
        $others = array_values(array_filter(self::allKeys(), static fn (EngineFact|string $key): bool => $key !== $changed));

        self::assertNotSame($fingerprint->of($this->facts(), [$changed]), $fingerprint->of($facts, [$changed]));
        self::assertNotSame($fingerprint->of($this->facts(), self::allKeys()), $fingerprint->of($facts, self::allKeys()));
        self::assertSame($fingerprint->of($this->facts(), $others), $fingerprint->of($facts, $others));
    }

    public function test_the_key_order_and_a_repeated_key_do_not_count(): void
    {
        $fingerprint = new FactFingerprint();

        self::assertSame(
            $fingerprint->of($this->facts(), [EngineFact::Slot, RefusalFacts::class]),
            $fingerprint->of($this->facts(), [RefusalFacts::class, EngineFact::Slot, RefusalFacts::class]),
        );
    }

    public function test_a_missing_pull_request_differs_from_a_present_one(): void
    {
        $fingerprint = new FactFingerprint();
        $none = FactsMother::facts(card: FactsMother::card(slot: 'implementation', documents: self::documents()), pullRequests: self::all(), run: self::workRun());

        self::assertNotSame($fingerprint->of($this->facts(), [EngineFact::PullRequest]), $fingerprint->of($none, [EngineFact::PullRequest]));
    }

    public function test_an_engine_group_keeps_the_hash_that_rule_states_already_store(): void
    {
        $fingerprint = new FactFingerprint();

        self::assertSame(hash('sha256', '{"slot":"implementation"}'), $fingerprint->of($this->facts(), [EngineFact::Slot]));
        self::assertSame(hash('sha256', '{"slot":"implementation"}'), $fingerprint->legacyOf($this->facts(), [EngineFact::Slot]));
    }

    public function test_a_provider_group_keeps_the_legacy_hash_under_its_old_name_and_the_provider_hash_under_its_class(): void
    {
        $fingerprint = new FactFingerprint();

        self::assertSame(hash('sha256', '{"children":[0,0]}'), $fingerprint->legacyOf($this->facts(), [ChildrenFacts::class]));
        self::assertSame(hash('sha256', json_encode(['class:'.ChildrenFacts::class => [0, 0]], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)), $fingerprint->of($this->facts(), [ChildrenFacts::class]));
    }

    public function test_a_stored_hash_of_either_form_stands_for_the_facts_and_a_missing_or_foreign_one_does_not(): void
    {
        $fingerprint = new FactFingerprint();
        $keys = [EngineFact::Slot, DocumentsFacts::class];

        self::assertTrue($fingerprint->sameAs($fingerprint->of($this->facts(), $keys), $this->facts(), $keys));
        self::assertTrue($fingerprint->sameAs($fingerprint->legacyOf($this->facts(), $keys), $this->facts(), $keys));
        self::assertFalse($fingerprint->sameAs(null, $this->facts(), $keys));
        self::assertFalse($fingerprint->sameAs(hash('sha256', 'other'), $this->facts(), $keys));
        self::assertNotSame($fingerprint->of($this->facts(), $keys), $fingerprint->legacyOf($this->facts(), $keys));
    }

    /** @return iterable<string, array{CardInputs, ?PullRequestFacts, list<PullRequestFacts>, RunInputs}> */
    public static function scenarios(): iterable
    {
        yield 'a neutral card' => [FactsMother::card(), null, [], FactsMother::run()];
        yield 'a busy card' => [
            FactsMother::card(slot: 'implementation', type: 'epic', hasOpenBlocker: true, isChild: true, childCount: 3, openChildCount: 2, documents: self::documents(), parentDocuments: [new DocumentFacts(['tech-design'], 'approved', '01a10beb-ba65-736b-8626-a6e3fa59dfc5')], parentSlot: 'next'),
            self::primary(),
            self::all(),
            self::workRun(),
        ];
        yield 'an epic with a child merged into its branch' => [
            FactsMother::card(slot: '@terminal', childCount: 2, openChildCount: 0, childMergedIntoEpicBranch: true),
            null,
            [FactsMother::pullRequest(state: PullRequestState::Merged, closedAt: new \DateTimeImmutable('2026-10-02'))],
            FactsMother::run(['review'], null, [], ['plan']),
        ];
    }

    /**
     * @param list<PullRequestFacts> $pullRequests
     */
    #[DataProvider('scenarios')]
    public function test_the_legacy_hash_on_the_new_facts_equals_the_old_hash_on_the_old_facts_for_every_group(CardInputs $card, ?PullRequestFacts $pullRequest, array $pullRequests, RunInputs $run): void
    {
        $fingerprint = new FactFingerprint();
        $facts = FactsMother::facts(card: $card, pullRequest: $pullRequest, pullRequests: $pullRequests, run: $run);
        $old = [
            'slot' => $card->slot,
            'card-type' => $card->type,
            'blockers' => $card->hasOpenBlocker,
            'parent' => $card->isChild,
            'children' => [$card->childCount, $card->openChildCount, ...($card->childMergedIntoEpicBranch ? [true] : [])],
            'documents' => self::oldSorted(array_map(self::oldDocument(...), $card->documents)),
            'parent-documents' => self::oldSorted(array_map(self::oldDocument(...), $card->parentDocuments)),
            'pull-request' => null === $pullRequest ? null : self::oldPullRequest($pullRequest),
            'pull-requests' => self::oldSorted(array_map(self::oldPullRequest(...), $pullRequests)),
            'work-requests' => self::oldSorted($run->activeWorkKinds),
            'refusal' => $run->lastRefusalCode,
            'worker-runs' => self::oldSorted($run->activeWorkerKinds),
            'parent-work' => self::oldSorted($run->parentActiveKinds),
            'parent-slot' => $card->parentSlot,
        ];
        $groups = array_combine(
            array_keys($old),
            [EngineFact::Slot, CardTypeFacts::class, BlockerFacts::class, ParentFacts::class, ChildrenFacts::class, DocumentsFacts::class, ParentDocumentsFacts::class, EngineFact::PullRequest, PullRequestList::class, WorkRequestFacts::class, RefusalFacts::class, WorkerRunFacts::class, ParentWorkFacts::class, EngineFact::ParentSlot],
        );

        foreach ($groups as $name => $key) {
            self::assertSame(self::oldHash([$name => $old[$name]]), $fingerprint->legacyOf($facts, [$key]), $name);
        }
        self::assertSame(self::oldHash($old), $fingerprint->legacyOf($facts, array_values($groups)));
    }

    /** @return list<EngineFact|class-string> */
    private static function allKeys(): array
    {
        return [
            ...EngineFact::cases(),
            CardTypeFacts::class,
            BlockerFacts::class,
            ParentFacts::class,
            ChildrenFacts::class,
            DocumentsFacts::class,
            ParentDocumentsFacts::class,
            PullRequestList::class,
            WorkRequestFacts::class,
            RefusalFacts::class,
            WorkerRunFacts::class,
            ParentWorkFacts::class,
        ];
    }

    /**
     * The hash of the fact builder that this release replaced, written out here so that the replacement has a fixed point to meet.
     *
     * @param array<string, mixed> $groups
     */
    private static function oldHash(array $groups): string
    {
        ksort($groups);

        return hash('sha256', json_encode($groups, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }

    /** @return array{string, list<string>} */
    private static function oldDocument(DocumentFacts $document): array
    {
        return [$document->status, self::oldSorted($document->tags)];
    }

    /** @return list<mixed> */
    private static function oldPullRequest(PullRequestFacts $pullRequest): array
    {
        return [
            $pullRequest->state->value, $pullRequest->draft, $pullRequest->checks->value, $pullRequest->conflicting, $pullRequest->behind,
            $pullRequest->approvalsCoveringHead, $pullRequest->changesRequested, $pullRequest->baseIsMergeTarget, $pullRequest->baseIsEpicBranch,
            $pullRequest->stacked, $pullRequest->parentMerged,
        ];
    }

    /**
     * @param list<mixed> $items
     *
     * @return list<mixed>
     */
    private static function oldSorted(array $items): array
    {
        usort($items, static fn (mixed $a, mixed $b): int => json_encode($a, \JSON_THROW_ON_ERROR) <=> json_encode($b, \JSON_THROW_ON_ERROR));

        return $items;
    }

    public function test_a_facts_class_counts_through_the_fingerprint_its_provider_gave(): void
    {
        $fingerprint = new FactFingerprint();
        $of = static fn (mixed $given): string => $fingerprint->of(
            FactsMother::facts(provided: [ProvidedFacts::class => new ProvidedFacts()], fingerprints: [ProvidedFacts::class => $given]),
            [EngineFact::Slot, ProvidedFacts::class],
        );

        self::assertSame($of([true, 1]), $of([true, 1]));
        self::assertNotSame($of([true, 1]), $of([true, 2]));
        self::assertNotSame(
            $fingerprint->of(FactsMother::facts(provided: [ProvidedFacts::class => new ProvidedFacts()], fingerprints: [ProvidedFacts::class => [true, 1]]), [EngineFact::Slot]),
            $of([true, 1]),
        );
    }

    public function test_a_facts_class_with_no_fingerprint_counts_as_null(): void
    {
        $fingerprint = new FactFingerprint();
        $unreadable = $fingerprint->of(FactsMother::facts(provided: [ProvidedFacts::class => new Unreadable(UnreadableKind::Failed, 'workflow.source.board')]), [ProvidedFacts::class]);

        self::assertSame($unreadable, $fingerprint->of(FactsMother::facts(), [ProvidedFacts::class]));
        self::assertSame($unreadable, $fingerprint->of(FactsMother::facts(provided: [ProvidedFacts::class => new Unreadable(UnreadableKind::Off, 'workflow.source.board')]), [ProvidedFacts::class]));
        self::assertNotSame($unreadable, $fingerprint->of(FactsMother::facts(fingerprints: [ProvidedFacts::class => [false, 1]]), [ProvidedFacts::class]));
    }

    private function facts(): Facts
    {
        return FactsMother::facts(
            card: FactsMother::card(slot: 'implementation', documents: self::documents()),
            pullRequest: self::primary(),
            pullRequests: self::all(),
            run: self::workRun(),
        );
    }

    /** @return list<DocumentFacts> */
    private static function documents(): array
    {
        return [new DocumentFacts(['product'], 'approved', '01a10beb-ba65-736b-8626-a6e3fa59dfc5'), new DocumentFacts(['design', 'tech'], 'in_review', '01a10beb-ba65-736b-8626-a6e3fa59dfc5')];
    }

    private static function primary(): PullRequestFacts
    {
        return FactsMother::pullRequest(checks: ChecksState::Passed);
    }

    /** @return list<PullRequestFacts> */
    private static function all(): array
    {
        return [self::primary(), FactsMother::pullRequest(state: PullRequestState::Closed)];
    }

    private static function workRun(): RunInputs
    {
        return FactsMother::run(['implement', 'review'], 'no-capacity', self::WORKER_KINDS, self::PARENT_KINDS);
    }
}
