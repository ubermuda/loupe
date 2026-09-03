<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Project\Entity\Project;
use App\Module\Review\Controller\SubmitReviewController;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Tests\Support\AcceptedTerms;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class SubmitReviewControllerTest extends WebTestCase
{
    /** @param non-empty-string $email */
    private function createUser(EntityManagerInterface $em, string $username, string $email): User
    {
        $user = new User(
            fullName: ucfirst($username),
            email: $email,
            password: 'hashed-password-placeholder',
        );
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());
        $em->persist($user);

        return $user;
    }

    /** @return array{User, Document, string, string} */
    private function seedOwnerAndDocument(EntityManagerInterface $em, string $suffix): array
    {
        $owner = $this->createUser($em, 'submitowner'.$suffix, 'submitowner'.$suffix.'@example.com');
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);

        $doc = new Document(owner: $owner, project: $project, title: 'Submit Review Doc');
        $doc->addVersion('# Hello', '<h1>Hello</h1>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $docId = (string) $doc->id;
        $em->clear();

        return [$owner, $doc, $projectId, $docId];
    }

    public function test_a_recognised_verdict_is_persisted_and_flashes_success(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$owner, , $projectId, $docId] = $this->seedOwnerAndDocument($em, '1');

        $client->loginUser($owner);
        // A prior GET establishes browsing history, so BrowserKit auto-sets
        // HTTP_REFERER on the POST below — without it, SameOriginCsrfTokenManager
        // sees neither an Origin/Referer match nor a real double-submit token
        // and rejects the request as a 403 regardless of controller logic.
        $client->request(Request::METHOD_GET, "/projects/$projectId/documents/$docId/review");
        $client->request(Request::METHOD_POST, "/projects/$projectId/documents/$docId/review/submit", [
            '_csrf_token' => 'csrf-token',
            'submit_review_form' => ['verdict' => 'changes-requested'],
        ]);

        self::assertResponseRedirects("/projects/$projectId/documents/$docId/review");
        $client->followRedirect();
        self::assertSelectorExists('.lp-flash--success');

        $freshDoc = static::getContainer()->get(EntityManagerInterface::class)->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $freshDoc);
        self::assertSame(DocumentStatus::ChangesRequested, $freshDoc->status);
    }

    public function test_a_missing_verdict_field_flashes_error_and_does_not_change_status(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$owner, , $projectId, $docId] = $this->seedOwnerAndDocument($em, '2');

        $client->loginUser($owner);
        // A prior GET establishes browsing history, so BrowserKit auto-sets
        // HTTP_REFERER on the POST below — without it, SameOriginCsrfTokenManager
        // sees neither an Origin/Referer match nor a real double-submit token
        // and rejects the request as a 403 regardless of controller logic.
        $client->request(Request::METHOD_GET, "/projects/$projectId/documents/$docId/review");
        // No submit_review_form[verdict] key at all — the form is not
        // considered submitted, mirroring a request without a clicked button.
        $client->request(Request::METHOD_POST, "/projects/$projectId/documents/$docId/review/submit", [
            '_csrf_token' => 'csrf-token',
        ]);

        self::assertResponseRedirects("/projects/$projectId/documents/$docId/review");
        $client->followRedirect();
        self::assertSelectorExists('.lp-flash--error');
        self::assertSelectorNotExists('.lp-flash--danger');

        $freshDoc = static::getContainer()->get(EntityManagerInterface::class)->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $freshDoc);
        self::assertSame(DocumentStatus::InReview, $freshDoc->status);
    }

    public function test_an_unrecognised_verdict_value_flashes_error_via_domain_errors(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$owner, , $projectId, $docId] = $this->seedOwnerAndDocument($em, '3');

        $client->loginUser($owner);
        // A prior GET establishes browsing history, so BrowserKit auto-sets
        // HTTP_REFERER on the POST below — without it, SameOriginCsrfTokenManager
        // sees neither an Origin/Referer match nor a real double-submit token
        // and rejects the request as a 403 regardless of controller logic.
        $client->request(Request::METHOD_GET, "/projects/$projectId/documents/$docId/review");
        $client->request(Request::METHOD_POST, "/projects/$projectId/documents/$docId/review/submit", [
            '_csrf_token' => 'csrf-token',
            'submit_review_form' => ['verdict' => 'not-a-real-verdict'],
        ]);

        self::assertResponseRedirects("/projects/$projectId/documents/$docId/review");
        $client->followRedirect();
        self::assertSelectorExists('.lp-flash--error');
        self::assertSelectorNotExists('.lp-flash--danger');

        $freshDoc = static::getContainer()->get(EntityManagerInterface::class)->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $freshDoc);
        self::assertSame(DocumentStatus::InReview, $freshDoc->status);
    }

    /**
     * NotBlank accepts "0", so it arrives as a submitted value and must travel
     * the same unrecognised-verdict path as any other unknown string rather
     * than tripping the can't-happen guard.
     */
    public function test_a_verdict_of_zero_flashes_error_via_domain_errors(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$owner, , $projectId, $docId] = $this->seedOwnerAndDocument($em, '4');

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, "/projects/$projectId/documents/$docId/review");
        $client->request(Request::METHOD_POST, "/projects/$projectId/documents/$docId/review/submit", [
            '_csrf_token' => 'csrf-token',
            'submit_review_form' => ['verdict' => '0'],
        ]);

        self::assertResponseRedirects("/projects/$projectId/documents/$docId/review");
        $client->followRedirect();
        self::assertSelectorExists('.lp-flash--error');

        $freshDoc = static::getContainer()->get(EntityManagerInterface::class)->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $freshDoc);
        self::assertSame(DocumentStatus::InReview, $freshDoc->status);
    }

    public function test_a_submitted_verdict_is_recorded_on_the_domain_channel(): void
    {
        $client = static::createClient();
        // The container must survive the POST, or the recording Auditor the
        // request used is thrown away before it can be read.
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        [$owner, , $projectId, $docId] = $this->seedOwnerAndDocument($em, 'audit');
        $audit = RecordingAuditor::installedIn(static::getContainer());

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, "/projects/$projectId/documents/$docId/review");
        $audit->forget();

        $client->request(Request::METHOD_POST, "/projects/$projectId/documents/$docId/review/submit", [
            '_csrf_token' => 'csrf-token',
            'submit_review_form' => ['verdict' => 'approved'],
        ]);

        self::assertResponseRedirects("/projects/$projectId/documents/$docId/review");

        $record = $audit->record('review.document_verdict_submitted');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('document', $record->subject->type);
        self::assertSame($docId, $record->subject->id);
        self::assertSame([
            'documentId' => $docId,
            'verdict' => 'approved',
            'reviewerId' => (string) $owner->id,
        ], $record->context);

        self::assertSame(['review.document_verdict_submitted'], $audit->domainLogLines());
        self::assertSame([], $audit->securityLogLines());
    }

    public function test_a_rejected_verdict_records_nothing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        [$owner, , $projectId, $docId] = $this->seedOwnerAndDocument($em, 'audit-refused');
        $audit = RecordingAuditor::installedIn(static::getContainer());

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, "/projects/$projectId/documents/$docId/review");
        $audit->forget();

        $client->request(Request::METHOD_POST, "/projects/$projectId/documents/$docId/review/submit", [
            '_csrf_token' => 'csrf-token',
            'submit_review_form' => ['verdict' => 'withdrawn'],
        ]);

        self::assertSame([], $audit->operations());
    }

    public function test_the_controller_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(SubmitReviewController::class);
    }
}
