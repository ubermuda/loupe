<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
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
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final class SubmitReviewControllerTest extends WebTestCase
{
    public function test_a_same_version_stale_form_keeps_its_note_and_does_not_replace_the_verdict(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$owner, , $projectId, $docId] = $this->seedOwnerAndDocument($em, 'same-version');
        $client->loginUser($owner);
        $url = "/projects/$projectId/documents/$docId/review";
        $firstPage = $client->request(Request::METHOD_GET, $url);
        $olderForm = $firstPage->selectButton('Submit review')->form([
            'submit_review_form[verdict]' => 'changes-requested',
            'submit_review_form[note]' => 'Keep my unfinished feedback.',
        ]);
        $newerPage = $client->request(Request::METHOD_GET, $url);
        $client->submit($newerPage->selectButton('Submit review')->form(['submit_review_form[verdict]' => 'approved']));
        self::assertResponseRedirects($url);

        $client->submit($olderForm);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('dialog .lp-field-errors li', 'The review changed after this page loaded.');
        self::assertSelectorTextContains('textarea[name="submit_review_form[note]"]', 'Keep my unfinished feedback.');
        self::assertSelectorExists('[data-modal-reopen-value="true"]');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $document = $em->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $document);
        self::assertSame(DocumentStatus::Approved, $document->status);
        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM reviews WHERE version_id = ?', [(string) $document->currentVersion()->id]));

        $page = $client->request(Request::METHOD_GET, $url);
        $client->submit($page->filter('.lp-verdict-bar__undo button')->form());
        self::assertResponseRedirects($url);
        $page = $client->followRedirect();
        self::assertNotSame('', $page->filter('input[name="submit_review_form[expectedReviewId]"]')->attr('value'));
        $client->submit($page->selectButton('Submit review')->form(['submit_review_form[verdict]' => 'approved']));
        self::assertResponseRedirects($url);
    }

    public function test_requesting_changes_requires_a_note_and_preserves_the_verdict(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$owner, , $projectId, $docId] = $this->seedOwnerAndDocument($em, 'note');
        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, "/projects/$projectId/documents/$docId/review");
        $client->submit($crawler->selectButton('Submit review')->form([
            'submit_review_form[verdict]' => 'changes-requested',
            'submit_review_form[note]' => '   ',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('dialog .lp-field-errors li', 'Explain the changes you request in a review note.');
        self::assertSelectorExists('input[name="submit_review_form[verdict]"][value="changes-requested"]:checked');
        self::assertSelectorExists('[data-modal-reopen-value="true"]');

        $fresh = static::getContainer()->get(EntityManagerInterface::class)->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $fresh);
        self::assertSame(DocumentStatus::InReview, $fresh->status);
        $reviews = static::getContainer()->get(\App\Module\Review\Repository\ReviewRepository::class);
        self::assertNull($reviews->findNewestByVersion($fresh->currentVersion()));
    }

    public function test_a_stale_verdict_does_not_approve_a_new_version(): void
    {
        $client = static::createClient();
        $client->catchExceptions(false);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$owner, , $projectId, $docId] = $this->seedOwnerAndDocument($em, 'stale');
        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, "/projects/$projectId/documents/$docId/review");
        $form = $crawler->selectButton('Submit review')->form(['submit_review_form[verdict]' => 'approved', 'submit_review_form[note]' => 'Keep this note.']);
        self::assertSame('1', $crawler->filter('input[name="submit_review_form[versionNumber]"]')->first()->attr('value'));

        $document = $em->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $document);
        $document->addVersion('# Revised', '<h1>Revised</h1>');
        $em->flush();
        $em->clear();

        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('dialog .lp-field-errors li', 'The document has a newer version.');
        self::assertSelectorTextContains('textarea[name="submit_review_form[note]"]', 'Keep this note.');

        $fresh = static::getContainer()->get(EntityManagerInterface::class)->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $fresh);
        self::assertSame(DocumentStatus::InReview, $fresh->status);
        self::assertSame(2, $fresh->currentVersion()->versionNumber);
        $reviews = static::getContainer()->get(\App\Module\Review\Repository\ReviewRepository::class);
        self::assertNull($reviews->findNewestByVersion($fresh->currentVersion()));
    }

    public function test_a_verdict_without_a_version_is_rejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$owner, , $projectId, $docId] = $this->seedOwnerAndDocument($em, 'no-version');
        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, "/projects/$projectId/documents/$docId/review");
        $client->request(Request::METHOD_POST, "/projects/$projectId/documents/$docId/review/submit", [
            'submit_review_form' => ['_token' => 'csrf-token', 'verdict' => 'approved'],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.lp-field-errors li');
        $fresh = static::getContainer()->get(EntityManagerInterface::class)->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $fresh);
        self::assertSame(DocumentStatus::InReview, $fresh->status);
    }

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
            'submit_review_form' => ['_token' => 'csrf-token', 'verdict' => 'changes-requested', 'versionNumber' => 1, 'note' => 'Explain the retry behaviour.'],
        ]);

        self::assertResponseRedirects("/projects/$projectId/documents/$docId/review");
        $client->followRedirect();
        self::assertSelectorExists('.lp-flash--success');
        self::assertSelectorTextContains('.lp-review-verdict-note', 'Explain the retry behaviour.');

        $freshDoc = static::getContainer()->get(EntityManagerInterface::class)->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $freshDoc);
        self::assertSame(DocumentStatus::ChangesRequested, $freshDoc->status);
    }

    public function test_a_missing_verdict_field_shows_an_error_and_does_not_change_status(): void
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
        $client->request(Request::METHOD_POST, "/projects/$projectId/documents/$docId/review/submit", [
            'submit_review_form' => ['_token' => 'csrf-token', 'versionNumber' => 1],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.lp-field-errors li');
        self::assertSelectorNotExists('.lp-flash--danger');

        $freshDoc = static::getContainer()->get(EntityManagerInterface::class)->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $freshDoc);
        self::assertSame(DocumentStatus::InReview, $freshDoc->status);
    }

    public function test_an_unrecognised_verdict_value_shows_a_field_error(): void
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
            'submit_review_form' => ['_token' => 'csrf-token', 'verdict' => 'not-a-real-verdict', 'versionNumber' => 1],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.lp-field-errors li');
        self::assertSelectorNotExists('.lp-flash--danger');

        $freshDoc = static::getContainer()->get(EntityManagerInterface::class)->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $freshDoc);
        self::assertSame(DocumentStatus::InReview, $freshDoc->status);
    }

    public function test_a_verdict_of_zero_shows_a_field_error(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$owner, , $projectId, $docId] = $this->seedOwnerAndDocument($em, '4');

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, "/projects/$projectId/documents/$docId/review");
        $client->request(Request::METHOD_POST, "/projects/$projectId/documents/$docId/review/submit", [
            'submit_review_form' => ['_token' => 'csrf-token', 'verdict' => '0', 'versionNumber' => 1],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.lp-field-errors li');

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
            'submit_review_form' => ['_token' => 'csrf-token', 'verdict' => 'approved', 'versionNumber' => 1],
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
            'submit_review_form' => ['_token' => 'csrf-token', 'verdict' => 'withdrawn', 'versionNumber' => 1],
        ]);

        self::assertSame([], $audit->operations());
    }

    public function test_the_controller_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(SubmitReviewController::class);
    }
}
