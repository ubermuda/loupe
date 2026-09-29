<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitEndReason;
use App\Module\Inbox\Entity\InboxCardWaitTrigger;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemDocument;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxProjectSettings;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Inbox\Service\CardWaitReconciler;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\SetDocumentTagsCommand;
use App\Module\Review\Command\SetDocumentTagsHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CardWaitReconcilerTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private CardWaitReconciler $reconciler;
    private Project $project;
    private Card $card;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $reconciler = self::getContainer()->get(CardWaitReconciler::class);
        self::assertInstanceOf(CardWaitReconciler::class, $reconciler);
        $this->reconciler = $reconciler;

        $this->project = $this->project($em, $this->owner($em, 'card-waits'), 'card-waits');
        $this->card = $this->card($em, $this->project, 12);
        $em->flush();
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);
    }

    public function test_a_linked_document_in_review_opens_one_wait_item_held_by_a_loupe_ask(): void
    {
        $document = $this->linkedDocument('Tech design');

        $this->reconcile();

        $watch = $this->onlyWatch();
        $item = $watch->item;
        self::assertSame(InboxItemKind::Wait, $item->kind);
        self::assertTrue($item->blocking);
        self::assertSame(InboxItemState::Open, $item->state);
        self::assertSame('#12 Ship it', $item->title);
        self::assertSame('Tech design in review, version 1', $item->body);
        self::assertSame($this->project->searchLanguage, $item->searchLanguage);
        self::assertSame([$this->card], array_map(static fn (InboxItemCard $link): Card => $link->card, array_values($item->cards->toArray())));
        self::assertSame([$document], array_map(static fn (InboxItemDocument $link): Document => $link->document, array_values($item->documents->toArray())));
        self::assertEquals($this->card->id, $watch->cardId);
        self::assertSame(12, $watch->cardNumber);
        self::assertNull($watch->closedAt);

        $ask = $this->onlyAskHolding($item);
        self::assertSame(InboxAskOrigin::Loupe, $ask->origin);
        self::assertNull($ask->sessionId);
        self::assertNull($ask->bridgeId);
        self::assertNull($ask->context);
        self::assertNull($ask->closedAt);

        $wait = $this->onlyWait($watch);
        self::assertEquals($document->id, $wait->documentId);
        self::assertSame(1, $wait->versionNumber);
        self::assertNull($wait->endedAt);
        self::assertSame(1, $this->searchHits('Tech'));
    }

    public function test_a_second_document_adds_a_wait_to_the_same_item(): void
    {
        $this->linkedDocument('Tech design');
        $this->reconcile();
        $this->linkedDocument('Product design');

        $this->reconcile();

        $watch = $this->onlyWatch();
        self::assertCount(2, $this->openWaits($watch));
        self::assertSame("Tech design in review, version 1\nProduct design in review, version 1", $watch->item->body);
        self::assertCount(2, $watch->item->documents);
        self::assertSame(1, $this->searchHits('Product'));
    }

    public function test_a_new_version_replaces_the_wait_and_keeps_the_item_open(): void
    {
        $document = $this->linkedDocument('Tech design');
        $this->reconcile();
        $document->addVersion('# Two', '<h1>Two</h1>');
        $this->em->flush();

        $this->reconcile();

        $watch = $this->onlyWatch();
        self::assertSame(InboxItemState::Open, $watch->item->state);
        self::assertSame('Tech design in review, version 2', $watch->item->body);
        [$old, $new] = $this->sortedWaits($watch);
        self::assertSame(1, $old->versionNumber);
        self::assertSame(InboxCardWaitEndReason::Resolved, $old->endReason);
        self::assertNotNull($old->endedAt);
        self::assertSame(2, $new->versionNumber);
        self::assertNull($new->endedAt);
        self::assertCount(1, $watch->item->documents);
    }

    public function test_an_approval_closes_the_item_done_and_its_ask(): void
    {
        $document = $this->linkedDocument('Tech design');
        $this->reconcile();
        $document->status = DocumentStatus::Approved;
        $this->em->flush();

        $this->reconcile();

        $watch = $this->onlyWatch();
        self::assertSame(InboxItemState::Done, $watch->item->state);
        self::assertNotNull($watch->item->closedAt);
        self::assertNotNull($watch->closedAt);
        self::assertSame(InboxCardWaitEndReason::Resolved, $this->onlyWait($watch)->endReason);
        self::assertNotNull($this->onlyAskHolding($watch->item)->closedAt);
    }

    public function test_an_archive_closes_the_item_done(): void
    {
        $document = $this->linkedDocument('Tech design');
        $this->reconcile();
        $document->archivedAt = new \DateTimeImmutable();
        $this->em->flush();

        $this->reconcile();

        $watch = $this->onlyWatch();
        self::assertSame(InboxItemState::Done, $watch->item->state);
        self::assertSame(InboxCardWaitEndReason::Resolved, $this->onlyWait($watch)->endReason);
    }

    public function test_a_card_in_a_terminal_column_closes_the_item_obsolete(): void
    {
        $this->linkedDocument('Tech design');
        $this->reconcile();
        $this->card->column = $this->column($this->project, 'done');
        $this->em->flush();

        $this->reconcile();

        $watch = $this->onlyWatch();
        self::assertSame(InboxItemState::Obsolete, $watch->item->state);
        self::assertNotNull($watch->closedAt);
        self::assertSame(InboxCardWaitEndReason::CardFinished, $this->onlyWait($watch)->endReason);
        self::assertNotNull($this->onlyAskHolding($watch->item)->closedAt);
    }

    public function test_a_deleted_card_closes_the_item_obsolete_and_the_watch_survives(): void
    {
        $this->linkedDocument('Tech design');
        $this->reconcile();
        $cardId = $this->card->id;
        self::assertNotNull($cardId);
        $projectId = $this->project->id;
        $this->em->remove($this->card);
        $this->em->flush();
        $this->em->clear();
        $project = $this->em->find(Project::class, $projectId);
        self::assertInstanceOf(Project::class, $project);

        $this->reconciler->reconcile($project, [(string) $cardId]);

        $watch = $this->onlyWatch($cardId);
        self::assertSame(InboxItemState::Obsolete, $watch->item->state);
        self::assertNotNull($watch->closedAt);
        self::assertSame(InboxCardWaitEndReason::CardDeleted, $this->onlyWait($watch)->endReason);
    }

    public function test_an_open_agent_review_of_the_document_suppresses_the_wait(): void
    {
        $document = $this->linkedDocument('Tech design');
        $this->agentReviewOf($document);

        $this->reconcile();

        self::assertSame([], $this->watches());
    }

    public function test_an_agent_review_that_arrives_later_closes_the_item_done(): void
    {
        $document = $this->linkedDocument('Tech design');
        $this->reconcile();
        $this->agentReviewOf($document);

        $this->reconcile();

        $watch = $this->onlyWatch();
        self::assertSame(InboxItemState::Done, $watch->item->state);
        self::assertSame(InboxCardWaitEndReason::Resolved, $this->onlyWait($watch)->endReason);
    }

    public function test_a_dismissed_version_stays_dismissed_and_a_new_version_opens_a_new_item(): void
    {
        $document = $this->linkedDocument('Tech design');
        $this->reconcile();
        $dismissed = $this->onlyWatch();
        $now = new \DateTimeImmutable();
        $dismissed->item->state = InboxItemState::Declined;
        $dismissed->item->closedAt = $now;
        $dismissed->dismissedAt = $now;
        $this->em->flush();

        $this->reconcile();

        self::assertSame([$dismissed], $this->watches());
        self::assertNotNull($dismissed->closedAt);
        self::assertSame(InboxCardWaitEndReason::Dismissed, $this->onlyWait($dismissed)->endReason);

        $document->addVersion('# Two', '<h1>Two</h1>');
        $this->em->flush();
        $this->reconcile();

        $watches = $this->watches();
        self::assertCount(2, $watches);
        self::assertSame($dismissed, $watches[0]);
        self::assertSame(InboxItemState::Open, $watches[1]->item->state);
        self::assertSame(2, $this->onlyWait($watches[1])->versionNumber);
    }

    public function test_a_dismissal_leaves_a_wait_that_had_already_ended_free_to_open_again(): void
    {
        $approved = $this->linkedDocument('Tech design');
        $this->linkedDocument('Product design');
        $this->reconcile();
        $approved->status = DocumentStatus::Approved;
        $this->em->flush();
        $this->reconcile();
        $dismissed = $this->onlyWatch();
        $now = new \DateTimeImmutable();
        $dismissed->item->state = InboxItemState::Declined;
        $dismissed->item->closedAt = $now;
        $dismissed->dismissedAt = $now;
        $this->em->flush();
        $this->reconcile();

        $approved->status = DocumentStatus::InReview;
        $this->em->flush();
        $this->reconcile();

        $watches = $this->watches();
        self::assertCount(2, $watches);
        self::assertSame($dismissed, $watches[0]);
        self::assertSame('Tech design in review, version 1', $watches[1]->item->body);
    }

    public function test_an_item_closed_by_another_path_frees_the_card_for_a_new_item(): void
    {
        $this->linkedDocument('Tech design');
        $this->reconcile();
        $closed = $this->onlyWatch();
        $closed->item->state = InboxItemState::Obsolete;
        $closed->item->closedAt = new \DateTimeImmutable();
        $this->em->flush();

        $this->reconcile();

        $watches = $this->watches();
        self::assertCount(2, $watches);
        self::assertNotNull($closed->closedAt);
        self::assertNull($closed->dismissedAt);
        self::assertSame(InboxCardWaitEndReason::Resolved, $this->onlyWait($closed)->endReason);
        self::assertSame(InboxItemState::Open, $watches[1]->item->state);
        self::assertNull($watches[1]->closedAt);
    }

    public function test_a_second_reconcile_changes_nothing(): void
    {
        $this->linkedDocument('Tech design');
        $this->linkedDocument('Product design');
        $this->reconcile();
        $watch = $this->onlyWatch();
        $updatedAt = $watch->item->updatedAt;
        $body = $watch->item->body;
        $this->em->clear();
        $project = $this->em->find(Project::class, $this->project->id);
        self::assertInstanceOf(Project::class, $project);

        $this->reconciler->reconcile($project, [(string) $this->card->id]);

        $this->em->clear();
        $again = $this->onlyWatch();
        self::assertSame($watch->id?->toRfc4122(), $again->id?->toRfc4122());
        self::assertCount(2, $again->waits);
        self::assertCount(2, $this->openWaits($again));
        self::assertSame($body, $again->item->body);
        self::assertEquals($updatedAt, $again->item->updatedAt);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_asks WHERE project_id = :id', ['id' => (string) $this->project->id]));
    }

    public function test_switching_the_inbox_off_closes_the_item_obsolete(): void
    {
        $this->linkedDocument('Tech design');
        $this->reconcile();
        $this->switchFlag($this->em, InboxInstallFlags::FLAG_INBOX_ENABLED, false);

        $this->reconcile();

        $watch = $this->onlyWatch();
        self::assertSame(InboxItemState::Obsolete, $watch->item->state);
        self::assertSame(InboxCardWaitEndReason::SwitchedOff, $this->onlyWait($watch)->endReason);
    }

    public function test_a_document_switched_off_opens_no_item(): void
    {
        $this->settings()->documentInReview = false;
        $this->em->flush();
        $this->linkedDocument('Tech design');

        $this->reconcile();

        self::assertSame([], $this->watches());
    }

    public function test_switching_documents_off_closes_the_item_obsolete_and_on_again_opens_a_new_one(): void
    {
        $this->linkedDocument('Tech design');
        $this->reconcile();
        $settings = $this->settings();
        $settings->documentInReview = false;
        $this->em->flush();

        $this->reconcile();

        $closed = $this->onlyWatch();
        self::assertSame(InboxItemState::Obsolete, $closed->item->state);
        self::assertNotNull($closed->closedAt);
        self::assertSame(InboxCardWaitEndReason::SwitchedOff, $this->onlyWait($closed)->endReason);

        $settings->documentInReview = true;
        $this->em->flush();
        $this->reconcile();

        $watches = $this->watches();
        self::assertCount(2, $watches);
        self::assertSame($closed, $watches[0]);
        self::assertSame(InboxItemState::Open, $watches[1]->item->state);
        self::assertSame('Tech design in review, version 1', $watches[1]->item->body);
    }

    public function test_without_a_card_list_it_finds_the_cards_with_a_document_in_review_or_an_open_watch(): void
    {
        $this->linkedDocument('Tech design');
        $other = $this->card($this->em, $this->project, 13);
        $approved = $this->document($this->em, $this->project);
        $approved->addVersion('# One', '<h1>One</h1>');
        $approved->status = DocumentStatus::Approved;
        $other->documents->add(new CardDocument($other, $approved));
        $this->em->flush();

        $this->reconciler->reconcile($this->project, null);

        $watch = $this->onlyWatch();
        self::assertSame(InboxItemState::Open, $watch->item->state);
        self::assertSame([], $this->watches($other->id));

        $this->onlyWait($watch);
        foreach ($this->card->documents as $link) {
            $link->document->status = DocumentStatus::Approved;
        }
        $this->em->flush();
        $this->reconciler->reconcile($this->project, null);

        self::assertSame(InboxItemState::Done, $watch->item->state);
    }

    public function test_a_plan_in_review_opens_no_wait(): void
    {
        $plan = $this->untaggedLinkedDocument('Plan');
        $this->tagDocument($this->em, $plan, ['plan']);
        $this->card->column = $this->stageColumn($this->em, $this->project, 'tech-design');
        $this->em->flush();

        $this->reconcile();

        self::assertSame([], $this->watches());
    }

    public function test_an_untagged_document_in_review_opens_no_wait(): void
    {
        $this->untaggedLinkedDocument('Notes');
        $this->card->column = $this->stageColumn($this->em, $this->project, 'tech-design');
        $this->em->flush();

        $this->reconcile();

        self::assertSame([], $this->watches());
    }

    public function test_a_tech_design_on_a_card_in_the_tech_design_column_opens_a_wait(): void
    {
        $document = $this->linkedDocument('Tech design');

        $this->reconcile();

        self::assertEquals($document->id, $this->onlyWait($this->onlyWatch())->documentId);
    }

    public function test_a_tech_design_on_a_card_in_another_open_column_opens_no_wait(): void
    {
        $this->linkedDocument('Tech design');
        $this->card->column = $this->stageColumn($this->em, $this->project, 'implementation');
        $this->em->flush();

        $this->reconcile();

        self::assertSame([], $this->watches());
    }

    public function test_a_product_design_on_a_card_in_the_product_design_column_opens_a_wait(): void
    {
        $document = $this->untaggedLinkedDocument('Product design');
        $this->stageDocument($this->em, $document, $this->card, 'product-design');
        $this->em->flush();

        $this->reconcile();

        $watch = $this->onlyWatch();
        self::assertEquals($document->id, $this->onlyWait($watch)->documentId);
        self::assertSame('Product design in review, version 1', $watch->item->body);
    }

    public function test_a_move_out_of_the_stage_column_ends_the_document_wait_done(): void
    {
        $this->linkedDocument('Tech design');
        $this->reconcile();
        $this->card->column = $this->stageColumn($this->em, $this->project, 'implementation');
        $this->em->flush();

        $this->reconcile();

        $watch = $this->onlyWatch();
        self::assertSame(InboxItemState::Done, $watch->item->state);
        self::assertSame(InboxCardWaitEndReason::Resolved, $this->onlyWait($watch)->endReason);
    }

    public function test_stage_tags_set_later_open_the_wait(): void
    {
        $document = $this->untaggedLinkedDocument('Tech design');
        $this->card->column = $this->stageColumn($this->em, $this->project, 'tech-design');
        $this->em->flush();
        $this->reconcile();
        self::assertSame([], $this->watches());

        $setTags = self::getContainer()->get(SetDocumentTagsHandler::class);
        self::assertInstanceOf(SetDocumentTagsHandler::class, $setTags);
        $setTags(new SetDocumentTagsCommand($document, ['design', 'decisions']));
        $this->em->clear();
        $project = $this->em->find(Project::class, $this->project->id);
        self::assertInstanceOf(Project::class, $project);

        $this->reconciler->reconcile($project, [(string) $this->card->id]);

        self::assertEquals($document->id, $this->onlyWait($this->onlyWatch())->documentId);
    }

    /** @return iterable<string, array{WorkerRunState, InboxCardWaitTrigger, string}> */
    public static function waitingRuns(): iterable
    {
        yield 'blocked' => [WorkerRunState::Blocked, InboxCardWaitTrigger::RunBlocked, 'Run blocked'];
        yield 'gave up' => [WorkerRunState::GaveUp, InboxCardWaitTrigger::RunGaveUp, 'Run gave up'];
        yield 'waiting for a person' => [WorkerRunState::WaitingForPerson, InboxCardWaitTrigger::RunWaitingForPerson, 'Run waits for a person'];
    }

    #[DataProvider('waitingRuns')]
    public function test_a_newest_run_that_waits_opens_a_wait_item(WorkerRunState $state, InboxCardWaitTrigger $trigger, string $label): void
    {
        $run = $this->workerRun($state, "Needs the API key\nSecond line");

        $this->reconcile();

        $watch = $this->onlyWatch();
        self::assertSame(InboxItemState::Open, $watch->item->state);
        self::assertSame($label.': Needs the API key', $watch->item->body);
        self::assertCount(0, $watch->item->documents);
        $wait = $this->onlyWait($watch);
        self::assertSame($trigger, $wait->trigger);
        self::assertEquals($run->id, $wait->runId);
        self::assertNull($wait->documentId);
        self::assertNull($wait->versionNumber);
    }

    public function test_a_run_reason_takes_the_first_non_empty_line_cut_to_140_characters(): void
    {
        $this->workerRun(WorkerRunState::Blocked, "\n   \n  ".str_repeat('é', 150)."  \nSecond line");

        $this->reconcile();

        self::assertSame('Run blocked: '.str_repeat('é', 140), $this->onlyWatch()->item->body);
    }

    public function test_a_run_with_no_output_gives_the_bare_label(): void
    {
        $this->workerRun(WorkerRunState::GaveUp, " \n ");

        $this->reconcile();

        self::assertSame('Run gave up', $this->onlyWatch()->item->body);
    }

    public function test_a_newer_open_run_ends_the_wait_and_closes_the_item_done(): void
    {
        $this->workerRun(WorkerRunState::Blocked, 'Stuck', receivedAt: new \DateTimeImmutable('-1 minute'));
        $this->reconcile();
        $this->workerRun(WorkerRunState::Queued, '');

        $this->reconcile();

        $watch = $this->onlyWatch();
        self::assertSame(InboxItemState::Done, $watch->item->state);
        self::assertNotNull($watch->closedAt);
        self::assertSame(InboxCardWaitEndReason::Resolved, $this->onlyWait($watch)->endReason);
    }

    public function test_a_move_to_another_open_column_ends_the_run_wait_done(): void
    {
        $this->workerRun(WorkerRunState::Blocked, 'Stuck');
        $this->reconcile();
        $this->card->column = $this->column($this->project, 'next');
        $this->em->flush();

        $this->reconcile();

        $watch = $this->onlyWatch();
        self::assertSame(InboxItemState::Done, $watch->item->state);
        self::assertSame(InboxCardWaitEndReason::Resolved, $this->onlyWait($watch)->endReason);
    }

    public function test_a_run_of_another_column_or_of_no_column_gives_no_wait(): void
    {
        $this->workerRun(WorkerRunState::Blocked, 'Stuck', column: 'next', receivedAt: new \DateTimeImmutable('-1 minute'));
        $this->reconcile();
        self::assertSame([], $this->watches());

        $this->workerRun(WorkerRunState::Blocked, 'Stuck', column: null);
        $this->reconcile();
        self::assertSame([], $this->watches());
    }

    public function test_a_run_that_does_not_wait_gives_no_wait(): void
    {
        $this->workerRun(WorkerRunState::Failed, 'Crashed');

        $this->reconcile();

        self::assertSame([], $this->watches());
    }

    public function test_a_dismissed_run_wait_stays_dismissed_and_a_new_waiting_run_opens_a_new_item(): void
    {
        $this->workerRun(WorkerRunState::Blocked, 'Stuck', receivedAt: new \DateTimeImmutable('-1 minute'));
        $this->reconcile();
        $dismissed = $this->onlyWatch();
        $now = new \DateTimeImmutable();
        $dismissed->item->state = InboxItemState::Declined;
        $dismissed->item->closedAt = $now;
        $dismissed->dismissedAt = $now;
        $this->em->flush();

        $this->reconcile();

        self::assertSame([$dismissed], $this->watches());
        self::assertSame(InboxCardWaitEndReason::Dismissed, $this->onlyWait($dismissed)->endReason);

        $newer = $this->workerRun(WorkerRunState::Blocked, 'Stuck again');
        $this->reconcile();

        $watches = $this->watches();
        self::assertCount(2, $watches);
        self::assertSame(InboxItemState::Open, $watches[1]->item->state);
        self::assertEquals($newer->id, $this->onlyWait($watches[1])->runId);
        self::assertSame('Run blocked: Stuck again', $watches[1]->item->body);
    }

    public function test_a_document_wait_and_a_run_wait_share_one_item_and_the_end_of_one_keeps_it_open(): void
    {
        $document = $this->linkedDocument('Tech design');
        $this->workerRun(WorkerRunState::Blocked, 'Stuck', 'tech-design', new \DateTimeImmutable('-1 minute'));
        $this->reconcile();
        $watch = $this->onlyWatch();
        self::assertCount(2, $this->openWaits($watch));
        self::assertCount(1, $watch->item->documents);

        $this->workerRun(WorkerRunState::Running, '', 'tech-design');
        $this->reconcile();

        self::assertSame(InboxItemState::Open, $watch->item->state);
        $open = $this->openWaits($watch);
        self::assertCount(1, $open);
        self::assertEquals($document->id, $open[0]->documentId);
        self::assertSame('Tech design in review, version 1', $watch->item->body);
        $ended = array_values(array_filter($watch->waits->toArray(), static fn (InboxCardWait $wait): bool => null !== $wait->endedAt));
        self::assertCount(1, $ended);
        self::assertSame(InboxCardWaitTrigger::RunBlocked, $ended[0]->trigger);
        self::assertSame(InboxCardWaitEndReason::Resolved, $ended[0]->endReason);
    }

    public function test_a_run_blocked_switch_off_opens_no_item_and_a_document_wait_of_another_card_still_opens(): void
    {
        $this->settings()->runBlocked = false;
        $this->em->flush();
        $this->workerRun(WorkerRunState::Blocked, 'Stuck');
        $other = $this->card($this->em, $this->project, 13);
        $document = $this->document($this->em, $this->project);
        $document->addVersion('# One', '<h1>One</h1>');
        $other->documents->add(new CardDocument($other, $document));
        $this->stageDocument($this->em, $document, $other);
        $this->em->flush();

        $this->reconciler->reconcile($this->project, [(string) $this->card->id, (string) $other->id]);

        self::assertSame([], $this->watches());
        $watch = $this->onlyWatch($other->id);
        self::assertSame(InboxItemState::Open, $watch->item->state);
        self::assertEquals($document->id, $this->onlyWait($watch)->documentId);
    }

    public function test_a_run_blocked_switch_off_ends_the_run_wait_and_keeps_the_document_wait_open(): void
    {
        $document = $this->linkedDocument('Tech design');
        $this->workerRun(WorkerRunState::Blocked, 'Stuck', 'tech-design');
        $this->reconcile();
        $watch = $this->onlyWatch();
        self::assertCount(2, $this->openWaits($watch));
        $this->settings()->runBlocked = false;
        $this->em->flush();

        $this->reconcile();

        self::assertSame(InboxItemState::Open, $watch->item->state);
        $open = $this->openWaits($watch);
        self::assertCount(1, $open);
        self::assertEquals($document->id, $open[0]->documentId);
        self::assertSame('Tech design in review, version 1', $watch->item->body);
        [$runWait] = array_values(array_filter($watch->waits->toArray(), static fn (InboxCardWait $wait): bool => null !== $wait->endedAt));
        self::assertSame(InboxCardWaitTrigger::RunBlocked, $runWait->trigger);
        self::assertSame(InboxCardWaitEndReason::SwitchedOff, $runWait->endReason);

        $document->status = DocumentStatus::Approved;
        $this->em->flush();
        $this->reconcile();

        // The last wait ended by its own cause in this pass, so the item closes done.
        self::assertSame(InboxItemState::Done, $watch->item->state);
        [$documentWait] = array_values(array_filter($watch->waits->toArray(), static fn (InboxCardWait $wait): bool => InboxCardWaitTrigger::DocumentInReview === $wait->trigger));
        self::assertSame(InboxCardWaitEndReason::Resolved, $documentWait->endReason);
    }

    public function test_a_card_with_a_run_wait_in_a_terminal_column_closes_the_item_obsolete(): void
    {
        $this->workerRun(WorkerRunState::Blocked, 'Stuck', column: 'backlog');
        $this->reconcile();
        $this->card->column = $this->column($this->project, 'done');
        $this->em->flush();

        $this->reconcile();

        $watch = $this->onlyWatch();
        self::assertSame(InboxItemState::Obsolete, $watch->item->state);
        self::assertSame(InboxCardWaitEndReason::CardFinished, $this->onlyWait($watch)->endReason);
    }

    public function test_without_a_card_list_it_finds_a_card_with_only_a_run_wait(): void
    {
        $this->workerRun(WorkerRunState::WaitingForPerson, 'Cap reached');

        $this->reconciler->reconcile($this->project, null);

        self::assertSame(InboxItemState::Open, $this->onlyWatch()->item->state);
    }

    public function test_a_second_reconcile_of_a_run_wait_changes_nothing(): void
    {
        $this->workerRun(WorkerRunState::Blocked, 'Stuck');
        $this->reconcile();
        $watch = $this->onlyWatch();
        $updatedAt = $watch->item->updatedAt;
        $this->em->clear();
        $project = $this->em->find(Project::class, $this->project->id);
        self::assertInstanceOf(Project::class, $project);

        $this->reconciler->reconcile($project, null);

        $this->em->clear();
        $again = $this->onlyWatch();
        self::assertSame($watch->id?->toRfc4122(), $again->id?->toRfc4122());
        self::assertCount(1, $again->waits);
        self::assertSame('Run blocked: Stuck', $again->item->body);
        self::assertEquals($updatedAt, $again->item->updatedAt);
    }

    private function reconcile(): void
    {
        $this->reconciler->reconcile($this->project, [(string) $this->card->id]);
    }

    private function settings(): InboxProjectSettings
    {
        $settings = new InboxProjectSettings($this->project);
        $this->em->persist($settings);
        $this->em->flush();

        return $settings;
    }

    /** A tech design of the card, which sits in the Tech design column. */
    private function linkedDocument(string $title): Document
    {
        $document = $this->untaggedLinkedDocument($title);
        $this->stageDocument($this->em, $document, $this->card);
        $this->em->flush();

        return $document;
    }

    private function untaggedLinkedDocument(string $title): Document
    {
        $document = new Document($this->project->owner, $this->project, $title);
        $document->addVersion('# One', '<h1>One</h1>');
        $this->em->persist($document);
        $this->card->documents->add(new CardDocument($this->card, $document));
        $this->em->flush();

        return $document;
    }

    private function workerRun(WorkerRunState $state, string $output, ?string $column = 'backlog', \DateTimeImmutable $receivedAt = new \DateTimeImmutable()): WorkerRun
    {
        $run = new WorkerRun(
            project: $this->project,
            bridgeId: Uuid::v7(),
            cardId: $this->card->id ?? throw new \LogicException('Card has no id.'),
            cardNumber: $this->card->number,
            ruleName: 'implement',
            state: $state,
            output: $output,
            receivedAt: $receivedAt,
            cardColumn: $column,
        );
        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    private function agentReviewOf(Document $document): void
    {
        $item = new InboxItem(project: $this->project, number: 900, kind: InboxItemKind::Review, title: 'Review the design', blocking: true);
        $this->em->persist($item);
        $this->em->persist(new InboxReview($item, $document));
        $ask = $this->ask($this->em, $this->project);
        $ask->items->add(new InboxAskItem($ask, $item));
        $this->em->flush();
    }

    /** @return list<InboxCardWatch> in item number order */
    private function watches(?Uuid $cardId = null): array
    {
        $repository = self::getContainer()->get(InboxCardWatchRepository::class);
        self::assertInstanceOf(InboxCardWatchRepository::class, $repository);
        $watches = $repository->findBy(['cardId' => $cardId ?? $this->card->id]);
        usort($watches, static fn (InboxCardWatch $a, InboxCardWatch $b): int => $a->item->number <=> $b->item->number);

        return $watches;
    }

    private function onlyWatch(?Uuid $cardId = null): InboxCardWatch
    {
        $watches = $this->watches($cardId);
        self::assertCount(1, $watches);

        return $watches[0];
    }

    private function onlyWait(InboxCardWatch $watch): InboxCardWait
    {
        self::assertCount(1, $watch->waits);
        $wait = $watch->waits->first();
        self::assertInstanceOf(InboxCardWait::class, $wait);

        return $wait;
    }

    /** @return list<InboxCardWait> */
    private function openWaits(InboxCardWatch $watch): array
    {
        return array_values(array_filter($watch->waits->toArray(), static fn (InboxCardWait $wait): bool => null === $wait->endedAt));
    }

    /** @return list<InboxCardWait> ended first */
    private function sortedWaits(InboxCardWatch $watch): array
    {
        $waits = array_values($watch->waits->toArray());
        usort($waits, static fn (InboxCardWait $a, InboxCardWait $b): int => ($a->versionNumber ?? 0) <=> ($b->versionNumber ?? 0));

        return $waits;
    }

    private function onlyAskHolding(InboxItem $item): InboxAsk
    {
        $repository = self::getContainer()->get(InboxAskRepository::class);
        self::assertInstanceOf(InboxAskRepository::class, $repository);
        $asks = $repository->createQueryBuilder('a')
            ->join('a.items', 'l')
            ->andWhere('l.item = :item')
            ->setParameter('item', $item)
            ->getQuery()
            ->getResult();
        self::assertCount(1, $asks);
        self::assertInstanceOf(InboxAsk::class, $asks[0]);

        return $asks[0];
    }

    private function searchHits(string $word): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM inbox_items WHERE project_id = :project AND kind = 'wait' AND search_vector @@ to_tsquery('english', :word)",
            ['project' => (string) $this->project->id, 'word' => strtolower($word)],
        );
    }
}
