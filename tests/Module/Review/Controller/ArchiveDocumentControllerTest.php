<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ArchiveDocumentControllerTest extends WebTestCase
{
    /** @param non-empty-string $email */
    private function createUser(EntityManagerInterface $em, string $username, string $email): User
    {
        $user = new User(
            username: $username,
            fullName: ucfirst($username),
            email: $email,
            password: 'hashed-password-placeholder',
        );
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);

        return $user;
    }

    private function document(EntityManagerInterface $em, User $owner, string $title): Document
    {
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);

        $document = new Document(owner: $owner, project: $project, title: $title);
        $document->addVersion('# '.$title, '<h1>'.$title.'</h1>');
        $em->persist($document);

        return $document;
    }

    public function test_archiving_from_the_list_returns_to_the_same_page_and_filter(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createUser($em, 'alice-arch', 'alice-arch@example.com');
        $document = $this->document($em, $alice, 'Put me away');
        $em->flush();
        $projectId = (string) $document->project->id;
        $documentId = (string) $document->id;
        $em->clear();

        $client->loginUser($alice);
        // A prior GET establishes browsing history, so BrowserKit auto-sets
        // HTTP_REFERER on the POST below — without it, SameOriginCsrfTokenManager
        // sees neither an Origin/Referer match nor a real double-submit token
        // and rejects the request as a 403 regardless of controller logic.
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents');
        $client->request(
            Request::METHOD_POST,
            '/projects/'.$projectId.'/documents/'.$documentId.'/archive?page=3&archived=1',
            ['_csrf_token' => 'csrf-token'],
        );

        self::assertResponseRedirects('/projects/'.$projectId.'/documents?page=3&archived=1');

        $em->clear();
        $fresh = $em->find(Document::class, $document->id);
        self::assertInstanceOf(Document::class, $fresh);
        self::assertNotNull($fresh->archivedAt);
    }

    public function test_unarchiving_puts_the_document_back_in_the_list(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createUser($em, 'alice-unarch', 'alice-unarch@example.com');
        $document = $this->document($em, $alice, 'Bring me back');
        $document->archivedAt = new \DateTimeImmutable();
        $em->flush();
        $projectId = (string) $document->project->id;
        $documentId = (string) $document->id;
        $em->clear();

        $client->loginUser($alice);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents?archived=1');
        $client->request(
            Request::METHOD_POST,
            '/projects/'.$projectId.'/documents/'.$documentId.'/unarchive',
            ['_csrf_token' => 'csrf-token'],
        );

        self::assertResponseRedirects('/projects/'.$projectId.'/documents?page=1');

        $em->clear();
        $fresh = $em->find(Document::class, $document->id);
        self::assertInstanceOf(Document::class, $fresh);
        self::assertNull($fresh->archivedAt);
    }

    /**
     * Archiving takes a document out of the list, not out of the app: every
     * review URL already handed out must keep working.
     */
    public function test_an_archived_document_still_opens_at_its_review_url(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createUser($em, 'alice-arch-url', 'alice-arch-url@example.com');
        $document = $this->document($em, $alice, 'Archived but readable');
        $document->archivedAt = new \DateTimeImmutable();
        $em->flush();
        $projectId = (string) $document->project->id;
        $documentId = (string) $document->id;
        $em->clear();

        $client->loginUser($alice);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$documentId.'/review');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.lp-review-doc__title', 'Archived but readable');
    }

    public function test_a_non_owner_cannot_archive_a_document(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-arch', 'owner-arch@example.com');
        $other = $this->createUser($em, 'other-arch', 'other-arch@example.com');
        $document = $this->document($em, $owner, 'Not yours');
        $em->flush();
        $projectId = (string) $document->project->id;
        $documentId = (string) $document->id;
        $em->clear();

        $client->loginUser($other);
        // Their own project list, purely to establish a same-origin referer — so
        // the 403 below is the voter denying, not CSRF rejecting the POST.
        $client->request(Request::METHOD_GET, '/projects');
        $client->request(
            Request::METHOD_POST,
            '/projects/'.$projectId.'/documents/'.$documentId.'/archive',
            ['_csrf_token' => 'csrf-token'],
        );

        self::assertResponseStatusCodeSame(403);

        $em->clear();
        $fresh = $em->find(Document::class, $document->id);
        self::assertInstanceOf(Document::class, $fresh);
        self::assertNull($fresh->archivedAt);
    }
}
