<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitEndReason;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemDocument;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Inbox\Service\CardWaitReconciler;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
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

    private function reconcile(): void
    {
        $this->reconciler->reconcile($this->project, [(string) $this->card->id]);
    }

    private function linkedDocument(string $title): Document
    {
        $document = new Document($this->project->owner, $this->project, $title);
        $document->addVersion('# One', '<h1>One</h1>');
        $this->em->persist($document);
        $this->card->documents->add(new CardDocument($this->card, $document));
        $this->em->flush();

        return $document;
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
