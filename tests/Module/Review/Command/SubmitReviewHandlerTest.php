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
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\ValueObject\Anchor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class SubmitReviewHandlerTest extends KernelTestCase
{
    public function test_an_older_same_version_form_cannot_replace_a_newer_verdict(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        [$reviewer, $document] = $this->createUserAndDocument($em, 'stale-same-version');
        $handler = self::getContainer()->get(SubmitReviewHandler::class);
        self::assertInstanceOf(SubmitReviewHandler::class, $handler);
        $first = $handler(new SubmitReviewCommand($reviewer, $document, 'approved', 1));

        try {
            $handler(new SubmitReviewCommand($reviewer, $document, 'changes-requested', 1, 'An older draft.'));
            self::fail('A stale verdict must be refused.');
        } catch (DomainErrors $error) {
            self::assertSame(['expectedReviewId' => 'review.document.flash.verdict_changed'], $error->errors);
        }
        self::assertTrue($em->isOpen());
        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM reviews WHERE version_id = ?', [(string) $first->version->id]));
        self::assertSame('approved', $em->getConnection()->fetchOne('SELECT status FROM documents WHERE id = ?', [(string) $document->id]));

        $second = $handler(new SubmitReviewCommand($reviewer, $document, 'changes-requested', 1, 'A current decision.', (string) $first->id));
        self::assertSame(2, $second->sequence);
        self::assertSame(Verdict::ChangesRequested, $second->verdict);
    }

    /** @return array{User, Document} */
    private function createUserAndDocument(EntityManagerInterface $em, string $suffix): array
    {
        $user = new User(
            fullName: 'Reviewer',
            email: 'reviewer'.$suffix.'@example.com',
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

    public function test_changes_requested_creates_review_and_transitions_status(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        /** @var User $reviewer */
        /** @var Document $doc */
        [$reviewer, $doc] = $this->createUserAndDocument($em, '1');

        $docId = $doc->id;
        self::assertInstanceOf(Uuid::class, $docId);

        /** @var SubmitReviewHandler $handler */
        $handler = self::getContainer()->get(SubmitReviewHandler::class);
        $review = $handler(new SubmitReviewCommand(
            reviewer: $reviewer,
            document: $doc,
            verdict: Verdict::ChangesRequested->value,
            versionNumber: 1,
            note: '  Explain the retry behaviour.  ',
        ));

        self::assertInstanceOf(Review::class, $review);
        self::assertSame(Verdict::ChangesRequested, $review->verdict);
        self::assertSame($reviewer, $review->reviewer);

        $em->clear();
        $freshDoc = $em->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $freshDoc);
        self::assertSame(DocumentStatus::ChangesRequested, $freshDoc->status);

        // Verify a Review record was persisted on the current version
        $currentVersion = $freshDoc->currentVersion();
        /** @var \App\Module\Review\Repository\ReviewRepository $reviewRepo */
        $reviewRepo = $em->getRepository(Review::class);
        $savedReview = $reviewRepo->findOneBy(['version' => $currentVersion]);
        self::assertInstanceOf(Review::class, $savedReview);
        self::assertSame(Verdict::ChangesRequested, $savedReview->verdict);
        self::assertSame('Explain the retry behaviour.', $savedReview->note);
    }

    public function test_approved_creates_review_and_transitions_status(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        /** @var User $reviewer */
        /** @var Document $doc */
        [$reviewer, $doc] = $this->createUserAndDocument($em, '2');

        $docId = $doc->id;
        self::assertInstanceOf(Uuid::class, $docId);

        /** @var SubmitReviewHandler $handler */
        $handler = self::getContainer()->get(SubmitReviewHandler::class);
        $review = $handler(new SubmitReviewCommand(
            reviewer: $reviewer,
            document: $doc,
            verdict: Verdict::Approved->value,
            versionNumber: 1,
        ));

        self::assertInstanceOf(Review::class, $review);
        self::assertSame(Verdict::Approved, $review->verdict);

        $em->clear();
        $freshDoc = $em->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $freshDoc);
        self::assertSame(DocumentStatus::Approved, $freshDoc->status);
    }

    /**
     * 'withdrawn' is a real Verdict case, so tryFrom accepts it and the form DTO
     * only checks the field is non-blank — this route is the only thing standing
     * between a hand-crafted POST and a withdrawal with none of undo's checks.
     */
    public function test_withdrawn_cannot_be_submitted_as_a_verdict(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        /** @var User $reviewer */
        /** @var Document $doc */
        [$reviewer, $doc] = $this->createUserAndDocument($em, '4');

        /** @var SubmitReviewHandler $handler */
        $handler = self::getContainer()->get(SubmitReviewHandler::class);

        try {
            $handler(new SubmitReviewCommand($reviewer, $doc, Verdict::Withdrawn->value, 1));
            self::fail('Withdrawn is written by undo, never submitted');
        } catch (DomainErrors $e) {
            self::assertContains('review.document.flash.verdict_invalid', $e->errors);
        }

        self::assertSame(DocumentStatus::InReview, $doc->status);
    }

    public function test_an_unrecognised_verdict_value_throws_domain_errors(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        /** @var User $reviewer */
        /** @var Document $doc */
        [$reviewer, $doc] = $this->createUserAndDocument($em, '3');

        /** @var SubmitReviewHandler $handler */
        $handler = self::getContainer()->get(SubmitReviewHandler::class);

        $this->expectException(DomainErrors::class);

        $handler(new SubmitReviewCommand(
            reviewer: $reviewer,
            document: $doc,
            verdict: 'not-a-real-verdict',
            versionNumber: 1,
        ));
    }

    public function test_a_bare_change_request_is_saved_when_the_version_has_an_open_comment(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        [$reviewer, $doc] = $this->createUserAndDocument($em, 'bare-open');
        $this->comment($em, $doc, $reviewer, CommentStatus::Pending);
        $docId = $doc->id;
        self::assertInstanceOf(Uuid::class, $docId);

        $handler = self::getContainer()->get(SubmitReviewHandler::class);
        self::assertInstanceOf(SubmitReviewHandler::class, $handler);
        $review = $handler(new SubmitReviewCommand($reviewer, $doc, Verdict::ChangesRequested->value, 1, '   '));

        self::assertSame(Verdict::ChangesRequested, $review->verdict);
        self::assertNull($review->note);
        $em->clear();
        $freshDoc = $em->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $freshDoc);
        self::assertSame(DocumentStatus::ChangesRequested, $freshDoc->status);
    }

    public function test_a_bare_change_request_is_refused_when_the_version_has_no_comment(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        [$reviewer, $doc] = $this->createUserAndDocument($em, 'bare-none');

        $this->assertBareChangeRequestRefused($em, $reviewer, $doc);
    }

    public function test_a_bare_change_request_is_refused_when_the_only_comment_is_resolved(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        [$reviewer, $doc] = $this->createUserAndDocument($em, 'bare-resolved');
        $this->comment($em, $doc, $reviewer, CommentStatus::Resolved);

        $this->assertBareChangeRequestRefused($em, $reviewer, $doc);
    }

    public function test_a_bare_change_request_is_refused_when_the_only_open_comment_is_deleted(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        [$reviewer, $doc] = $this->createUserAndDocument($em, 'bare-deleted');
        $this->comment($em, $doc, $reviewer, CommentStatus::Pending)->deletedAt = new \DateTimeImmutable();
        $em->flush();

        $this->assertBareChangeRequestRefused($em, $reviewer, $doc);
    }

    private function comment(EntityManagerInterface $em, Document $doc, User $author, CommentStatus $status): Comment
    {
        $comment = new Comment($doc->currentVersion(), $author, 'Change this passage.', Anchor::unanchored());
        $comment->status = $status;
        $em->persist($comment);
        $em->flush();

        return $comment;
    }

    private function assertBareChangeRequestRefused(EntityManagerInterface $em, User $reviewer, Document $doc): void
    {
        $handler = self::getContainer()->get(SubmitReviewHandler::class);
        self::assertInstanceOf(SubmitReviewHandler::class, $handler);

        try {
            $handler(new SubmitReviewCommand($reviewer, $doc, Verdict::ChangesRequested->value, 1));
            self::fail('A bare change request with no open comment must be refused.');
        } catch (DomainErrors $e) {
            self::assertSame(['note' => 'review.document.flash.note_or_comment_required'], $e->errors);
        }

        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM reviews WHERE version_id = ?', [(string) $doc->currentVersion()->id]));
        self::assertSame('in-review', $em->getConnection()->fetchOne('SELECT status FROM documents WHERE id = ?', [(string) $doc->id]));
    }
}
