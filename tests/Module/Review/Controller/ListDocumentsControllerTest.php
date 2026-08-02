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

final class ListDocumentsControllerTest extends WebTestCase
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

    public function test_paginates_at_twenty_per_page(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createUser($em, 'alice-paginated', 'alice-paginated@example.com');
        $project = $this->project($em, $alice);
        for ($i = 0; $i < 21; ++$i) {
            $this->document($em, $alice, $project, 'Doc '.$i);
        }
        $em->flush();
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($alice);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents');

        self::assertResponseIsSuccessful();
        self::assertCount(20, $crawler->filter('[data-document-id]'));
        self::assertSelectorTextContains('.lp-pagination__status', 'Page 1 of 2');

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents?page=2');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-document-id]'));
    }

    public function test_out_of_range_page_redirects_to_the_last_page(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createUser($em, 'alice-clamp', 'alice-clamp@example.com');
        $project = $this->project($em, $alice);
        $this->document($em, $alice, $project, 'Only doc');
        $em->flush();
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($alice);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents?page=5');

        self::assertResponseRedirects('/projects/'.$projectId.'/documents?page=1');
    }

    public function test_archived_documents_drop_out_of_the_list_until_the_filter_is_on(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createUser($em, 'alice-archive', 'alice-archive@example.com');
        $project = $this->project($em, $alice);
        $live = $this->document($em, $alice, $project, 'Still open');
        $archived = $this->document($em, $alice, $project, 'Put away');
        $archived->archivedAt = new \DateTimeImmutable();

        $em->flush();
        $projectId = (string) $project->id;
        $liveId = (string) $live->id;
        $archivedId = (string) $archived->id;
        $em->clear();

        $client->loginUser($alice);
        // Asserted on the row hook rather than the page text: a title appearing
        // in a flash or a tooltip would satisfy a whole-body search without the
        // document being listed at all.
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-document-id="'.$liveId.'"]'));
        self::assertCount(0, $crawler->filter('[data-document-id="'.$archivedId.'"]'));

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents?archived=1');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-document-id="'.$liveId.'"]'));
        self::assertCount(1, $crawler->filter('[data-document-id="'.$archivedId.'"]'));
        self::assertSelectorTextContains('[data-document-id="'.$archivedId.'"] .lp-document-row__archived', 'Archived');
    }

    public function test_the_sidebar_and_project_card_counts_exclude_archived_documents(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createUser($em, 'alice-counts', 'alice-counts@example.com');
        $project = $this->project($em, $alice);
        $this->document($em, $alice, $project, 'Still open');
        $this->document($em, $alice, $project, 'Also open');
        $put = $this->document($em, $alice, $project, 'Put away');
        $put->archivedAt = new \DateTimeImmutable();

        $em->flush();
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($alice);

        // The nav pill sits directly above the list; three documents exist and
        // two are listed, so the pill must read 2.
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-document-id]'));
        self::assertSame('2', trim($crawler->filter('.lp-sidebar__pill')->first()->text()));

        // Same number on the project card.
        $client->request(Request::METHOD_GET, '/projects');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-project-id="'.$projectId.'"] .lp-project-row__meta', '2 documents');
    }

    public function test_the_filter_bar_survives_an_empty_list(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createUser($em, 'alice-empty', 'alice-empty@example.com');
        $project = $this->project($em, $alice);
        $em->flush();
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($alice);
        // With no documents at all the list is empty; the filter must still be
        // on screen, or a filter that matched nothing would be unturn-off-able.
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents?archived=1');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.lp-list-filters .lp-filter-chip'));
    }

    public function test_the_filter_survives_the_clamp_redirect_and_the_pagination_links(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createUser($em, 'alice-filter-page', 'alice-filter-page@example.com');
        $project = $this->project($em, $alice);
        for ($i = 0; $i < 21; ++$i) {
            $this->document($em, $alice, $project, 'Doc '.$i);
        }
        $em->flush();
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($alice);

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents?archived=1');
        self::assertResponseIsSuccessful();
        // The next-page arrow and the "2" both point at it; what matters is that
        // neither dropped the filter.
        self::assertGreaterThan(
            0,
            $crawler->filter('.lp-pagination a[href="/projects/'.$projectId.'/documents?page=2&archived=1"]')->count(),
        );
        self::assertCount(0, $crawler->filter('.lp-pagination a[href="/projects/'.$projectId.'/documents?page=2"]'));

        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents?page=9&archived=1');
        self::assertResponseRedirects('/projects/'.$projectId.'/documents?page=2&archived=1');
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
