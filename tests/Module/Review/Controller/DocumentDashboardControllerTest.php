<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\ValueObject\Anchor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class DocumentDashboardControllerTest extends WebTestCase
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

    private function project(EntityManagerInterface $em, User $owner): Project
    {
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);

        return $project;
    }

    private function document(EntityManagerInterface $em, User $owner, Project $project, string $title): Document
    {
        $document = new Document(owner: $owner, project: $project, title: $title);
        // A document always carries at least one version in production; the list
        // reads currentVersion(), so every seeded document must have one.
        $document->addVersion('# '.$title, '<h1>'.$title.'</h1>');
        $em->persist($document);

        return $document;
    }

    public function test_owner_sees_their_projects_documents(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createUser($em, 'alice', 'alice@example.com');
        $bob = $this->createUser($em, 'bob', 'bob@example.com');

        $aliceProject = $this->project($em, $alice);
        $this->document($em, $alice, $aliceProject, 'Alice Draft');
        $this->document($em, $bob, $this->project($em, $bob), 'Bob Secret');

        $em->flush();
        $aliceProjectId = (string) $aliceProject->id;
        $em->clear();

        $client->loginUser($alice);
        $client->request(Request::METHOD_GET, '/projects/'.$aliceProjectId.'/documents');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Alice Draft');
        self::assertStringNotContainsString('Bob Secret', (string) $client->getResponse()->getContent());
    }

    public function test_a_project_shows_only_its_own_documents(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createUser($em, 'alice2', 'alice2@example.com');
        $bob = $this->createUser($em, 'bob2', 'bob2@example.com');

        $aliceProject = $this->project($em, $alice);
        $this->document($em, $bob, $this->project($em, $bob), 'Bob Private');
        $em->flush();
        $aliceProjectId = (string) $aliceProject->id;
        $em->clear();

        $client->loginUser($alice);
        $client->request(Request::METHOD_GET, '/projects/'.$aliceProjectId.'/documents');

        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();
        self::assertStringNotContainsString('Bob Private', (string) $content);
    }

    public function test_row_shows_breadcrumb_version_pill_status_chip_and_open_threads(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createUser($em, 'alice3', 'alice3@example.com');
        $project = $this->project($em, $alice);

        $document = new Document(owner: $alice, project: $project, title: 'Rate-limit the public API');
        $document->addVersion('# v1', '<h1>v1</h1>');
        $current = $document->addVersion('# v2', '<h1>v2</h1>');
        $em->persist($document);

        // One open top-level thread + one resolved top-level thread + one
        // unresolved reply (parent set) on the current version. The meta counts
        // only unresolved *top-level* threads, so the reply must NOT bump the
        // count → the meta line should read "1 open thread".
        $open = new Comment($current, $alice, 'Please rethink the window.', Anchor::unanchored());
        $resolved = new Comment($current, $alice, 'Fixed, thanks.', Anchor::unanchored());
        $resolved->resolved = true;
        $reply = new Comment($current, $alice, 'Agreed — and one more thing.', Anchor::unanchored(), $open);
        $em->persist($open);
        $em->persist($resolved);
        $em->persist($reply);

        $em->flush();
        $projectId = (string) $project->id;
        $documentId = (string) $document->id;
        $em->clear();

        $client->loginUser($alice);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents');

        self::assertResponseIsSuccessful();

        // Breadcrumb: {project} / Documents.
        self::assertSelectorTextContains('.lp-crumbs', 'Documents');
        self::assertSelectorTextContains('.lp-crumbs', $project->name);

        $rowSelector = '[data-document-id="'.$documentId.'"]';

        // Version pill reflects the current version number.
        self::assertSelectorTextContains($rowSelector.' .lp-document-row__version', 'v2');

        // Status chip keeps the lp-badge hook and the translated status text.
        self::assertSelectorTextContains($rowSelector.' .lp-badge', 'In review');

        // Open-thread meta counts only the unresolved top-level thread.
        self::assertSelectorTextContains($rowSelector.' .lp-document-row__open', '1 open thread');
    }

    public function test_non_owner_cannot_view_a_projects_dashboard(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-dash', 'owner-dash@example.com');
        $other = $this->createUser($em, 'other-dash', 'other-dash@example.com');
        $project = $this->project($em, $owner);
        $em->flush();
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($other);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_unauthenticated_user_is_redirected(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/projects/00000000-0000-0000-0000-000000000000/documents');

        self::assertResponseRedirects('/login');
    }
}
