<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Controller\UndoVerdictController;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Tests\Support\AcceptedTerms;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final class UndoVerdictControllerTest extends WebTestCase
{
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
            'submit_review_form' => ['verdict' => 'approved'],
        ]);
        $audit->forget();

        $client->request(Request::METHOD_POST, "/projects/$projectId/documents/$documentId/review/undo", [
            '_csrf_token' => 'csrf-token',
        ]);

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
            '_csrf_token' => 'csrf-token',
        ]);

        self::assertResponseRedirects("/projects/$projectId/documents/$documentId/review");
        self::assertSame([], $audit->operations());
    }

    public function test_the_controller_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(UndoVerdictController::class);
    }
}
