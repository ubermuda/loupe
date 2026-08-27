<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Command\UndoVerdictCommand;
use App\Module\Review\Command\UndoVerdictHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Repository\ReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class UndoVerdictHandlerTest extends KernelTestCase
{
    /** @return array{User, Document} */
    private function createUserAndDocument(EntityManagerInterface $em, string $suffix): array
    {
        $user = new User(
            fullName: 'Reviewer',
            email: 'undo-reviewer'.$suffix.'@example.com',
            password: 'hashed-placeholder',
        );
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Auth PRD', '# Auth'));

        return [$user, $doc];
    }

    public function test_undo_appends_a_withdrawal_and_keeps_the_verdict_it_takes_back(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        /** @var User $reviewer */
        /** @var Document $doc */
        [$reviewer, $doc] = $this->createUserAndDocument($em, '1');

        $docId = $doc->id;
        self::assertInstanceOf(Uuid::class, $docId);

        /** @var SubmitReviewHandler $submit */
        $submit = self::getContainer()->get(SubmitReviewHandler::class);
        $approval = $submit(new SubmitReviewCommand($reviewer, $doc, Verdict::Approved->value));
        self::assertSame(DocumentStatus::Approved, $doc->status);

        /** @var UndoVerdictHandler $undo */
        $undo = self::getContainer()->get(UndoVerdictHandler::class);
        $withdrawal = $undo(new UndoVerdictCommand(document: $doc, actor: $reviewer));

        self::assertSame(Verdict::Withdrawn, $withdrawal->verdict);
        self::assertSame($reviewer, $withdrawal->reviewer, 'The log records who withdrew it');
        self::assertSame($approval->sequence + 1, $withdrawal->sequence);

        $em->clear();
        $freshDoc = $em->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $freshDoc);
        self::assertSame(DocumentStatus::InReview, $freshDoc->status);

        $version = $freshDoc->currentVersion();

        /** @var ReviewRepository $reviews */
        $reviews = self::getContainer()->get(ReviewRepository::class);

        // The approval is still there — that is the point of appending rather than
        // deleting: "approved at T1, withdrawn at T2, by whom" stays readable.
        $log = $reviews->findBy(['version' => $version], ['sequence' => 'ASC']);
        self::assertCount(2, $log);
        self::assertSame(Verdict::Approved, $log[0]->verdict);
        self::assertSame(Verdict::Withdrawn, $log[1]->verdict);

        // And nothing stands on the version any more.
        self::assertNull($reviews->findStandingVerdictByVersion($version));
    }

    /**
     * The reason the log carries a sequence at all. submitted_at is TIMESTAMP(0),
     * so an approval and the withdrawal a moment later store the same value and no
     * ordering over that column can tell them apart — the sequence can.
     */
    public function test_a_verdict_and_its_withdrawal_in_the_same_second_are_still_ordered(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        /** @var User $reviewer */
        /** @var Document $doc */
        [$reviewer, $doc] = $this->createUserAndDocument($em, '2');

        // Written by hand with one shared timestamp rather than through the handlers:
        // two real calls usually land in the same second but are not guaranteed to,
        // and a timing-dependent guard here would be a flake rather than a test.
        $docId = $doc->id;
        self::assertInstanceOf(Uuid::class, $docId);
        $sameSecond = new \DateTimeImmutable('2026-01-01 12:00:00');
        $version = $doc->currentVersion();
        $em->persist(new Review($version, Verdict::Approved, $reviewer, sequence: 1, submittedAt: $sameSecond));
        $em->persist(new Review($version, Verdict::Withdrawn, $reviewer, sequence: 2, submittedAt: $sameSecond));
        $em->flush();
        $em->clear();

        $freshDoc = $em->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $freshDoc);
        $freshVersion = $freshDoc->currentVersion();

        /** @var ReviewRepository $reviews */
        $reviews = self::getContainer()->get(ReviewRepository::class);
        $newest = $reviews->findNewestByVersion($freshVersion);
        self::assertInstanceOf(Review::class, $newest);
        self::assertSame(
            $newest->submittedAt->format('Y-m-d H:i:s'),
            $sameSecond->format('Y-m-d H:i:s'),
            'Both rows really do carry the same second',
        );
        self::assertSame(Verdict::Withdrawn, $newest->verdict, 'The withdrawal is the newest row, not the approval');
        self::assertNull($reviews->findStandingVerdictByVersion($freshVersion), 'So nothing stands on the version');
    }

    public function test_a_withdrawn_version_can_be_ruled_on_again(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        /** @var User $reviewer */
        /** @var Document $doc */
        [$reviewer, $doc] = $this->createUserAndDocument($em, '3');

        /** @var SubmitReviewHandler $submit */
        $submit = self::getContainer()->get(SubmitReviewHandler::class);
        /** @var UndoVerdictHandler $undo */
        $undo = self::getContainer()->get(UndoVerdictHandler::class);

        $submit(new SubmitReviewCommand($reviewer, $doc, Verdict::Approved->value));
        $undo(new UndoVerdictCommand(document: $doc, actor: $reviewer));
        $second = $submit(new SubmitReviewCommand($reviewer, $doc, Verdict::ChangesRequested->value));

        self::assertSame(DocumentStatus::ChangesRequested, $doc->status);
        self::assertSame(3, $second->sequence, 'The third row in the log, not a replacement for the first');

        /** @var ReviewRepository $reviews */
        $reviews = self::getContainer()->get(ReviewRepository::class);
        $standing = $reviews->findStandingVerdictByVersion($doc->currentVersion());
        self::assertInstanceOf(Review::class, $standing);
        self::assertSame(Verdict::ChangesRequested, $standing->verdict);
    }

    public function test_an_already_withdrawn_verdict_cannot_be_withdrawn_again(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        /** @var User $reviewer */
        /** @var Document $doc */
        [$reviewer, $doc] = $this->createUserAndDocument($em, '4');

        /** @var SubmitReviewHandler $submit */
        $submit = self::getContainer()->get(SubmitReviewHandler::class);
        $submit(new SubmitReviewCommand($reviewer, $doc, Verdict::Approved->value));

        /** @var UndoVerdictHandler $undo */
        $undo = self::getContainer()->get(UndoVerdictHandler::class);
        $undo(new UndoVerdictCommand(document: $doc, actor: $reviewer));

        try {
            $undo(new UndoVerdictCommand(document: $doc, actor: $reviewer));
            self::fail('There is nothing to withdraw once the verdict already is');
        } catch (DomainErrors $e) {
            self::assertContains('review.document.flash.verdict_already_withdrawn', $e->errors);
        }
    }

    public function test_a_document_with_no_verdict_cannot_be_undone(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        /** @var User $reviewer */
        /** @var Document $doc */
        [$reviewer, $doc] = $this->createUserAndDocument($em, '5');

        /** @var UndoVerdictHandler $undo */
        $undo = self::getContainer()->get(UndoVerdictHandler::class);

        try {
            $undo(new UndoVerdictCommand(document: $doc, actor: $reviewer));
            self::fail('Undoing a verdict that was never given must be rejected');
        } catch (DomainErrors $e) {
            self::assertContains('review.document.flash.verdict_none', $e->errors);
        }

        self::assertSame(DocumentStatus::InReview, $doc->status);
    }
}
