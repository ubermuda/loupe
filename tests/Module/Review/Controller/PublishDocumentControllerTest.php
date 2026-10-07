<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class PublishDocumentControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(fullName: 'Owner', email: $email, password: 'hashed-password-placeholder');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());
        $this->em->persist($user);

        return $user;
    }

    private function document(User $owner, DocumentStatus $status): Document
    {
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);
        $document = new Document(owner: $owner, project: $project, title: 'Staged plan');
        $document->addVersion('# Plan', '<h1>Plan</h1>');
        $document->status = $status;
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    private function reviewUrl(Document $document): string
    {
        return '/projects/'.$document->project->id.'/documents/'.$document->id.'/review';
    }

    private function statusOf(Document $document): DocumentStatus
    {
        $this->em->clear();
        $fresh = $this->em->find(Document::class, $document->id);
        self::assertInstanceOf(Document::class, $fresh);

        return $fresh->status;
    }

    /** Driven through the button the page renders, so the action URL and the token are exercised as shipped. */
    public function test_the_publish_button_sends_a_draft_to_review(): void
    {
        $owner = $this->user('publish-web@example.com');
        $document = $this->document($owner, DocumentStatus::Draft);
        $this->client->loginUser($owner);

        $crawler = $this->client->request(Request::METHOD_GET, $this->reviewUrl($document));
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Publish')->form(), [], ['HTTP_REFERER' => 'http://localhost'.$this->reviewUrl($document)]);

        self::assertResponseRedirects($this->reviewUrl($document));
        self::assertSame(DocumentStatus::InReview, $this->statusOf($document));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.lp-review-doc__byline', 'In review');
        self::assertSelectorNotExists('[data-document-publish]');
    }

    public function test_the_button_is_absent_for_a_document_in_review(): void
    {
        $owner = $this->user('publish-web-absent@example.com');
        $document = $this->document($owner, DocumentStatus::InReview);
        $this->client->loginUser($owner);

        $this->client->request(Request::METHOD_GET, $this->reviewUrl($document));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.lp-review-doc__actions');
        self::assertSelectorNotExists('[data-document-publish]');
    }

    public function test_publishing_a_document_in_review_changes_nothing(): void
    {
        $owner = $this->user('publish-web-noop@example.com');
        $document = $this->document($owner, DocumentStatus::ChangesRequested);
        $this->client->loginUser($owner);

        $this->post($document, 'csrf-token');

        self::assertResponseRedirects($this->reviewUrl($document));
        self::assertSame(DocumentStatus::ChangesRequested, $this->statusOf($document));
    }

    public function test_an_archived_draft_is_refused_with_a_message(): void
    {
        $owner = $this->user('publish-web-archived@example.com');
        $document = $this->document($owner, DocumentStatus::Draft);
        $document->archivedAt = new \DateTimeImmutable();
        $this->em->flush();
        $this->client->loginUser($owner);

        $this->post($document, 'csrf-token');

        self::assertResponseRedirects($this->reviewUrl($document));
        self::assertSame(DocumentStatus::Draft, $this->statusOf($document));
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'An archived document cannot be sent to review.');
    }

    public function test_a_forged_token_is_refused(): void
    {
        $owner = $this->user('publish-web-csrf@example.com');
        $document = $this->document($owner, DocumentStatus::Draft);
        $this->client->loginUser($owner);

        $this->post($document, 'forged');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(DocumentStatus::Draft, $this->statusOf($document));
    }

    public function test_another_user_cannot_publish_the_document(): void
    {
        $document = $this->document($this->user('publish-web-owner@example.com'), DocumentStatus::Draft);
        $this->client->loginUser($this->user('publish-web-stranger@example.com'));

        // The same-origin sentinel passes the CSRF check, so the 403 is the voter's.
        $this->post($document, 'csrf-token');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(DocumentStatus::Draft, $this->statusOf($document));
    }

    private function post(Document $document, string $token): void
    {
        $url = '/projects/'.$document->project->id.'/documents/'.$document->id.'/publish';
        $this->client->request(Request::METHOD_POST, $url, ['_csrf_token' => $token], [], ['HTTP_REFERER' => 'http://localhost'.$url]);
    }
}
