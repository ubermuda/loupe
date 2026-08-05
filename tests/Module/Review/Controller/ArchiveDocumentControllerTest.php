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
            fullName: ucfirst($username),
            email: $email,
            password: 'hashed-password-placeholder',
        );
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);

        return $user;
    }

    private function document(EntityManagerInterface $em, User $owner, Project $project, string $title): Document
    {
        $document = new Document(owner: $owner, project: $project, title: $title);
        $document->addVersion('# '.$title, '<h1>'.$title.'</h1>');
        $em->persist($document);

        return $document;
    }

    private function project(EntityManagerInterface $em, User $owner): Project
    {
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);

        return $project;
    }

    /**
     * Driven through the form the list actually renders, so the form name, the
     * action URL and the CSRF token are all exercised as shipped rather than
     * assumed.
     */
    public function test_archiving_from_the_list_returns_to_the_same_page_and_filter(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createUser($em, 'alice-arch', 'alice-arch@example.com');
        $project = $this->project($em, $alice);
        // Enough documents for a second page, so the redirect has a page number
        // worth preserving rather than the default.
        for ($i = 0; $i < 21; ++$i) {
            $this->document($em, $alice, $project, 'Doc '.$i);
        }
        $em->flush();
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($alice);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents?page=2&archived=1');
        self::assertResponseIsSuccessful();

        $row = $crawler->filter('[data-document-id]')->first();
        $documentId = (string) $row->attr('data-document-id');
        $client->submit($row->filter('form')->form());

        self::assertResponseRedirects('/projects/'.$projectId.'/documents?page=2&archived=1');

        $em->clear();
        $fresh = $em->find(Document::class, $documentId);
        self::assertInstanceOf(Document::class, $fresh);
        self::assertNotNull($fresh->archivedAt);
    }

    public function test_unarchiving_puts_the_document_back_in_the_list(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createUser($em, 'alice-unarch', 'alice-unarch@example.com');
        $project = $this->project($em, $alice);
        $document = $this->document($em, $alice, $project, 'Bring me back');
        $document->archivedAt = new \DateTimeImmutable();
        $em->flush();
        $projectId = (string) $project->id;
        $documentId = (string) $document->id;
        $em->clear();

        $client->loginUser($alice);
        // The document is archived, so it is only on screen with the filter on.
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents?archived=1');
        self::assertResponseIsSuccessful();

        $client->submit($crawler->filter('[data-document-id="'.$documentId.'"] form')->form());

        self::assertResponseRedirects('/projects/'.$projectId.'/documents?page=1&archived=1');

        $em->clear();
        $fresh = $em->find(Document::class, $document->id);
        self::assertInstanceOf(Document::class, $fresh);
        self::assertNull($fresh->archivedAt);
    }

    /**
     * A POST carrying no form submission at all — the shape a forged or stale
     * request takes. It must change nothing and say so, rather than archiving.
     */
    public function test_a_post_without_the_forms_token_changes_nothing(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createUser($em, 'alice-arch-notoken', 'alice-arch-notoken@example.com');
        $project = $this->project($em, $alice);
        $document = $this->document($em, $alice, $project, 'Leave me alone');
        $em->flush();
        $projectId = (string) $project->id;
        $documentId = (string) $document->id;
        $em->clear();

        $client->loginUser($alice);
        $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/documents/'.$documentId.'/archive');

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
        $project = $this->project($em, $alice);
        $document = $this->document($em, $alice, $project, 'Archived but readable');
        $document->archivedAt = new \DateTimeImmutable();
        $em->flush();
        $projectId = (string) $project->id;
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
        $project = $this->project($em, $owner);
        $document = $this->document($em, $owner, $project, 'Not yours');
        $em->flush();
        $projectId = (string) $project->id;
        $documentId = (string) $document->id;
        $em->clear();

        // The voter runs before the form is built, so no token is needed to
        // prove the denial is authorization rather than CSRF.
        $client->loginUser($other);
        $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/documents/'.$documentId.'/archive');

        self::assertResponseStatusCodeSame(403);

        $em->clear();
        $fresh = $em->find(Document::class, $document->id);
        self::assertInstanceOf(Document::class, $fresh);
        self::assertNull($fresh->archivedAt);
    }
}
