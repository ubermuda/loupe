<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemDocument;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Inbox\Entity\InboxReviewVerdict;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Command\UndoVerdictCommand;
use App\Module\Review\Command\UndoVerdictHandler;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Event\ReviewSubmitted;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class DocumentReviewCompletionTest extends KernelTestCase
{
    use InboxFixtures;

    public function test_a_failure_after_completion_rolls_back_the_verdict_and_inbox_result(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = $this->owner($em, 'rollback-review');
        $project = $this->project($em, $owner, 'rollback-review');
        $document = $this->document($em, $project);
        $document->addVersion('# Design', '<h1>Design</h1>');
        $item = new InboxItem($project, 1, InboxItemKind::Review, 'Review this design', true);
        $review = new InboxReview($item, $document);
        $ask = $this->ask($em, $project);
        $link = new InboxAskItem($ask, $item);
        $ask->items->add($link);
        foreach ([$item, $review, $link] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $events = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $events);
        $events->addListener(ReviewSubmitted::class, static function () use ($item): void {
            self::assertSame(InboxItemState::Done, $item->state);
            throw new \RuntimeException('Abort after inbox completion.');
        }, -1000);
        $submit = self::getContainer()->get(SubmitReviewHandler::class);
        self::assertInstanceOf(SubmitReviewHandler::class, $submit);
        try {
            $submit(new SubmitReviewCommand($owner, $document, 'approved', 1));
            self::fail('The failing listener must abort the transaction.');
        } catch (\RuntimeException $error) {
            self::assertSame('Abort after inbox completion.', $error->getMessage());
        }

        $db = $em->getConnection();
        self::assertSame('open', $db->fetchOne('SELECT state FROM inbox_items WHERE id = ?', [(string) $item->id]));
        self::assertNull($db->fetchOne('SELECT verdict FROM inbox_reviews WHERE id = ?', [(string) $review->id]));
        self::assertNull($db->fetchOne('SELECT closed_at FROM inbox_asks WHERE id = ?', [(string) $ask->id]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM reviews WHERE version_id = ?', [(string) $document->currentVersion()->id]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM outbox_events WHERE project_id = ?', [(string) $project->id]));
    }

    #[TestWith(['approved'])]
    #[TestWith(['changes-requested'])]
    public function test_verdict_completes_only_targeted_reviews_and_withdrawal_preserves_the_answer(string $verdict): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = $this->owner($em, 'review-completion');
        $project = $this->project($em, $owner, 'review-completion');
        $document = $this->document($em, $project);
        $document->addVersion('# Design', '<h1>Design</h1>');
        $otherDocument = $this->document($em, $project);
        $otherDocument->addVersion('# Other', '<h1>Other</h1>');
        $question = $this->item($em, $project, 1);
        $question->documents->add(new InboxItemDocument($question, $document));
        $item = new InboxItem($project, 2, InboxItemKind::Review, 'Review this design', true);
        $otherItem = new InboxItem($project, 3, InboxItemKind::Review, 'Review another design', true);
        $review = new InboxReview($item, $document);
        $otherReview = new InboxReview($otherItem, $otherDocument);
        $ask = $this->ask($em, $project);
        $link = new InboxAskItem($ask, $item);
        $ask->items->add($link);
        foreach ([$item, $otherItem, $review, $otherReview, $link] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $submit = self::getContainer()->get(SubmitReviewHandler::class);
        self::assertInstanceOf(SubmitReviewHandler::class, $submit);
        $result = $submit(new SubmitReviewCommand($owner, $document, $verdict, 1, 'Please verify retries.'));
        $undo = self::getContainer()->get(UndoVerdictHandler::class);
        self::assertInstanceOf(UndoVerdictHandler::class, $undo);
        $withdrawal = $undo(new UndoVerdictCommand($document, $owner, (string) $result->id));
        self::assertSame(DocumentStatus::InReview, $document->status);
        $submit(new SubmitReviewCommand($owner, $document, 'approved', 1, 'A later verdict.', (string) $withdrawal->id));
        $em->clear();

        $stored = $em->find(InboxReview::class, $review->id);
        self::assertInstanceOf(InboxReview::class, $stored);
        self::assertSame(InboxItemState::Done, $stored->item->state);
        self::assertSame(InboxReviewVerdict::from($verdict), $stored->verdict);
        self::assertSame('Please verify retries.', $stored->note);
        self::assertSame((string) $result->id, (string) $stored->documentReview?->id);
        self::assertSame((string) $owner->id, (string) $stored->reviewer?->id);
        self::assertSame(1, $stored->reviewedVersionNumber);
        self::assertNotNull($stored->submittedAt);
        self::assertNotNull($em->find(InboxAsk::class, $ask->id)?->closedAt);
        self::assertSame(InboxItemState::Open, $em->find(InboxItem::class, $question->id)?->state);
        self::assertSame(InboxItemState::Open, $em->find(InboxItem::class, $otherItem->id)?->state);
        self::assertNull($em->find(InboxReview::class, $otherReview->id)?->verdict);
        $payloads = $em->getConnection()->fetchFirstColumn(
            'SELECT payload FROM outbox_events WHERE project_id = ? AND type = ?',
            [(string) $project->id, 'inbox.ask_closed'],
        );
        self::assertCount(1, $payloads);
        self::assertSame('human', json_decode($payloads[0], true, flags: \JSON_THROW_ON_ERROR)['actor']);
    }
}
