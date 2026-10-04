<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\EventListener;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardLink;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardReporter;
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
use App\Module\Board\Service\BoardAvailability;
use App\Module\Board\Service\CardMove;
use App\Module\Bridge\Event\CardHoldsReleased;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Event\PullRequestStateChanged;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Event\DocumentStatusChanged;
use App\Module\Review\Event\ReviewSubmitted;
use App\Module\Workflow\EventListener\BaselineCardsOnCardHoldsReleased;
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
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Module\Workflow\Repository\WorkflowPendingBaselineRepository;
use App\Module\Workflow\Service\EvaluationTrigger;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/** Each listener runs with an engine that is on, and the test reads the evaluations that reached the async transport. */
final class EvaluationListenersTest extends KernelTestCase
{
    use ActionScenario;

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
        $listener = new EvaluateCardsOnPullRequestStateChanged($this->service(CardPullRequestRepository::class), $this->trigger());

        $listener(new PullRequestStateChanged($pullRequest, $pullRequest->snapshot(), $pullRequest->snapshot()));
        $listener(new PullRequestStateChanged($unknown, $unknown->snapshot(), $unknown->snapshot()));

        self::assertEqualsCanonicalizing($this->ids($one, $two), $this->sent());
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

        new EvaluateCardOnWorkRequestChanged($events, $this->trigger())(new WorkRequestChanged($this->projectId(), $cardId, Uuid::v7(), WorkRequestState::Open));

        self::assertSame($this->ids($card), $this->sent());
        self::assertCount(1, $changed);
        self::assertSame([$this->projectId()->toRfc4122(), $cardId->toRfc4122(), CardChanged::UPDATED, false], [
            $changed[0]->projectId->toRfc4122(),
            $changed[0]->cardId->toRfc4122(),
            $changed[0]->change,
            $changed[0]->contentChanged,
        ]);
    }

    public function test_a_release_marks_the_existing_cards_for_a_baseline_and_asks_for_them(): void
    {
        [$one, $two] = [$this->card($this->project, 'next'), $this->card($this->project, 'next')];
        $event = new CardHoldsReleased($this->projectId(), [$one->id ?? throw new \LogicException('A flushed card has an id.'), $two->id ?? throw new \LogicException('A flushed card has an id.'), Uuid::v7()]);

        $this->baselineListener()($event);
        $this->baselineListener()($event);

        self::assertEqualsCanonicalizing($this->ids($one, $two), $this->pendingBaselines());
        $ids = $this->ids($one, $two);
        self::assertSame([...$ids, ...$ids], array_values(array_filter($this->sent(), static fn (string $id): bool => \in_array($id, $ids, true))));
    }

    private function baselineListener(): BaselineCardsOnCardHoldsReleased
    {
        return new BaselineCardsOnCardHoldsReleased(
            $this->service(WorkflowPendingBaselineRepository::class),
            $this->trigger(),
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
        return new EvaluationTrigger($this->service(MessageBusInterface::class), $this->service(BoardAvailability::class));
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
