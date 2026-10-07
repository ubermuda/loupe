<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\EventListener;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardLink;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Event\BoardColumnDeleted;
use App\Module\Board\Event\BoardColumnTerminalChanged;
use App\Module\Board\Event\CardBlockersRemoved;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Event\CardMoved;
use App\Module\Board\Event\CardParentChanged;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\CardMove;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Event\CardHoldsReleased;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Event\PullRequestStateChanged;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Event\DocumentStatusChanged;
use App\Module\Review\Event\ReviewSubmitted;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\EventListener\EvaluateCardOnWorkRequestChanged;
use App\Module\Workflow\EventListener\EvaluateCardsOnBoardColumnDeleted;
use App\Module\Workflow\EventListener\EvaluateCardsOnBoardColumnTerminalChanged;
use App\Module\Workflow\EventListener\EvaluateCardsOnCardBlockersRemoved;
use App\Module\Workflow\EventListener\EvaluateCardsOnCardMoved;
use App\Module\Workflow\EventListener\EvaluateCardsOnCardParentChanged;
use App\Module\Workflow\EventListener\EvaluateCardsOnDocumentStatusChanged;
use App\Module\Workflow\EventListener\EvaluateCardsOnPullRequestStateChanged;
use App\Module\Workflow\EventListener\EvaluateCardsOnReviewSubmitted;
use App\Module\Workflow\EventListener\EvaluateCardsOnWorkerRunChanged;
use App\Module\Workflow\EventListener\RearmCardsOnCardHoldsReleased;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Module\Workflow\Repository\WorkflowPendingBaselineRepository;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Module\Workflow\Service\EvaluationTrigger;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/** Each listener runs with an engine that is on, and the test reads the evaluations that reached the async transport. */
final class EvaluationListenersTest extends KernelTestCase
{
    use ActionScenario;

