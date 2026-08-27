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
use Doctrine\Persistence\ManagerRegistry;
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

        // The MCP review payload reads the verdict off the Review row, so the row
        // has to go with the status or the two disagree.
        /** @var ReviewRepository $reviews */
        $reviews = self::getContainer()->get(ReviewRepository::class);
        self::assertNull($reviews->findByVersion($freshDoc->currentVersion()));
    }

    /**
     * submitted_at is TIMESTAMP(0), so two verdicts a second apart or less are
     * indistinguishable in time. Rather than tie-break an ordering that cannot be
     * trusted, the second verdict is refused — so there is never a pair for undo to
     * choose between, and the one row it removes is necessarily the right one.
     */
    public function test_a_second_verdict_in_the_same_second_is_refused_and_the_first_is_what_undo_removes(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        /** @var User $reviewer */
        /** @var Document $doc */
        [$reviewer, $doc] = $this->createUserAndDocument($em, '2');

        $docId = $doc->id;
        self::assertInstanceOf(Uuid::class, $docId);

        /** @var SubmitReviewHandler $submit */
        $submit = self::getContainer()->get(SubmitReviewHandler::class);
        $first = $submit(new SubmitReviewCommand($reviewer, $doc, Verdict::ChangesRequested->value));
        $firstId = (string) $first->id;

        try {
            $submit(new SubmitReviewCommand($reviewer, $doc, Verdict::Approved->value));
            self::fail('A version already carrying a verdict must refuse a second one');
        } catch (DomainErrors $e) {
            self::assertContains('review.document.flash.verdict_already_given', $e->errors);
        }

        // wrapInTransaction closes the manager when the body throws, so everything
        // below works through a fresh one — as the reviewer's next request would.
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $registry->resetManager();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $freshDoc = $em->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $freshDoc);
        self::assertSame(DocumentStatus::ChangesRequested, $freshDoc->status, 'The refused submit changed nothing');

        /** @var ReviewRepository $reviews */
        $reviews = self::getContainer()->get(ReviewRepository::class);
        $onlyVerdict = $reviews->findByVersion($freshDoc->currentVersion());
        self::assertInstanceOf(Review::class, $onlyVerdict);
        self::assertSame($firstId, (string) $onlyVerdict->id, 'The refused submit wrote no second row');

        /** @var UndoVerdictHandler $undo */
        $undo = self::getContainer()->get(UndoVerdictHandler::class);
        $undo(new UndoVerdictCommand(document: $freshDoc));

        self::assertNull($reviews->findByVersion($freshDoc->currentVersion()));
        self::assertSame(DocumentStatus::InReview, $freshDoc->status);

        // And with the version clear again, a fresh verdict is accepted.
        $freshReviewer = $em->find(User::class, $reviewer->id);
        self::assertInstanceOf(User::class, $freshReviewer);
        $submit(new SubmitReviewCommand($freshReviewer, $freshDoc, Verdict::Approved->value));
        self::assertSame(DocumentStatus::Approved, $freshDoc->status);
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
