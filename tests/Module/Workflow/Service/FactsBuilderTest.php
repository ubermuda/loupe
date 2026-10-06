<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardLink;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState as ForgePullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Module\Workflow\Contract\ChecksState;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\PullRequestFacts;
use App\Module\Workflow\Contract\PullRequestState;
use App\Module\Workflow\Contract\Unreadable;
use App\Module\Workflow\Contract\UnreadableKind;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use App\Module\Workflow\Service\CardPullRequests;
use App\Module\Workflow\Service\FactProviders;
use App\Module\Workflow\Service\FactsBuilder;
use App\Tests\Module\Workflow\Fact\ProvidedFacts;
use App\Tests\Module\Workflow\Fact\ProvidedFactsProvider;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class FactsBuilderTest extends KernelTestCase
{
    use WorkflowProjects;

    private const string REPOSITORY = 'Acme/Widgets';

    private int $cardNumber = 0;

    private int $pullRequestNumber = 0;

    /** @var array<string, Tag> */
    private array $tags = [];

    private ProvidedFactsProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        $this->provider = new ProvidedFactsProvider();
    }

    public function test_a_provider_that_is_on_gives_its_facts_beside_the_built_in_ones(): void
    {
        self::bootKernel();
        $this->provider->facts = new ProvidedFacts(ready: true);

        $facts = $this->facts($this->card($this->workflowProject('facts-provided'), 'next'));

        self::assertSame($this->provider->facts, $facts->get(ProvidedFacts::class));
        self::assertNull($facts->unreadable(ProvidedFacts::class));
        self::assertSame([ProvidedFacts::class => [true, 1]], $facts->fingerprints);
    }

    public function test_a_provider_that_is_off_gives_an_off_source_and_is_not_built(): void
    {
        self::bootKernel();
        $this->provider->on = false;
        $this->provider->failure = new \RuntimeException('A provider that is off is never built.');

        $unreadable = $this->facts($this->card($this->workflowProject('facts-off'), 'next'))->unreadable(ProvidedFacts::class);

        self::assertEquals(new Unreadable(UnreadableKind::Off, 'workflow.source.board'), $unreadable);
    }

    public function test_a_provider_that_throws_gives_a_failed_source_with_its_cause(): void
    {
        self::bootKernel();
        $failure = new \RuntimeException('The source is down.');
        $this->provider->failure = $failure;

        $facts = $this->facts($this->card($this->workflowProject('facts-failed'), 'next'));

        self::assertEquals(new Unreadable(UnreadableKind::Failed, 'workflow.source.board', $failure), $facts->unreadable(ProvidedFacts::class));
        self::assertFalse($facts->card->hasOpenBlocker);
        $this->expectException(\LogicException::class);
        $facts->get(ProvidedFacts::class);
    }

    public function test_a_provider_whose_source_throws_gives_a_failed_source_named_by_its_class(): void
    {
        self::bootKernel();
        $failure = new \RuntimeException('The label is gone.');
        $this->provider->sourceFailure = $failure;

        $unreadable = $this->facts($this->card($this->workflowProject('facts-source-failed'), 'next'))->unreadable(ProvidedFacts::class);

        self::assertEquals(new Unreadable(UnreadableKind::Failed, ProvidedFactsProvider::class, $failure), $unreadable);
    }

    public function test_a_provider_whose_fingerprint_throws_gives_a_failed_source(): void
    {
        self::bootKernel();
        $failure = new \RuntimeException('The fingerprint is broken.');
        $this->provider->fingerprintFailure = $failure;

        $unreadable = $this->facts($this->card($this->workflowProject('facts-fingerprint-failed'), 'next'))->unreadable(ProvidedFacts::class);

        self::assertEquals(new Unreadable(UnreadableKind::Failed, 'workflow.source.board', $failure), $unreadable);
    }

    public function test_a_database_error_in_a_provider_leaves_the_transaction_of_the_caller_usable(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('facts-database-failed'), 'next');
        $connection = $this->em()->getConnection();
        $this->provider->onBuild = static fn () => $connection->executeQuery('SELECT * FROM no_such_table');

        $connection->beginTransaction();
        $facts = $this->facts($card);

        self::assertSame(UnreadableKind::Failed, $facts->unreadable(ProvidedFacts::class)?->kind);
        self::assertSame(1, $connection->fetchOne('SELECT 1'));
        $connection->rollBack();
    }

    public function test_a_provider_that_swallows_its_own_database_error_gives_a_failed_source_and_leaves_the_transaction_usable(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('facts-database-swallowed'), 'next');
        $connection = $this->em()->getConnection();
        $this->provider->onBuild = static function () use ($connection): void {
            try {
                $connection->executeQuery('SELECT * FROM no_such_table');
            } catch (\Throwable) {
            }
        };

        $connection->beginTransaction();
        $facts = $this->facts($card);

        self::assertSame(UnreadableKind::Failed, $facts->unreadable(ProvidedFacts::class)?->kind);
        self::assertSame(1, $connection->fetchOne('SELECT 1'));
        $connection->rollBack();
    }

    public function test_two_providers_for_one_facts_class_are_refused(): void
    {
        $this->expectException(\LogicException::class);

        new FactProviders([new ProvidedFactsProvider(), new ProvidedFactsProvider()]);
    }

    public function test_the_slot_is_the_flag_of_the_column_or_the_slot_linked_to_it(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('facts-slot');
        $this->bindLifecycle($project);

        self::assertSame('@backlog', $this->facts($this->card($project, 'backlog'))->card->slot);
        self::assertSame('@terminal', $this->facts($this->card($project, 'done'))->card->slot);
        self::assertSame('implementation', $this->facts($this->card($project, 'in-progress'))->card->slot);
        self::assertSame('tech-design', $this->facts($this->card($project, 'tech-design'))->card->slot);
    }

    public function test_an_unlinked_column_has_no_slot(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('facts-unbound');

        self::assertNull($this->facts($this->card($project, 'in-progress'))->card->slot);
    }

    public function test_a_neutral_card_gives_neutral_facts(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('facts-neutral');
        $card = $this->card($project, 'next', CardType::Bug);
        $now = new \DateTimeImmutable('2026-10-02 12:00:00');

        $facts = $this->builder()->build($card, $now);

        self::assertSame($now, $facts->now);
        self::assertSame('bug', $facts->card->type);
        self::assertFalse($facts->card->hasOpenBlocker);
        self::assertFalse($facts->card->isChild);
        self::assertSame(0, $facts->card->childCount);
        self::assertSame(0, $facts->card->openChildCount);
        self::assertSame([], $facts->card->documents);
        self::assertNull($facts->pullRequest);
        self::assertSame([], $facts->pullRequests);
        self::assertSame([], $facts->run->activeWorkKinds);
        self::assertNull($facts->run->lastRefusalCode);
        self::assertSame([], $facts->run->activeWorkerKinds);
        self::assertSame([], $facts->run->parentActiveKinds);
    }

    public function test_the_card_facts_count_open_blockers_children_and_the_parent(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('facts-card');
        $epic = $this->card($project, 'in-progress', CardType::Epic);
        $card = $this->card($project, 'next');
        $card->parent = $epic;
        $openChild = $this->card($project, 'next');
        $openChild->parent = $card;
        $doneChild = $this->card($project, 'done');
        $doneChild->parent = $card;
        $finishedBlocker = $this->card($project, 'done');
        $this->em()->persist(new CardLink($finishedBlocker, $card, CardLinkKind::Blocks));
        $this->em()->flush();

        $facts = $this->facts($card);
        self::assertTrue($facts->card->isChild);
        self::assertSame(2, $facts->card->childCount);
        self::assertSame(1, $facts->card->openChildCount);
        self::assertFalse($facts->card->hasOpenBlocker);

        $this->em()->persist(new CardLink($this->card($project, 'next'), $card, CardLinkKind::Blocks));
        $this->em()->flush();
        self::assertTrue($this->facts($card)->card->hasOpenBlocker);
    }

    public function test_the_documents_are_the_unarchived_linked_documents_with_their_tags_and_status(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('facts-documents');
        $card = $this->card($project, 'tech-design');
        $product = $this->document($card, DocumentStatus::Approved, ['product']);
        $design = $this->document($card, DocumentStatus::ChangesRequested, ['tech', 'design']);
        $untagged = $this->document($card, DocumentStatus::InReview, []);
        $this->document($card, DocumentStatus::Approved, ['tech'], archived: true);
        $this->document($this->card($project, 'next'), DocumentStatus::Approved, ['elsewhere']);

        self::assertEquals([
            new DocumentFacts(['product'], 'approved', $product),
            new DocumentFacts(['design', 'tech'], 'changes-requested', $design),
            new DocumentFacts([], 'in-review', $untagged),
        ], $this->facts($card)->card->documents);
    }

    public function test_the_run_facts_give_the_live_kinds_and_a_refusal_that_settled_last(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('facts-run');
        $card = $this->card($project, 'in-progress');
        $this->workRequest($card, 'implement', WorkRequestState::Open);
        $this->workRequest($card, 'review', WorkRequestState::Claimed);
        $this->workRequest($card, 'design', WorkRequestState::Cancelled);
        $this->workRequest($card, 'design', WorkRequestState::Done, settledAt: '2026-10-02 10:00:00');
        $this->workRequest($card, 'design', WorkRequestState::Refused, 'no-capacity', '2026-10-02 11:00:00');

        $run = $this->facts($card)->run;
        self::assertSame(['implement', 'review'], $run->activeWorkKinds);
        self::assertSame('no-capacity', $run->lastRefusalCode);

        $this->workRequest($card, 'design', WorkRequestState::Done, settledAt: '2026-10-02 11:30:00');
        self::assertNull($this->facts($card)->run->lastRefusalCode);
    }

    public function test_the_run_facts_give_the_kinds_of_the_open_worker_runs_of_the_card_and_of_its_parent(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('facts-worker-runs');
        $epic = $this->card($project, 'in-progress', CardType::Epic);
        $child = $this->card($project, 'backlog');
        $child->parent = $epic;
        $this->em()->flush();
        $this->workerRun($epic, 'breakdown', WorkerRunState::Running);
        $this->workerRun($epic, 'implement', WorkerRunState::Succeeded);
        $this->workerRun($child, 'fix', WorkerRunState::Queued);

        $childRun = $this->facts($child)->run;
        self::assertSame(['fix'], $childRun->activeWorkerKinds);
        self::assertSame(['breakdown'], $childRun->parentActiveKinds);

        $epicRun = $this->facts($epic)->run;
        self::assertSame(['breakdown'], $epicRun->activeWorkerKinds);
        self::assertSame([], $epicRun->parentActiveKinds);
    }

    public function test_a_pull_request_maps_its_forge_state(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('facts-pr-map');
        $card = $this->card($project, 'in-review');
        $pullRequest = $this->pullRequest($card, base: 'main', head: 'feature');
        $pullRequest->draft = true;
        $pullRequest->checks = PullRequestChecks::Failed;
        $pullRequest->mergeability = PullRequestMergeability::Conflicting;
        $pullRequest->review = PullRequestReview::ChangesRequested;
        $pullRequest->headSha = 'abc';
        $pullRequest->changesRequestedSha = 'abc';
        $this->em()->flush();

        self::assertEquals(new PullRequestFacts(
            state: PullRequestState::Open,
            draft: true,
            checks: ChecksState::Failed,
            conflicting: true,
            behind: false,
            approvalsCoveringHead: 0,
            changesRequested: true,
            baseIsMergeTarget: true,
            baseIsEpicBranch: false,
            stacked: false,
            parentMerged: false,
            closedAt: null,
        ), $this->facts($card)->pullRequest);

        $pullRequest->checks = PullRequestChecks::Pending;
        $pullRequest->mergeability = PullRequestMergeability::Behind;
        $pullRequest->review = PullRequestReview::Approved;
        $pullRequest->headSha = 'abc';
        $pullRequest->coveredSha = 'abc';
        $this->em()->flush();
        $facts = $this->facts($card)->pullRequest;
        self::assertNotNull($facts);
        self::assertSame(ChecksState::Pending, $facts->checks);
        self::assertFalse($facts->conflicting);
        self::assertTrue($facts->behind);
        self::assertFalse($facts->changesRequested);
        self::assertSame(1, $facts->approvalsCoveringHead);

        $pullRequest->checks = PullRequestChecks::Passed;
        $pullRequest->headSha = 'def';
        $this->em()->flush();
        $facts = $this->facts($card)->pullRequest;
        self::assertSame(ChecksState::Passed, $facts?->checks);
        self::assertSame(0, $facts->approvalsCoveringHead);

        $pullRequest->headSha = null;
        $pullRequest->coveredSha = null;
        $this->em()->flush();
        self::assertSame(0, $this->facts($card)->pullRequest?->approvalsCoveringHead);
    }

    public function test_changes_are_requested_only_when_a_changes_requested_review_covers_the_head(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('facts-pr-changes-requested');
        $card = $this->card($project, 'in-review');
        $pullRequest = $this->pullRequest($card);
        $pullRequest->review = PullRequestReview::ChangesRequested;
        $pullRequest->headSha = 'abc';
        $pullRequest->changesRequestedSha = 'abc';
        $this->em()->flush();
        self::assertTrue($this->changesRequested($card));

        $pullRequest->headSha = 'def';
        $this->em()->flush();
        self::assertFalse($this->changesRequested($card), 'A fix push leaves the review on an older commit.');

        $pullRequest->changesRequestedSha = null;
        $this->em()->flush();
        self::assertFalse($this->changesRequested($card), 'A review on an unknown commit does not cover the head.');

        $pullRequest->headSha = null;
        $this->em()->flush();
        self::assertFalse($this->changesRequested($card));

        $pullRequest->review = PullRequestReview::Approved;
        $pullRequest->headSha = 'abc';
        $pullRequest->changesRequestedSha = 'abc';
        $this->em()->flush();
        self::assertFalse($this->changesRequested($card), 'An approval after the change request ends it.');
    }

    public function test_a_finished_pull_request_closes_at_its_merge_or_its_last_read(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('facts-pr-closed');
        $card = $this->card($project, 'in-review');
        $merged = $this->pullRequest($card, state: ForgePullRequestState::Merged);
        $merged->mergedAt = new \DateTimeImmutable('2026-10-01 09:00:00');
        $merged->refreshedAt = new \DateTimeImmutable('2026-10-01 10:00:00');
        $closed = $this->pullRequest($card, state: ForgePullRequestState::Closed);
        $closed->refreshedAt = new \DateTimeImmutable('2026-10-01 11:00:00');
        $this->em()->flush();

        $facts = $this->facts($card)->pullRequests;

        self::assertCount(2, $facts);
        self::assertSame(PullRequestState::Merged, $facts[0]->state);
        self::assertEquals(new \DateTimeImmutable('2026-10-01 09:00:00'), $facts[0]->closedAt);
        self::assertSame(PullRequestState::Closed, $facts[1]->state);
        self::assertEquals(new \DateTimeImmutable('2026-10-01 11:00:00'), $facts[1]->closedAt);
    }

    public function test_the_primary_pull_request_is_the_newest_open_one_and_else_the_newest(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('facts-pr-primary');
        $card = $this->card($project, 'in-review');
        $older = $this->pullRequest($card, openedAt: '2026-09-01');
        $newest = $this->pullRequest($card, state: ForgePullRequestState::Closed, openedAt: '2026-09-03');
        $newerOpen = $this->pullRequest($card, openedAt: '2026-09-02');
        $newerOpen->draft = true;
        $this->em()->flush();

        $facts = $this->facts($card);
        self::assertCount(3, $facts->pullRequests);
        self::assertTrue($facts->pullRequest?->draft);

        $newerOpen->state = ForgePullRequestState::Merged;
        $older->state = ForgePullRequestState::Closed;
        $this->em()->flush();
        $pullRequests = $this->cardPullRequests();
        self::assertSame($newest, $pullRequests->primary($pullRequests->forCard($card)));
    }

    public function test_an_untracked_or_foreign_link_gives_no_pull_request(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('facts-pr-untracked');
        $card = $this->card($project, 'in-review');
        $this->em()->persist(new CardPullRequest($card, 'https://github.com/acme/widgets/pull/9', Forge::GitHub, self::REPOSITORY, 9));
        $this->em()->persist(new CardPullRequest($card, 'https://example.com/merge/1'));
        $this->em()->persist(new ForgePullRequest($this->workflowProject('facts-pr-foreign'), 'github', self::REPOSITORY, 9));
        $this->em()->flush();

        $facts = $this->facts($card);

        self::assertNull($facts->pullRequest);
        self::assertSame([], $facts->pullRequests);
    }

    public function test_a_pull_request_on_the_branch_of_the_open_epic_pull_request_targets_the_epic_and_is_not_stacked(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('facts-pr-epic');
        $epic = $this->card($project, 'in-progress', CardType::Epic);
        $this->pullRequest($epic, base: 'main', head: 'epic/42');
        $card = $this->card($project, 'in-review');
        $card->parent = $epic;
        $this->pullRequest($card, base: 'epic/42', head: 'feature');
        $this->em()->flush();

        $facts = $this->facts($card)->pullRequest;

        self::assertNotNull($facts);
        self::assertTrue($facts->baseIsEpicBranch);
        self::assertTrue($facts->baseIsMergeTarget);
        self::assertFalse($facts->stacked);
        self::assertFalse($facts->parentMerged);
    }

    public function test_a_closed_epic_pull_request_or_a_card_with_no_epic_does_not_make_an_epic_branch(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('facts-pr-no-epic');
        $epic = $this->card($project, 'in-progress', CardType::Epic);
        $epicPullRequest = $this->pullRequest($epic, state: ForgePullRequestState::Merged, base: 'main', head: 'epic/42');
        $child = $this->card($project, 'in-review');
        $child->parent = $epic;
        $this->pullRequest($child, base: 'epic/42', head: 'child');
        $orphan = $this->card($project, 'in-review');
        $this->pullRequest($orphan, base: 'epic/42', head: 'orphan');
        $this->em()->flush();

        foreach ([$child, $orphan] as $card) {
            $facts = $this->facts($card)->pullRequest;
            self::assertNotNull($facts);
            self::assertFalse($facts->baseIsEpicBranch);
            self::assertFalse($facts->baseIsMergeTarget);
            self::assertTrue($facts->stacked);
            self::assertTrue($facts->parentMerged);
        }

        $epicPullRequest->state = ForgePullRequestState::Open;
        $this->em()->flush();
        self::assertTrue($this->facts($child)->pullRequest?->baseIsEpicBranch);
        self::assertFalse($this->facts($orphan)->pullRequest?->baseIsEpicBranch);
    }

    public function test_stacking_needs_another_pull_request_of_the_same_repository_whose_head_is_the_base(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('facts-pr-stack');
        $card = $this->card($project, 'in-review');
        $self = $this->pullRequest($card, base: 'loop', head: 'loop');
        $other = $this->card($project, 'in-review');
        $this->pullRequest($other, base: 'main', head: 'release', repository: 'acme/other');
        $third = $this->card($project, 'in-review');
        $this->pullRequest($third, base: 'release', head: 'hotfix');
        $fourth = $this->card($project, 'in-review');
        $this->pullRequest($fourth, base: null, head: 'nowhere');
        $this->em()->flush();

        self::assertFalse($this->facts($card)->pullRequest?->stacked);
        $otherRepository = $this->facts($third)->pullRequest;
        self::assertNotNull($otherRepository);
        self::assertFalse($otherRepository->stacked);
        self::assertFalse($otherRepository->baseIsMergeTarget);
        $unknownBase = $this->facts($fourth)->pullRequest;
        self::assertNotNull($unknownBase);
        self::assertFalse($unknownBase->stacked);
        self::assertFalse($unknownBase->baseIsMergeTarget);

        $self->defaultBranch = 'loop';
        $this->em()->flush();
        self::assertTrue($this->facts($card)->pullRequest?->baseIsMergeTarget);
    }

    private function changesRequested(Card $card): bool
    {
        return ($this->facts($card)->pullRequest ?? throw new \LogicException('The card has a pull request.'))->changesRequested;
    }

    private function facts(Card $card): Facts
    {
        return $this->builder()->build($card, new \DateTimeImmutable('2026-10-02 12:00:00'));
    }

    /** Built by hand, because no production service injects it yet and the container drops it. */
    private function builder(): FactsBuilder
    {
        return new FactsBuilder(
            $this->service(WorkflowSlotLinkRepository::class),
            $this->service(CardRepository::class),
            $this->service(CardDocumentRepository::class),
            $this->cardPullRequests(),
            $this->service(ForgePullRequestRepository::class),
            $this->service(WorkRequestRepository::class),
            $this->service(WorkerRunRepository::class),
            new FactProviders([$this->provider]),
            $this->em()->getConnection(),
        );
    }

    private function workerRun(Card $card, string $workKind, WorkerRunState $state): void
    {
        $this->em()->persist(new WorkerRun(
            project: $card->project,
            bridgeId: Uuid::v7(),
            cardId: $card->id ?? throw new \LogicException('A flushed card has an id.'),
            cardNumber: $card->number,
            workKind: $workKind,
            state: $state,
        ));
        $this->em()->flush();
    }

    private function cardPullRequests(): CardPullRequests
    {
        return new CardPullRequests($this->service(CardPullRequestRepository::class), $this->service(ForgePullRequestRepository::class));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    private function card(Project $project, string $column, CardType $type = CardType::Feature): Card
    {
        $card = new Card($project, $this->column($project, $column), 'Card', '', ++$this->cardNumber, $type);
        $this->em()->persist($card);
        $this->em()->flush();

        return $card;
    }

    private function pullRequest(
        Card $card,
        ForgePullRequestState $state = ForgePullRequestState::Open,
        ?string $base = 'main',
        ?string $head = null,
        string $repository = self::REPOSITORY,
        ?string $openedAt = null,
    ): ForgePullRequest {
        $number = ++$this->pullRequestNumber;
        $this->em()->persist(new CardPullRequest($card, 'https://github.com/'.$repository.'/pull/'.$number, Forge::GitHub, $repository, $number));
        $pullRequest = new ForgePullRequest($card->project, 'github', $repository, $number);
        $pullRequest->state = $state;
        $pullRequest->baseBranch = $base;
        $pullRequest->headBranch = $head ?? 'branch-'.$number;
        $pullRequest->defaultBranch = 'main';
        $pullRequest->openedAt = null === $openedAt ? null : new \DateTimeImmutable($openedAt);
        $this->em()->persist($pullRequest);
        $this->em()->flush();

        return $pullRequest;
    }

    /** @param list<string> $tags */
    private function document(Card $card, DocumentStatus $status, array $tags, bool $archived = false): string
    {
        $document = new Document($card->project->owner, $card->project, 'Design');
        $document->status = $status;
        if ($archived) {
            $document->archivedAt = new \DateTimeImmutable();
        }
        foreach ($tags as $name) {
            $key = $card->project->id.'/'.$name;
            if (!isset($this->tags[$key])) {
                $this->tags[$key] = new Tag($card->project, $name);
                $this->em()->persist($this->tags[$key]);
            }
            $document->tags->add($this->tags[$key]);
        }
        $this->em()->persist($document);
        $this->em()->persist(new CardDocument($card, $document));
        $this->em()->flush();

        return ($document->id ?? throw new \LogicException('A flushed document has an id.'))->toRfc4122();
    }

    private function workRequest(Card $card, string $kind, WorkRequestState $state, ?string $reason = null, ?string $settledAt = null): void
    {
        $request = new WorkRequest($card->project, $card->id ?? throw new \LogicException('A flushed card has an id.'), $card->number, $kind, null, 'rule', new \DateTimeImmutable('2026-10-02 09:00:00'));
        $request->state = $state;
        $request->reason = $reason;
        $request->settledAt = null === $settledAt ? null : new \DateTimeImmutable($settledAt);
        $this->em()->persist($request);
        $this->em()->flush();
    }
}