    private const string RELEASED_AT = '2026-10-02 12:00:00';

    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->project = $this->workflowProject('listeners');
        $this->transport()->reset();
    }

    public function test_a_move_asks_for_the_card_its_epic_and_the_cards_it_blocks(): void
    {
        $epic = $this->card($this->project, 'next');
        $card = $this->card($this->project, 'next');
        $blocked = $this->card($this->project, 'next');
        $this->card($this->project, 'next');
        $card->parent = $epic;
        $this->em()->persist(new CardLink($card, $blocked, CardLinkKind::Blocks));
        $this->em()->flush();

        new EvaluateCardsOnCardMoved($this->service(CardRepository::class), $this->trigger())(
            new CardMoved($card, new CardMove($this->column($this->project, 'in-progress')), CardReporter::Human),
        );

        self::assertSame($this->ids($card, $epic, $blocked), $this->sent());
    }

    public function test_a_parent_change_asks_for_the_card_and_both_parents(): void
    {
        [$card, $old, $new] = [$this->card($this->project, 'next'), $this->card($this->project, 'next'), $this->card($this->project, 'next')];

        new EvaluateCardsOnCardParentChanged($this->trigger())(new CardParentChanged($card, $old, $new, CardReporter::Human));
        new EvaluateCardsOnCardParentChanged($this->trigger())(new CardParentChanged($card, null, $new, CardReporter::Human));

        self::assertSame([...$this->ids($card, $old, $new), ...$this->ids($card, $new)], $this->sent());
    }

    public function test_a_blocker_removal_asks_for_the_cards_that_lost_it(): void
    {
        [$one, $two] = [$this->card($this->project, 'next'), $this->card($this->project, 'next')];

        new EvaluateCardsOnCardBlockersRemoved($this->trigger())(new CardBlockersRemoved($this->project, [$one, $two], CardReporter::Human));

        self::assertSame($this->ids($one, $two), $this->sent());
    }

    public function test_column_events_ask_for_their_cards(): void
    {
        [$one, $two] = [$this->card($this->project, 'next'), $this->card($this->project, 'next')];

        new EvaluateCardsOnBoardColumnTerminalChanged($this->trigger())(new BoardColumnTerminalChanged($this->project, 'column', true, $this->ids($one, $two), CardReporter::Human));
        new EvaluateCardsOnBoardColumnDeleted($this->trigger())(new BoardColumnDeleted($this->project, 'column', 'review', 'backlog', $this->ids($two), CardReporter::Human, false, false));

        self::assertSame([...$this->ids($one, $two), ...$this->ids($two)], $this->sent());
    }

    public function test_document_events_ask_for_the_linked_cards(): void
    {
        [$one, $two] = [$this->card($this->project, 'next'), $this->card($this->project, 'next')];
        $this->card($this->project, 'next');
        $document = new Document($this->project->owner, $this->project, 'Design');
        $version = $document->addVersion('# One', '<h1>One</h1>');
        $this->em()->persist($document);
        $this->em()->persist(new CardDocument($one, $document));
        $this->em()->persist(new CardDocument($two, $document));
        $this->em()->flush();
        $documentId = $document->id ?? throw new \LogicException('A flushed document has an id.');

        new EvaluateCardsOnDocumentStatusChanged($this->service(CardDocumentRepository::class), $this->trigger())(new DocumentStatusChanged($this->projectId(), $documentId));
        $statusSent = $this->sent();
        $this->transport()->reset();
        new EvaluateCardsOnReviewSubmitted($this->service(CardDocumentRepository::class), $this->trigger())(new ReviewSubmitted(new Review($version, Verdict::Approved, $this->project->owner)));

        self::assertEqualsCanonicalizing($this->ids($one, $two), $statusSent);
        self::assertEqualsCanonicalizing($this->ids($one, $two), $this->sent());
    }

    public function test_a_pull_request_change_asks_for_every_card_that_links_it_on_any_known_forge(): void
    {
        [$one, $two] = [$this->card($this->project, 'next'), $this->card($this->project, 'next')];
        $this->em()->persist(new CardPullRequest($one, 'https://gitlab.com/acme/widgets/-/merge_requests/5', Forge::GitLab, 'Acme/Widgets', 5));
        $this->em()->persist(new CardPullRequest($two, 'https://gitlab.com/acme/widgets/-/merge_requests/5', Forge::GitLab, 'acme/widgets', 5));
        $this->em()->persist(new CardPullRequest($two, 'https://github.com/acme/widgets/pull/5', Forge::GitHub, 'acme/widgets', 5));
        $pullRequest = new ForgePullRequest($this->project, 'gitlab', 'acme/widgets', 5);
        $unknown = new ForgePullRequest($this->project, 'gitea', 'acme/widgets', 5);
        $this->em()->persist($pullRequest);
        $this->em()->persist($unknown);
        $this->em()->flush();
        $listener = new EvaluateCardsOnPullRequestStateChanged($this->service(CardPullRequestRepository::class), $this->service(CardRepository::class), $this->trigger());

        $listener(new PullRequestStateChanged($pullRequest, $pullRequest->snapshot(), $pullRequest->snapshot()));
        $listener(new PullRequestStateChanged($unknown, $unknown->snapshot(), $unknown->snapshot()));

        self::assertEqualsCanonicalizing($this->ids($one, $two), $this->sent());
    }

    public function test_a_pull_request_change_asks_for_the_epic_of_a_child_and_the_children_of_an_epic(): void
    {
        $epic = $this->card($this->project, 'next');
        $epic->type = CardType::Epic;
        [$child, $sibling] = [$this->card($this->project, 'next'), $this->card($this->project, 'next')];
        $child->parent = $epic;
        $sibling->parent = $epic;
        $this->em()->persist(new CardPullRequest($child, 'https://github.com/acme/widgets/pull/7', Forge::GitHub, 'acme/widgets', 7));
        $this->em()->persist(new CardPullRequest($epic, 'https://github.com/acme/widgets/pull/8', Forge::GitHub, 'acme/widgets', 8));
        $childPullRequest = new ForgePullRequest($this->project, 'github', 'acme/widgets', 7);
        $epicPullRequest = new ForgePullRequest($this->project, 'github', 'acme/widgets', 8);
        $this->em()->persist($childPullRequest);
        $this->em()->persist($epicPullRequest);
        $this->em()->flush();
        $listener = new EvaluateCardsOnPullRequestStateChanged($this->service(CardPullRequestRepository::class), $this->service(CardRepository::class), $this->trigger());

        $listener(new PullRequestStateChanged($childPullRequest, $childPullRequest->snapshot(), $childPullRequest->snapshot()));
        self::assertEqualsCanonicalizing($this->ids($child, $epic), $this->sent());

        $this->transport()->reset();
        $listener(new PullRequestStateChanged($epicPullRequest, $epicPullRequest->snapshot(), $epicPullRequest->snapshot()));
        self::assertEqualsCanonicalizing($this->ids($epic, $child, $sibling), $this->sent());
    }

    public function test_a_run_change_asks_for_its_cards(): void
    {
        [$one, $two] = [$this->card($this->project, 'next'), $this->card($this->project, 'next')];

        new EvaluateCardsOnWorkerRunChanged($this->trigger())(new WorkerRunChanged($this->projectId(), $this->ids($one, $two), []));

        self::assertSame($this->ids($one, $two), $this->sent());
    }

    public function test_a_work_request_change_asks_for_its_card_and_updates_the_card_face(): void
    {
        $card = $this->card($this->project, 'next');
        $cardId = $card->id ?? throw new \LogicException('A flushed card has an id.');
        $events = new EventDispatcher();
        $changed = [];
        $events->addListener(CardChanged::class, static function (CardChanged $event) use (&$changed): void {
            $changed[] = $event;
        });

        new EvaluateCardOnWorkRequestChanged($events, $this->trigger())(new WorkRequestChanged($this->projectId(), WorkSubject::CARD, $cardId, Uuid::v7(), WorkRequestState::Open));

        self::assertSame($this->ids($card), $this->sent());
        self::assertCount(1, $changed);
        self::assertSame([$this->projectId()->toRfc4122(), $cardId->toRfc4122(), CardChanged::UPDATED, false], [
            $changed[0]->projectId->toRfc4122(),
            $changed[0]->cardId->toRfc4122(),
            $changed[0]->change,
            $changed[0]->contentChanged,
        ]);
    }

    public function test_a_release_resets_the_rules_restarts_the_clock_of_the_open_requests_and_asks_for_the_cards(): void
    {
        [$one, $two, $other] = [$this->card($this->project, 'next'), $this->card($this->project, 'next'), $this->card($this->project, 'next')];
        $state = new WorkflowRuleState($one, $this->project, 'work', new \DateTimeImmutable('2026-10-02 09:00:00'));
        $state->truth = true;
        $state->attempts = 2;
        $state->fires = 3;
        $state->fingerprint = 'facts';
        $state->dueAt = new \DateTimeImmutable('2026-10-02 13:00:00');
        $state->lastRefusal = 'refused';
        $state->lastRefusalAt = new \DateTimeImmutable('2026-10-02 11:00:00');
        $subject = Uuid::v7();
        $state->subjectPullRequestId = $subject;
        $untouched = new WorkflowRuleState($other, $this->project, 'work', new \DateTimeImmutable('2026-10-02 09:00:00'));
        $untouched->truth = true;
        $open = $this->workRequest($one, 'open');
        $claimed = $this->workRequest($two, 'claimed');
        $claimed->state = WorkRequestState::Claimed;
        $otherOpen = $this->workRequest($other, 'other');
        $this->em()->persist($state);
        $this->em()->persist($untouched);
        $this->em()->flush();
        $this->service(WorkflowPendingBaselineRepository::class)->markCards($this->projectId(), [$one->id ?? throw new \LogicException('A flushed card has an id.'), $other->id ?? throw new \LogicException('A flushed card has an id.')]);

        $this->rearmListener()(new CardHoldsReleased($this->projectId(), [$one->id ?? throw new \LogicException('A flushed card has an id.'), $two->id ?? throw new \LogicException('A flushed card has an id.')]));

        $this->em()->refresh($state);
        $this->em()->refresh($untouched);
        $this->em()->refresh($open);
        $this->em()->refresh($claimed);
        $this->em()->refresh($otherOpen);
        self::assertSame(
            [false, 0, 0, 'facts', null, null, null, $subject->toRfc4122(), self::RELEASED_AT],
            [$state->truth, $state->attempts, $state->fires, $state->fingerprint, $state->dueAt, $state->lastRefusal, $state->lastRefusalAt, $state->subjectPullRequestId?->toRfc4122(), $state->updatedAt->format('Y-m-d H:i:s')],
        );
        self::assertTrue($untouched->truth);
        self::assertSame(self::RELEASED_AT, $open->reopenedAt?->format('Y-m-d H:i:s'));
        self::assertNull($claimed->reopenedAt);
        self::assertNull($otherOpen->reopenedAt);
        self::assertSame($this->ids($other), $this->pendingBaselines());
        self::assertSame($this->ids($one, $two), $this->sent());
    }

    private function workRequest(Card $card, string $kind): WorkRequest
    {
        $request = new WorkRequest($this->project, WorkSubject::CARD, $card->id ?? throw new \LogicException('A flushed card has an id.'), $card->number, $kind, null, $kind, new \DateTimeImmutable('2026-10-02 09:00:00'));
        $this->em()->persist($request);

        return $request;
    }

    private function rearmListener(): RearmCardsOnCardHoldsReleased
    {
        return new RearmCardsOnCardHoldsReleased(
            $this->service(WorkflowRuleStateRepository::class),
            $this->service(WorkRequestRepository::class),
            $this->service(WorkflowPendingBaselineRepository::class),
            $this->trigger(),
            new MockClock(self::RELEASED_AT),
        );
    }

    /** @return list<string> */
    private function pendingBaselines(): array
    {
        return array_map(strval(...), $this->em()->getConnection()->fetchFirstColumn(
            'SELECT card_id FROM workflow_pending_baselines WHERE project_id = :project',
            ['project' => $this->projectId()->toRfc4122()],
        ));
    }

    private function trigger(): EvaluationTrigger
    {
        return new EvaluationTrigger($this->service(MessageBusInterface::class));
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /** @return list<string> the card ids of the queued evaluations, in order */
    private function sent(): array
    {
        $ids = [];
        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof EvaluateCard) {
                $ids[] = $message->cardId;
            }
        }

        return $ids;
    }

    /** @return list<string> */
    private function ids(Card ...$cards): array
    {
        return array_values(array_map(static fn (Card $card): string => ($card->id ?? throw new \LogicException('A flushed card has an id.'))->toRfc4122(), $cards));
    }

    private function projectId(): Uuid
    {
        return $this->project->id ?? throw new \LogicException('A flushed project has an id.');
    }
}
