<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Controller\UndoVerdictController;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Repository\ReviewRepository;
use App\Tests\Support\AcceptedTerms;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final class UndoVerdictControllerTest extends WebTestCase
{
    #[TestWith([''])]
    #[TestWith(['not-a-uuid'])]
    #[TestWith(['00000000-0000-7000-8000-000000000000'])]
    public function test_an_invalid_withdrawal_target_preserves_the_verdict(string $reviewId): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        [$owner, $projectId, $documentId] = $this->seedOwnerAndDocument($em, 'invalid-target');
        $document = $em->find(Document::class, $documentId);
        self::assertInstanceOf(Document::class, $document);
        $reviewer = $em->find(User::class, $owner->id);
        self::assertInstanceOf(User::class, $reviewer);
        $submit = static::getContainer()->get(SubmitReviewHandler::class);
        self::assertInstanceOf(SubmitReviewHandler::class, $submit);
        $approval = $submit(new SubmitReviewCommand($reviewer, $document, 'approved', 1));

        $client->loginUser($owner);
        $page = $client->request(Request::METHOD_GET, "/projects/$projectId/documents/$documentId/review");
        $form = $page->filter('.lp-verdict-bar__undo button')->form();
        $form['undo_verdict_form[reviewId]'] = $reviewId;
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();
        $document = $em->find(Document::class, $documentId);
        self::assertInstanceOf(Document::class, $document);
        self::assertSame(DocumentStatus::Approved, $document->status);
        $reviews = static::getContainer()->get(ReviewRepository::class);
        self::assertInstanceOf(ReviewRepository::class, $reviews);
        self::assertCount(1, $reviews->findHistoryByDocument($document));
        self::assertEquals($approval->id, $reviews->findNewestByVersion($document->currentVersion())?->id);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_a_stale_withdrawal_keeps_a_newer_verdict(bool $revised): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        [$owner, $projectId, $documentId] = $this->seedOwnerAndDocument($em, 'stale');
        $document = $em->find(Document::class, $documentId);
        self::assertInstanceOf(Document::class, $document);
        $reviewer = $em->find(User::class, $owner->id);
        self::assertInstanceOf(User::class, $reviewer);
        $submit = static::getContainer()->get(SubmitReviewHandler::class);
        self::assertInstanceOf(SubmitReviewHandler::class, $submit);
        $submit(new SubmitReviewCommand($reviewer, $document, 'approved', 1));

        $client->loginUser($owner);
        $page = $client->request(Request::METHOD_GET, "/projects/$projectId/documents/$documentId/review");
        $staleForm = $page->filter('.lp-verdict-bar__undo button')->form();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $document = $em->find(Document::class, $documentId);
        self::assertInstanceOf(Document::class, $document);
        $reviewer = $em->find(User::class, $owner->id);
        self::assertInstanceOf(User::class, $reviewer);
        if ($revised) {
            $document->addVersion('# Revised', '<h1>Revised</h1>');
        }
        $newer = new Review($document->currentVersion(), Verdict::ChangesRequested, $reviewer, $revised ? 1 : 2, note: 'Clarify the retry policy.');
        $document->status = DocumentStatus::ChangesRequested;
        $em->persist($newer);
        $em->flush();
        $newerId = $newer->id;
        $em->clear();

        $client->submit($staleForm);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();
        $document = $em->find(Document::class, $documentId);
        self::assertInstanceOf(Document::class, $document);
        self::assertSame(DocumentStatus::ChangesRequested, $document->status);
        $reviews = static::getContainer()->get(ReviewRepository::class);
        self::assertInstanceOf(ReviewRepository::class, $reviews);
        self::assertCount(2, $reviews->findHistoryByDocument($document));
        self::assertEquals($newerId, $reviews->findNewestByVersion($document->currentVersion())?->id);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-review-withdrawal-errors]', 'The review changed');
    }

    /** @return array{User, string, string} */
    private function seedOwnerAndDocument(EntityManagerInterface $em, string $suffix): array
    {
        $owner = new User(
            fullName: 'Undo Owner',
            email: 'undoowner'.$suffix.'@example.com',
            password: 'hashed-password-placeholder',
        );
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($owner, static::getContainer());
        $em->persist($owner);

        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);

        $document = new Document(owner: $owner, project: $project, title: 'Undo Verdict Doc');
        $document->addVersion('# Hello', '<h1>Hello</h1>');
        $em->persist($document);
        $em->flush();

        $projectId = (string) $project->id;
        $documentId = (string) $document->id;
        $em->clear();

        return [$owner, $projectId, $documentId];
    }

    public function test_an_undone_verdict_is_recorded_on_the_domain_channel(): void
    {
        $client = static::createClient();
        // The container must survive the POST, or the recording Auditor the
        // request used is thrown away before it can be read.
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        [$owner, $projectId, $documentId] = $this->seedOwnerAndDocument($em, 'audit');
        $audit = RecordingAuditor::installedIn(static::getContainer());

        $client->loginUser($owner);
        // A prior GET establishes browsing history, so BrowserKit auto-sets
        // HTTP_REFERER on the POSTs below; without it the same-origin CSRF check
        // rejects them as 403 whatever the controller does.
        $client->request(Request::METHOD_GET, "/projects/$projectId/documents/$documentId/review");
        $client->request(Request::METHOD_POST, "/projects/$projectId/documents/$documentId/review/submit", [
            '_csrf_token' => 'csrf-token',
            'submit_review_form' => ['_token' => 'csrf-token', 'verdict' => 'approved', 'versionNumber' => 1],
        ]);
        $audit->forget();
        $page = $client->followRedirect();
        $client->submit($page->filter('.lp-verdict-bar__undo button')->form());

        self::assertResponseRedirects("/projects/$projectId/documents/$documentId/review");

        $record = $audit->record('review.document_verdict_undone');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('document', $record->subject->type);
        self::assertSame($documentId, $record->subject->id);
        self::assertSame([
            'documentId' => $documentId,
            'status' => DocumentStatus::InReview->value,
        ], $record->context);

        self::assertSame(['review.document_verdict_undone'], $audit->domainLogLines());
        self::assertSame([], $audit->securityLogLines());
    }

    public function test_undoing_a_document_with_no_verdict_records_nothing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        [$owner, $projectId, $documentId] = $this->seedOwnerAndDocument($em, 'audit-none');
        $audit = RecordingAuditor::installedIn(static::getContainer());

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, "/projects/$projectId/documents/$documentId/review");
        $audit->forget();

        $client->request(Request::METHOD_POST, "/projects/$projectId/documents/$documentId/review/undo", [
            'undo_verdict_form' => ['_token' => 'csrf-token', 'reviewId' => '00000000-0000-7000-8000-000000000000'],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $audit->operations());
    }

    public function test_the_controller_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(UndoVerdictController::class);
    }
}
