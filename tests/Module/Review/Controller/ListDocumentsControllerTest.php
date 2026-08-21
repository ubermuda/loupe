<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Module\Review\ValueObject\Anchor;
use App\Tests\Support\AcceptedTerms;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Profiler\Profile;

final class ListDocumentsControllerTest extends WebTestCase
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

    /**
     * Through the handler rather than `new Document`, because that is what
     * maintains the search vector — a directly persisted document is invisible
     * to search.
     *
     * @param list<string> $tagNames
     */
    private function createDocument(Project $project, string $title, string $body, array $tagNames = []): Document
    {
        return (static::getContainer()->get(CreateDocumentHandler::class))(
            new CreateDocumentCommand($project, $title, $body, tagNames: $tagNames),
        );
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

    /**
     * The open-thread count is one grouped query for the page. Per row it was an
     * N+1 that nothing would notice — the list renders identically either way.
     */
    public function test_the_documents_list_counts_open_threads_in_one_query(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'countowner', 'count-owner@example.com');
        $project = $this->project($em, $owner);
        for ($i = 0; $i < 6; ++$i) {
            $this->document($em, $owner, $project, 'Doc '.$i);
        }
        $em->flush();
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $client->enableProfiler();
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents');
        self::assertResponseIsSuccessful();

        $profile = $client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        $countQueries = 0;
        $total = 0;
        foreach ($collector->getQueries() as $queries) {
            foreach ($queries as $query) {
                ++$total;
                $sql = (string) $query['sql'];
                if (str_contains($sql, 'FROM comments') && str_contains($sql, 'COUNT(')) {
                    ++$countQueries;
                }
            }
        }

        // Without this the assertion below would also pass on a request that ran
        // no queries at all.
        self::assertGreaterThan(0, $total);

        // Six documents, one grouped count. Per row this would be six.
        self::assertLessThanOrEqual(1, $countQueries);
    }

    public function test_a_documents_tags_are_rendered_on_its_row(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createUser($em, 'alice', 'alice@example.com');
        $project = $this->project($em, $alice);
        $tagged = $this->document($em, $alice, $project, 'Tagged Draft');
        $this->document($em, $alice, $project, 'Bare Draft');
        // Attached in reverse-alphabetical order: the rendered order must come
        // from the mapping, not from how the rows happen to come back.
        foreach (['Release', 'Design'] as $name) {
            $tag = new Tag($project, $name);
            $em->persist($tag);
            $tagged->tags->add($tag);
        }

        $em->flush();
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($alice);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents');

        self::assertResponseIsSuccessful();
        // Scoped to the tagged row — a page-wide count would pass even if every
        // row rendered the whole project's vocabulary.
        self::assertSame(
            ['design', 'release'],
            $crawler->filter('[data-document-id="'.$tagged->id.'"] .lp-tag')->each(
                static fn (Crawler $chip): string => trim($chip->text()),
            ),
        );
        self::assertCount(2, $crawler->filter('.lp-tag'));
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

    public function test_row_shows_version_pill_status_chip_and_open_thread_count(): void
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
        // unresolved reply (parent set) on the current version. The chip counts
        // only unresolved *top-level* threads, so the reply must NOT bump the
        // count → the chip should read "1 open".
        $open = new Comment($current, $alice, 'Please rethink the window.', Anchor::unanchored());
        $resolved = new Comment($current, $alice, 'Fixed, thanks.', Anchor::unanchored());
        $resolved->status = CommentStatus::Resolved;
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

        // The trail lives in the shell's top bar: {project} / Documents.
        self::assertSelectorTextContains('.lp-topbar__crumb', $project->name);
        self::assertSelectorTextContains('.lp-topbar__here', 'Documents');

        $rowSelector = '[data-document-id="'.$documentId.'"]';

        // Version pill reflects the current version number.
        self::assertSelectorTextContains($rowSelector.' .lp-document-row__version', 'v2');

        // Status chip keeps the lp-badge hook and the translated status text.
        self::assertSelectorTextContains($rowSelector.' .lp-badge', 'In review');

        // Open-thread chip counts only the unresolved top-level thread.
        self::assertSelectorTextContains($rowSelector.' .lp-document-row__threads', '1 open');
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

    public function test_the_filter_count_reports_the_total_not_rows_on_the_page(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createUser($em, 'alice-count', 'alice-count@example.com');
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
        // 21 match and 20 fit on the page: the count must be the match total,
        // or a filtered list reads as having matched only one page.
        self::assertCount(20, $crawler->filter('[data-document-id]'));
        self::assertSelectorTextContains('.lp-filter-count', '21 of 21 documents');
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

    /**
     * Only the MCP tool requires a reason, so most archived rows carry none. The
     * row with one shows it; the row without shows nothing at all, because an
     * empty label or a dash would read as a reason that went missing rather than
     * one that was never asked for.
     */
    public function test_an_archived_row_shows_its_reason_and_renders_nothing_when_there_is_none(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createUser($em, 'alice-reason', 'alice-reason@example.com');
        $project = $this->project($em, $alice);

        $explained = $this->document($em, $alice, $project, 'Superseded draft');
        $explained->archivedAt = new \DateTimeImmutable();
        $explained->archiveReason = 'superseded by the v2 plan';

        $silent = $this->document($em, $alice, $project, 'Tidied away');
        $silent->archivedAt = new \DateTimeImmutable();

        $em->flush();
        $projectId = (string) $project->id;
        $explainedId = (string) $explained->id;
        $silentId = (string) $silent->id;
        $em->clear();

        $client->loginUser($alice);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents?archived=1');

        self::assertResponseIsSuccessful();
        // Both rows are listed, so the absence asserted below is the template's
        // doing rather than a row that never rendered.
        self::assertCount(1, $crawler->filter('[data-document-id="'.$explainedId.'"]'));
        self::assertCount(1, $crawler->filter('[data-document-id="'.$silentId.'"]'));

        self::assertSelectorTextContains(
            '[data-document-id="'.$explainedId.'"] .lp-document-row__archive-reason',
            'superseded by the v2 plan',
        );
        self::assertCount(0, $crawler->filter('[data-document-id="'.$silentId.'"] .lp-document-row__archive-reason'));
    }

    /** A restored document must not go back into the list still explaining why it left. */
    public function test_a_live_row_never_shows_an_archive_reason(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createUser($em, 'alice-live-reason', 'alice-live-reason@example.com');
        $project = $this->project($em, $alice);

        // A reason with no archivedAt beside it is a state the handlers never
        // produce; the template must not print one anyway.
        $live = $this->document($em, $alice, $project, 'Back in the list');
        $live->archiveReason = 'superseded by the v2 plan';

        $em->flush();
        $projectId = (string) $project->id;
        $liveId = (string) $live->id;
        $em->clear();

        $client->loginUser($alice);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-document-id="'.$liveId.'"]'));
        self::assertCount(0, $crawler->filter('[data-document-id="'.$liveId.'"] .lp-document-row__archive-reason'));
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
        self::assertCount(1, $crawler->filter('.lp-list-filters .lp-filter-chip--on'));
        self::assertCount(1, $crawler->filter('.lp-list-filters input[name="search"]'));
        self::assertCount(1, $crawler->filter('.lp-list-filters select[name="status"]'));
    }

    public function test_a_search_that_matches_nothing_says_so_and_offers_a_way_back(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createUser($em, 'alice-nomatch', 'alice-nomatch@example.com');
        $project = $this->project($em, $alice);
        $em->flush();
        $projectId = (string) $project->id;
        $this->createDocument($project, 'Rate limits', 'A leaky bucket per token.');
        $em->clear();

        $client->loginUser($alice);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents?search=telemetry');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-document-id]'));
        // Not the "No documents yet" copy — the project does have one.
        self::assertSelectorTextContains('.lp-empty-state__title', 'No documents match these filters');
        self::assertCount(
            1,
            $crawler->filter('.lp-empty-state a[href="/projects/'.$projectId.'/documents"]'),
        );
        // The search box is outside the empty branch, so it is still reachable.
        self::assertCount(1, $crawler->filter('.lp-list-filters input[name="search"]'));
    }

    public function test_search_matches_the_markdown_body_and_keeps_the_term_in_the_box(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createUser($em, 'alice-search', 'alice-search@example.com');
        $project = $this->project($em, $alice);
        $em->flush();
        $projectId = (string) $project->id;
        $hit = $this->createDocument($project, 'Rate limits', 'A leaky bucket per token.');
        $miss = $this->createDocument($project, 'Onboarding', 'The first-run wizard.');
        $hitId = (string) $hit->id;
        $missId = (string) $miss->id;
        $em->clear();

        $client->loginUser($alice);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents?search=bucket');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-document-id="'.$hitId.'"]'));
        self::assertCount(0, $crawler->filter('[data-document-id="'.$missId.'"]'));
        self::assertSame('bucket', $crawler->filter('input[name="search"]')->attr('value'));
        // The page no longer promises newest-first while results are ranked.
        self::assertSelectorTextContains('.lp-workspace-desc', 'best matches first');
    }

    public function test_the_status_and_tag_filters_narrow_the_list(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createUser($em, 'alice-narrow', 'alice-narrow@example.com');
        $project = $this->project($em, $alice);
        $em->flush();
        $projectId = (string) $project->id;

        $approved = $this->createDocument($project, 'Signed off', 'Done.', ['Design']);
        $approved->status = DocumentStatus::Approved;
        $open = $this->createDocument($project, 'Still going', 'Ongoing.');
        $em->flush();
        $approvedId = (string) $approved->id;
        $openId = (string) $open->id;
        $em->clear();

        $client->loginUser($alice);

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents?status=approved');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-document-id="'.$approvedId.'"]'));
        self::assertCount(0, $crawler->filter('[data-document-id="'.$openId.'"]'));

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents?tag=design');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-document-id="'.$approvedId.'"]'));
        self::assertCount(0, $crawler->filter('[data-document-id="'.$openId.'"]'));
    }

    public function test_every_filter_survives_the_clamp_redirect_and_the_pagination_links(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createUser($em, 'alice-all-filters', 'alice-all-filters@example.com');
        $project = $this->project($em, $alice);
        $em->flush();
        $projectId = (string) $project->id;
        for ($i = 0; $i < 21; ++$i) {
            $this->createDocument($project, 'Kafka topic '.$i, 'Partitions and offsets.', ['Design']);
        }
        $em->clear();

        $client->loginUser($alice);

        $filters = 'archived=1&search=kafka&status=in-review&tag=design';
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents?'.$filters);

        self::assertResponseIsSuccessful();
        self::assertCount(20, $crawler->filter('[data-document-id]'));

        $paged = '/projects/'.$projectId.'/documents?page=2&archived=1&search=kafka&status=in-review&tag=design';
        self::assertGreaterThan(0, $crawler->filter('.lp-pagination a[href="'.$paged.'"]')->count());

        // Following the link rather than only rendering it: a filter can be
        // correct in an href and still be lost when the next request re-parses
        // it. (Whether the ordering's id tiebreak holds is not testable here —
        // Postgres returns a stable order for this plan with or without it.)
        $firstPage = $crawler->filter('[data-document-id]')->each(
            static fn (Crawler $row): string => (string) $row->attr('data-document-id'),
        );
        $crawler = $client->request(Request::METHOD_GET, $paged);

        self::assertResponseIsSuccessful();
        $secondPage = $crawler->filter('[data-document-id]')->each(
            static fn (Crawler $row): string => (string) $row->attr('data-document-id'),
        );
        self::assertCount(1, $secondPage);
        self::assertSame([], array_intersect($firstPage, $secondPage));

        // The archived chip is a link built outside the form, so it is the one
        // most likely to drop the rest of the bar.
        self::assertGreaterThan(
            0,
            $crawler->filter('.lp-list-filters a[href*="search=kafka"][href*="tag=design"]')->count(),
        );

        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents?page=9&'.$filters);
        self::assertResponseRedirects($paged);
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
