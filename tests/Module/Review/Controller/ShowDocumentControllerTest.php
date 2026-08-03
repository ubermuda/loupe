<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Service\MarkdownRenderer;
use App\Module\Review\ValueObject\Anchor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\UX\Turbo\TurboBundle;

final class ShowDocumentControllerTest extends WebTestCase
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

    public function test_owner_sees_review_page(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner1', 'owner1@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'My Review Doc');
        $doc->addVersion('# Hello', '<h1>Hello</h1>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'My Review Doc');
        self::assertSelectorExists('.lp-review-sidebar');
    }

    public function test_review_page_renders_byline_and_verdict_bar(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'ribbonowner', 'ribbon@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Ribbon Doc');
        $doc->addVersion('# Hello', '<h1>Hello</h1>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseIsSuccessful();
        // Byline names the reviewer (the author side is the literal "Claude").
        self::assertSelectorTextContains('.lp-review-doc__byline', 'reviewed by');
        // Verdict bar shows both verdict buttons and NOT the approved confirmation.
        self::assertSelectorExists('button[name="submit_review_form[verdict]"][value="approved"]');
        self::assertSelectorExists('button[name="submit_review_form[verdict]"][value="changes-requested"]');
        self::assertSelectorNotExists('.lp-verdict-approved');
    }

    public function test_review_page_groups_threads_into_status_ladder(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'ladderowner', 'ladder@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Ladder Doc');
        $version = $doc->addVersion('# Body', '<h1>Body</h1>');
        $em->persist($doc);

        $pending = new Comment($version, $owner, 'A pending comment', new Anchor('Body', '', '', 0));
        $resolved = new Comment($version, $owner, 'A resolved comment', new Anchor('Body', '', '', 10));
        $resolved->status = CommentStatus::Resolved;
        $em->persist($pending);
        $em->persist($resolved);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseIsSuccessful();
        // Both ladder eyebrows render (grouping done in Twig).
        self::assertSelectorTextContains('.lp-ladder-group__eyebrow--pending', 'Pending');
        self::assertSelectorTextContains('.lp-ladder-group__eyebrow--resolved', 'Resolved');
        // Each thread carries its derived anchor status + a status chip.
        self::assertSelectorExists('[data-anchor-status="pending"]');
        self::assertSelectorExists('[data-anchor-status="resolved"]');
        self::assertSelectorExists('.lp-comment-thread--resolved');
    }

    public function test_resolving_a_comment_returns_the_whole_list_stream_regrouped(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'resolveowner', 'resolve@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Resolve Doc');
        $version = $doc->addVersion('# Body', '<h1>Body</h1>');
        $em->persist($doc);

        $comment = new Comment($version, $owner, 'Please fix this', new Anchor('Body', '', '', 0));
        $em->persist($comment);
        $em->flush();

        $commentId = (string) $comment->id;
        $em->clear();

        $client->loginUser($owner);
        // 'csrf-token' is the SameOriginCsrfTokenManager sentinel: a same-origin
        // Referer lets it stand in for the stateless comment-action token. The
        // Turbo Accept header selects the stream branch over the redirect fallback.
        $client->request(
            Request::METHOD_POST,
            '/comments/'.$commentId.'/resolve',
            ['_csrf_token' => 'csrf-token'],
            server: [
                'HTTP_ACCEPT' => TurboBundle::STREAM_MEDIA_TYPE,
                'HTTP_REFERER' => 'http://localhost/comments/'.$commentId.'/resolve',
            ],
        );

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        // The stream replaces the whole #comment-threads region, not a single card.
        self::assertStringContainsString('target="comment-threads"', $content);
        self::assertStringNotContainsString('target="comment-thread-'.$commentId.'"', $content);

        // The re-rendered list places the card under RESOLVED with a refreshed
        // count (1/1) and a full progress bar.
        self::assertStringContainsString('lp-ladder-group__eyebrow--resolved', $content);
        self::assertStringContainsString('lp-comment-thread--resolved', $content);
        self::assertStringContainsString('1/1 resolved', $content);
        self::assertStringContainsString('width: 100%', $content);

        // The comment is actually persisted as resolved.
        $fetched = $em->find(Comment::class, $comment->id);
        self::assertNotNull($fetched);
        self::assertSame(CommentStatus::Resolved, $fetched->status);
    }

    public function test_approved_document_shows_locked_confirmation(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'approvedowner', 'approved@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Approved Doc');
        $doc->addVersion('# Done', '<h1>Done</h1>');
        $doc->status = DocumentStatus::Approved;
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseIsSuccessful();
        // Approved state replaces the verdict buttons with a locked confirmation.
        self::assertSelectorExists('.lp-verdict-approved');
        self::assertSelectorNotExists('button[name="submit_review_form[verdict]"]');
    }

    public function test_non_owner_gets_403(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner2', 'owner2@example.com');
        $other = $this->createUser($em, 'other2', 'other2@example.com');

        $project = $this->project($em, $owner);
        $doc = new Document(owner: $owner, project: $project, title: 'Owner Only Doc');
        $doc->addVersion('# Private', '<h1>Private</h1>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($other);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_document_under_the_wrong_project_is_not_found(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner3', 'owner3@example.com');
        $projectA = $this->project($em, $owner);
        $projectB = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $projectA, title: 'Belongs To A');
        $doc->addVersion('# A', '<h1>A</h1>');
        $em->persist($doc);
        $em->flush();

        $projectBId = (string) $projectB->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectBId.'/documents/'.$id.'/review');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The reported bug, end to end: resolve a thread, revise the document, and
     * the discussion vanishes from the app. A revision carries only the still-open
     * comments forward, so the resolved thread stays on the version it was written
     * on — which had no way to be rendered.
     */
    public function test_a_thread_resolved_before_a_revision_stays_readable_on_its_own_version(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $reviseDocument = static::getContainer()->get(ReviseDocumentHandler::class);

        $owner = $this->createUser($em, 'owner-v', 'owner-v@example.com');
        $project = $this->project($em, $owner);
        $doc = new Document(owner: $owner, project: $project, title: 'Revised Doc');
        $version = $doc->addVersion('# Body', '<h1>Body</h1>');
        $em->persist($doc);

        $resolved = new Comment($version, $owner, 'Settled in v1', new Anchor('Body', '', '', 0));
        $resolved->status = CommentStatus::Resolved;
        $em->persist($resolved);
        $em->flush();

        ($reviseDocument)(new ReviseDocumentCommand($doc, '# Rewritten', 'Rewrote the body.'));

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();
        $client->loginUser($owner);

        // The current version is where the bug shows: the sidebar is empty.
        $latest = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Settled in v1', $latest->text());

        // The switcher is the way back — a route with no entry point would leave
        // the discussion exactly as unreachable as before.
        $versionUrl = '/projects/'.$projectId.'/documents/'.$id.'/review/versions/1';
        self::assertCount(1, $latest->filter('.lp-version-switcher a[href="'.$versionUrl.'"]'));

        $earlier = $client->request(Request::METHOD_GET, $versionUrl);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Settled in v1', $earlier->text());
        self::assertStringContainsString('Body', $earlier->filter('.lp-review-doc__prose')->text());
    }

    public function test_an_earlier_version_offers_no_control_that_would_write_to_the_current_one(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $reviseDocument = static::getContainer()->get(ReviseDocumentHandler::class);

        $owner = $this->createUser($em, 'owner-w', 'owner-w@example.com');
        $project = $this->project($em, $owner);
        $doc = new Document(owner: $owner, project: $project, title: 'Read Only Doc');
        $version = $doc->addVersion('# Body', '<h1>Body</h1>');
        $em->persist($doc);
        $em->persist(new Comment($version, $owner, 'Still open', new Anchor('Body', '', '', 0)));
        $em->flush();

        ($reviseDocument)(new ReviseDocumentCommand($doc, '# Body', 'No content change.'));

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();
        $client->loginUser($owner);

        $earlier = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review/versions/1');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $earlier->filter('.lp-comment-composer'), 'the composer posts onto the current version');
        self::assertCount(0, $earlier->filter('.lp-anchor-toolbar'));
        self::assertCount(0, $earlier->filter('.lp-verdict-bar'), 'the verdict applies to the document as it stands');
        self::assertCount(0, $earlier->filter('.lp-comment-thread form'), 'reply, resolve and delete all act on the live discussion');

        // Same page on the current version, to prove the assertions above are not
        // passing simply because the selectors never match anything.
        $latest = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');
        self::assertCount(1, $latest->filter('.lp-comment-composer'));
        self::assertCount(1, $latest->filter('.lp-verdict-bar'));
        self::assertGreaterThan(0, $latest->filter('.lp-comment-thread form')->count());
    }

    public function test_an_unknown_version_number_is_not_found(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-x', 'owner-x@example.com');
        $project = $this->project($em, $owner);
        $doc = new Document(owner: $owner, project: $project, title: 'One Version');
        $doc->addVersion('# Body', '<h1>Body</h1>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review/versions/7');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_each_version_entry_shows_what_that_version_changed(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-desc', 'owner-desc@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Described Doc');
        $doc->addVersion('# v1', '<h1>v1</h1>', 'The original brief.');
        $doc->addVersion('# v2', '<h1>v2</h1>', 'Replaced the rollout section with a phased plan.');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseIsSuccessful();

        $descriptions = $crawler->filter('.lp-version-entry__description')->each(
            static fn (\Symfony\Component\DomCrawler\Crawler $node): string => trim($node->text()),
        );

        self::assertSame(
            ['Replaced the rollout section with a phased plan.', 'The original brief.'],
            $descriptions,
        );
    }

    /**
     * A document with one version has no switching to do, but its description is
     * the only account of what that version is — hiding the list would store text
     * nothing ever shows.
     */
    public function test_a_single_version_document_still_shows_its_description(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-desc-one', 'owner-desc-one@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Single Version Doc');
        $doc->addVersion('# v1', '<h1>v1</h1>', 'The original brief.');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.lp-version-entry__description', 'The original brief.');
    }

    /**
     * The mirror of the case above: with one version and nothing to say about
     * it, the list has no destination and no text, so it is omitted rather than
     * rendered as a lone pill. Every document created before descriptions
     * existed is in this state.
     */
    public function test_a_single_version_document_with_no_description_renders_no_version_list(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-desc-none', 'owner-desc-none@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Undescribed Doc');
        $doc->addVersion('# v1', '<h1>v1</h1>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.lp-version-switcher'));
    }

    /**
     * A description on any version earns the list even when only one version has
     * one — otherwise the descriptions on a multi-version document would be the
     * only ones ever shown.
     */
    public function test_several_versions_without_descriptions_still_render_the_switcher(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-desc-multi', 'owner-desc-multi@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Two Bare Versions');
        $doc->addVersion('# v1', '<h1>v1</h1>');
        $doc->addVersion('# v2', '<h1>v2</h1>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.lp-version-switcher'));
        self::assertCount(0, $crawler->filter('.lp-version-entry__description'));
    }

    public function test_the_table_of_contents_links_to_headings_from_outside_the_anchoring_target(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-contents', 'owner-contents@example.com');
        $project = $this->project($em, $owner);

        // Rendered for real: the heading ids the table of contents links to only
        // exist in MarkdownRenderer's output.
        $markdown = "## First\n\nBody.\n\n## Second\n\nMore.\n";
        $doc = new Document(owner: $owner, project: $project, title: 'Sectioned Doc');
        $doc->addVersion($markdown, new MarkdownRenderer(new NullLogger())->render($markdown));
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['#heading-first', '#heading-second'],
            $crawler->filter('.lp-review-contents__link')->each(static fn ($node): string => (string) $node->attr('href')),
        );
        // The panel must sit outside the prose container, whose textContent has to
        // stay identical to DocumentVersion::plainText() for anchors to resolve.
        self::assertCount(0, $crawler->filter('[data-comment-anchor-target="doc"] .lp-review-contents'));
    }

    public function test_a_document_with_one_heading_renders_no_table_of_contents(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-nocontents', 'owner-nocontents@example.com');
        $project = $this->project($em, $owner);

        $markdown = "## Only\n\nBody.\n";
        $doc = new Document(owner: $owner, project: $project, title: 'Flat Doc');
        $doc->addVersion($markdown, new MarkdownRenderer(new NullLogger())->render($markdown));
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.lp-review-contents'));
    }

    public function test_unauthenticated_user_is_redirected(): void
    {
        $client = static::createClient();
        $client->request(
            Request::METHOD_GET,
            '/projects/00000000-0000-0000-0000-000000000000/documents/00000000-0000-0000-0000-000000000000/review',
        );

        self::assertResponseRedirects('/login');
    }
}
