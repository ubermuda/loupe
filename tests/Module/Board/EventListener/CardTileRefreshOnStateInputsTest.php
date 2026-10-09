<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Entity\CardLink;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Event\CardBlockersRemoved;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Event\CardMoved;
use App\Module\Board\EventListener\DispatchCardChangedOnBlockerMoved;
use App\Module\Board\EventListener\DispatchCardChangedOnCardBlockersRemoved;
use App\Module\Board\EventListener\DispatchCardChangedOnDocumentRenamed;
use App\Module\Board\EventListener\DispatchCardChangedOnDocumentStatusChanged;
use App\Module\Board\EventListener\DispatchCardChangedOnReviewSubmitted;
use App\Module\Board\EventListener\DispatchCardChangedOnWorkerRunChanged;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\CardMove;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Event\DocumentRenamed;
use App\Module\Review\Event\DocumentStatusChanged;
use App\Module\Review\Event\ReviewSubmitted;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Board\CardStateFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Uid\Uuid;

/** Each input of a card state that no event announced to the board reaches the open boards through CardChanged. */
final class CardTileRefreshOnStateInputsTest extends KernelTestCase
{
    use CardStateFixtures;

    /** @var list<CardChanged> */
    private array $changes = [];

    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addListener(CardChanged::class, function (CardChanged $event): void {
            $this->changes[] = $event;
        });
    }

    public function test_a_run_that_changes_redraws_each_card_it_is_about(): void
    {
        $projectId = Uuid::v7();
        $first = Uuid::v7();
        $second = Uuid::v7();

        new DispatchCardChangedOnWorkerRunChanged($this->dispatcher)(new WorkerRunChanged($projectId, [$first->toRfc4122(), $second->toRfc4122()], [Uuid::v7()->toRfc4122()]));

        self::assertSame([$first->toRfc4122(), $second->toRfc4122()], $this->changedCardIds());
        self::assertTrue($projectId->equals($this->changes[0]->projectId));
        self::assertSame(CardChanged::UPDATED, $this->changes[0]->change);
    }

    public function test_a_document_status_change_redraws_the_cards_that_link_the_document(): void
    {
        $project = $this->stateProject('refresh-document');
        $card = $this->stateCard($project, 'tech-design');
        $other = $this->stateCard($project, 'tech-design');
        $document = $this->reviewDocument($card);

        new DispatchCardChangedOnDocumentStatusChanged($this->service(CardDocumentRepository::class), $this->dispatcher)(new DocumentStatusChanged($project->id ?? Uuid::v7(), $document->id ?? Uuid::v7()));

        self::assertSame([(string) $card->id], $this->changedCardIds());
        self::assertNotContains((string) $other->id, $this->changedCardIds());
    }

    public function test_a_rename_redraws_the_cards_that_link_the_document(): void
    {
        $project = $this->stateProject('refresh-rename');
        $card = $this->stateCard($project, 'tech-design');
        $other = $this->stateCard($project, 'tech-design');
        $document = $this->reviewDocument($card);

        new DispatchCardChangedOnDocumentRenamed($this->service(CardDocumentRepository::class), $this->dispatcher)(new DocumentRenamed($project->id ?? Uuid::v7(), $document->id ?? Uuid::v7()));

        self::assertSame([(string) $card->id], $this->changedCardIds());
        self::assertNotContains((string) $other->id, $this->changedCardIds());
    }

    public function test_a_verdict_redraws_the_cards_that_link_the_document(): void
    {
        $project = $this->stateProject('refresh-verdict');
        $card = $this->stateCard($project, 'tech-design');
        $document = $this->reviewDocument($card);
        $review = new Review($document->versions->first() ?: throw new \LogicException('The document has a version.'), Verdict::Approved, $project->owner);

        new DispatchCardChangedOnReviewSubmitted($this->service(CardDocumentRepository::class), $this->dispatcher)(new ReviewSubmitted($review));

        self::assertSame([(string) $card->id], $this->changedCardIds());
    }

    public function test_a_blocker_that_enters_a_terminal_column_redraws_the_card_it_blocks(): void
    {
        $project = $this->stateProject('refresh-blocker');
        $blocked = $this->stateCard($project, 'tech-design');
        $blocker = $this->stateCard($project, 'in-progress');
        $this->em()->persist(new CardLink($blocker, $blocked, CardLinkKind::Blocks));
        $this->em()->flush();
        $from = $blocker->column;
        $blocker->column = $this->column($project, 'done');
        $this->em()->flush();

        $this->blockerMoved()(new CardMoved($blocker, new CardMove($from), Actor::Human));

        self::assertSame([(string) $blocked->id], $this->changedCardIds());
    }

    public function test_a_blocker_that_leaves_a_terminal_column_redraws_the_card_it_blocks(): void
    {
        $project = $this->stateProject('refresh-blocker-reopened');
        $blocked = $this->stateCard($project, 'tech-design');
        $blocker = $this->stateCard($project, 'done');
        $this->em()->persist(new CardLink($blocker, $blocked, CardLinkKind::Blocks));
        $this->em()->flush();
        $from = $blocker->column;
        $blocker->column = $this->column($project, 'in-progress');
        $this->em()->flush();

        $this->blockerMoved()(new CardMoved($blocker, new CardMove($from), Actor::Human));

        self::assertSame([(string) $blocked->id], $this->changedCardIds());
    }

    public function test_a_blocker_that_moves_between_open_columns_redraws_nothing(): void
    {
        $project = $this->stateProject('refresh-blocker-open');
        $blocked = $this->stateCard($project, 'tech-design');
        $blocker = $this->stateCard($project, 'in-progress');
        $this->em()->persist(new CardLink($blocker, $blocked, CardLinkKind::Blocks));
        $this->em()->flush();
        $from = $blocker->column;
        $blocker->column = $this->column($project, 'next');
        $this->em()->flush();

        $this->blockerMoved()(new CardMoved($blocker, new CardMove($from), Actor::Human));

        self::assertSame([], $this->changes);
    }

    public function test_cards_that_lose_a_blocker_are_redrawn(): void
    {
        $project = $this->stateProject('refresh-blockers-removed');
        $first = $this->stateCard($project);
        $second = $this->stateCard($project);

        new DispatchCardChangedOnCardBlockersRemoved($this->dispatcher)(new CardBlockersRemoved($project, [$first, $second], Actor::Human));

        self::assertSame([(string) $first->id, (string) $second->id], $this->changedCardIds());
    }

    private function blockerMoved(): DispatchCardChangedOnBlockerMoved
    {
        return new DispatchCardChangedOnBlockerMoved($this->service(CardRepository::class), $this->dispatcher);
    }

    /** @return list<string> */
    private function changedCardIds(): array
    {
        return array_map(static fn (CardChanged $change): string => $change->cardId->toRfc4122(), $this->changes);
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
}
