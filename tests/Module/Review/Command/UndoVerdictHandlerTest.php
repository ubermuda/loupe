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

    public function test_undoing_a_verdict_returns_the_document_to_review_and_drops_the_row(): void
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
        $submit(new SubmitReviewCommand($reviewer, $doc, Verdict::Approved->value));

        self::assertSame(DocumentStatus::Approved, $doc->status);

        /** @var UndoVerdictHandler $undo */
        $undo = self::getContainer()->get(UndoVerdictHandler::class);
        $undo(new UndoVerdictCommand(document: $doc));

        $em->clear();
        $freshDoc = $em->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $freshDoc);
        self::assertSame(DocumentStatus::InReview, $freshDoc->status);

        // The MCP review payload reads the verdict off the latest Review row, so
        // the row has to go with the status or the two disagree.
        /** @var ReviewRepository $reviews */
        $reviews = self::getContainer()->get(ReviewRepository::class);
        self::assertNull($reviews->findLatestByVersion($freshDoc->currentVersion()));
    }

    public function test_undo_pops_only_the_latest_verdict(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        /** @var User $reviewer */
        /** @var Document $doc */
        [$reviewer, $doc] = $this->createUserAndDocument($em, '2');

        // Built directly rather than through the handler so its timestamp is
        // unambiguously older — two handler calls in one test share a second, and
        // "latest" is decided by submittedAt.
        $older = new Review(
            version: $doc->currentVersion(),
            verdict: Verdict::ChangesRequested,
            reviewer: $reviewer,
            submittedAt: new \DateTimeImmutable('-1 hour'),
        );
        $em->persist($older);
        $em->flush();

        /** @var SubmitReviewHandler $submit */
        $submit = self::getContainer()->get(SubmitReviewHandler::class);
        $submit(new SubmitReviewCommand($reviewer, $doc, Verdict::Approved->value));

        self::assertSame(DocumentStatus::Approved, $doc->status);

        /** @var UndoVerdictHandler $undo */
        $undo = self::getContainer()->get(UndoVerdictHandler::class);
        $undo(new UndoVerdictCommand(document: $doc));

        self::assertSame(DocumentStatus::ChangesRequested, $doc->status, 'The verdict underneath comes back');

        /** @var ReviewRepository $reviews */
        $reviews = self::getContainer()->get(ReviewRepository::class);
        $remaining = $reviews->findLatestByVersion($doc->currentVersion());
        self::assertInstanceOf(Review::class, $remaining);
        self::assertSame(Verdict::ChangesRequested, $remaining->verdict);
    }

    public function test_a_document_with_no_verdict_cannot_be_undone(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        /** @var Document $doc */
        [, $doc] = $this->createUserAndDocument($em, '3');

        /** @var UndoVerdictHandler $undo */
        $undo = self::getContainer()->get(UndoVerdictHandler::class);

        try {
            $undo(new UndoVerdictCommand(document: $doc));
            self::fail('Undoing a verdict that was never given must be rejected');
        } catch (DomainErrors $e) {
            self::assertContains('review.document.flash.verdict_none', $e->errors);
        }

        self::assertSame(DocumentStatus::InReview, $doc->status);
    }
}
