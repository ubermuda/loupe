<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Command\SetDocumentHighlightsCommand;
use App\Module\Review\Command\SetDocumentHighlightsHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Tag;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Service\MarkdownRenderer;
use App\Module\Review\ValueObject\Anchor;
use App\Tests\Support\AcceptedTerms;
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
        // The two columns the page is built from: the document being read, and the
        // margin the comment cards are positioned in beside it.
        self::assertSelectorExists('.lp-review-doc');
        self::assertSelectorExists('.lp-review-margin');
    }

    public function test_review_page_renders_the_document_tags(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'tagowner', 'tagowner@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Tagged Doc');
        $doc->addVersion('# Hello', '<h1>Hello</h1>');
        $tag = new Tag($project, 'architecture');
        $em->persist($tag);
        $doc->tags->add($tag);
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.lp-tag', 'architecture');
    }

    public function test_review_page_renders_byline_and_verdict_actions(): void
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
        // While the document is still in review both verdict buttons are offered;
        // the bar that reports a verdict only replaces them once there is one.
        self::assertSelectorExists('button[name="submit_review_form[verdict]"][value="approved"]');
        self::assertSelectorExists('button[name="submit_review_form[verdict]"][value="changes-requested"]');
        self::assertSelectorNotExists('.lp-verdict-bar');
    }

    public function test_agent_highlights_are_carried_outside_the_document_pane(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'markowner', 'marks@example.com');
        $project = $this->project($em, $owner);

        $html = '<h1>Hello</h1><p>We will issue short-lived JWTs.</p>';
        $doc = new Document(owner: $owner, project: $project, title: 'Marked Doc');
        $version = $doc->addVersion('# Hello', $html);
        $em->persist($doc);
        $em->flush();

        $handler = static::getContainer()->get(SetDocumentHighlightsHandler::class);
        self::assertInstanceOf(SetDocumentHighlightsHandler::class, $handler);
        $handler(new SetDocumentHighlightsCommand($doc, ['short-lived JWTs']));

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseIsSuccessful();
        $marks = $crawler->filter('[data-comment-anchor-target="agentHighlight"]');
        self::assertCount(1, $marks);
        self::assertSame('short-lived JWTs', $marks->attr('data-anchor-quote'));

        // The pane's text must still equal the basis every anchor offset is
        // counted against, so the carriers cannot have landed inside it.
        $pane = $crawler->filter('[data-comment-anchor-target="doc"]');
        self::assertSame($version->plainText(), $pane->text(null, false));
        self::assertCount(0, $pane->filter('[data-comment-anchor-target="agentHighlight"]'));
    }

    /**
     * A card's position in the margin is what says which passage it belongs to, so
     * the column is not grouped or sorted by status. Each card states its own
     * status instead.
     */
    public function test_every_thread_states_its_own_status_in_one_ungrouped_column(): void
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
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseIsSuccessful();
        // Both anchored threads sit in the same margin column, in no status order.
        self::assertCount(2, $crawler->filter('.lp-review-margin .lp-comment-thread'));
        // Each thread carries its derived anchor status and says it in words.
        self::assertSelectorExists('[data-anchor-status="pending"]');
        self::assertSelectorExists('[data-anchor-status="resolved"]');
        self::assertSelectorTextContains('.lp-comment-status--pending', 'Open');
        self::assertSelectorTextContains('.lp-comment-status--resolved', 'Resolved');
        self::assertSelectorExists('.lp-comment-thread--resolved');
    }

    public function test_the_topbar_reports_the_thread_signals_of_the_version_on_screen(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'signalowner', 'signals@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Signal Doc');
        $version = $doc->addVersion('# Body', '<h1>Body</h1>');
        $em->persist($doc);

        $addressed = new Comment($version, $owner, 'An addressed comment', new Anchor('Body', '', '', 0));
        $addressed->status = CommentStatus::Addressed;
        $pending = new Comment($version, $owner, 'A pending comment', new Anchor('Body', '', '', 10));
        $resolved = new Comment($version, $owner, 'A resolved comment', new Anchor('Body', '', '', 20));
        $resolved->status = CommentStatus::Resolved;
        // A reply is not a thread, so neither the summary nor the chip counts it.
        $reply = new Comment($version, $owner, 'A reply', $pending->anchor, $pending);
        $em->persist($addressed);
        $em->persist($pending);
        $em->persist($resolved);
        $em->persist($reply);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.lp-topbar__meta', '2 open · 1 resolved');
        self::assertSelectorTextContains('.lp-signal--addressed', '1 addressed');
        self::assertSelectorNotExists('.lp-signal--answered');
    }

    public function test_resolving_a_comment_returns_the_whole_list_as_one_stream(): void
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

        // The re-rendered card reports the new status, and the region it sits in
        // carries the refreshed resolved tally.
        self::assertStringContainsString('lp-comment-thread--resolved', $content);
        self::assertStringContainsString('lp-comment-status--resolved', $content);
        self::assertStringContainsString('data-resolved-count="1"', $content);

        // The comment is actually persisted as resolved.
        $fetched = $em->find(Comment::class, $comment->id);
        self::assertNotNull($fetched);
        self::assertSame(CommentStatus::Resolved, $fetched->status);
    }

    public function test_reopening_a_resolved_comment_returns_the_whole_list_as_one_stream(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'reopenowner', 'reopen@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Reopen Doc');
        $version = $doc->addVersion('# Body', '<h1>Body</h1>');
        $em->persist($doc);

        $comment = new Comment($version, $owner, 'Please fix this', new Anchor('Body', '', '', 0));
        $comment->status = CommentStatus::Resolved;
        $em->persist($comment);
        $em->flush();

        $commentId = (string) $comment->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(
            Request::METHOD_POST,
            '/comments/'.$commentId.'/reopen',
            ['_csrf_token' => 'csrf-token'],
            server: [
                'HTTP_ACCEPT' => TurboBundle::STREAM_MEDIA_TYPE,
                'HTTP_REFERER' => 'http://localhost/comments/'.$commentId.'/reopen',
            ],
        );

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('target="comment-threads"', $content);
        self::assertStringNotContainsString('lp-comment-thread--resolved', $content);
        self::assertStringContainsString('data-resolved-count="0"', $content);

        $fetched = $em->find(Comment::class, $comment->id);
        self::assertNotNull($fetched);
        self::assertSame(CommentStatus::Pending, $fetched->status);
    }

    public function test_undoing_a_verdict_returns_the_document_to_review(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'undoowner', 'undo@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Undo Doc');
        $version = $doc->addVersion('# Done', '<h1>Done</h1>');
        $doc->status = DocumentStatus::Approved;
        $em->persist($doc);
        $em->persist(new Review($version, Verdict::Approved, $owner));
        $em->flush();

        $projectId = (string) $project->id;
        $documentId = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$documentId.'/review');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.lp-verdict-bar__undo'));

        $client->request(
            Request::METHOD_POST,
            '/projects/'.$projectId.'/documents/'.$documentId.'/review/undo',
            ['_csrf_token' => 'csrf-token'],
            server: ['HTTP_REFERER' => 'http://localhost/projects/'.$projectId.'/documents/'.$documentId.'/review'],
        );

        self::assertResponseRedirects('/projects/'.$projectId.'/documents/'.$documentId.'/review');

        $fetched = $em->find(Document::class, $doc->id);
        self::assertNotNull($fetched);
        self::assertSame(DocumentStatus::InReview, $fetched->status);

        // Appended, not deleted — the approval is still under the withdrawal.
        $log = $em->getRepository(Review::class)->findBy(
            ['version' => $fetched->currentVersion()],
            ['sequence' => 'ASC'],
        );
        self::assertCount(2, $log);
        self::assertSame(Verdict::Approved, $log[0]->verdict);
        self::assertSame(Verdict::Withdrawn, $log[1]->verdict);
        self::assertSame((string) $owner->id, (string) $log[1]->reviewer->id, 'The log records who withdrew it');

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$documentId.'/review');
        self::assertSelectorNotExists('.lp-verdict-bar');
        self::assertCount(2, $crawler->filter('button[name="submit_review_form[verdict]"]'));
    }

    public function test_a_comment_card_shows_its_age_and_an_undated_one_shows_none(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'ageowner', 'age@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Age Doc');
        $version = $doc->addVersion('# Body', '<h1>Body</h1>');
        $em->persist($doc);

        $dated = new Comment($version, $owner, 'Written a while ago', new Anchor('Body', '', '', 0), createdAt: new \DateTimeImmutable('-2 hours'));
        // Comments predating the column hydrate with a null createdAt; a fresh row
        // cannot, so it is written null here to stand in for one.
        $undated = new Comment($version, $owner, 'Written before the column', new Anchor('Body', '', '', 10), createdAt: null);
        $em->persist($dated);
        $em->persist($undated);
        $em->flush();

        $projectId = (string) $project->id;
        $documentId = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$documentId.'/review');

        self::assertResponseIsSuccessful();
        $ages = $crawler->filter('.lp-comment-age');
        self::assertCount(1, $ages, 'Only the dated comment has an age to show');
        self::assertSame('2h ago', trim($ages->text()));
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
        // Once a verdict exists it replaces the buttons that produced it.
        self::assertSelectorExists('.lp-verdict-bar--approved');
        self::assertSelectorTextContains('.lp-verdict-bar__title', 'Approved');
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
        self::assertCount(0, $earlier->filter('form[name="strike_passage_form"]'), 'a strike would land on the wrong version');
        self::assertCount(0, $earlier->filter('button[name="submit_review_form[verdict]"]'), 'the verdict applies to the document as it stands');
        self::assertCount(0, $earlier->filter('.lp-comment-thread form'), 'reply, resolve and delete all act on the live discussion');

        // Same page on the current version, to prove the assertions above are not
        // passing simply because the selectors never match anything.
        $latest = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');
        // Two composers: one for a comment, one for a rewording.
        self::assertCount(2, $latest->filter('.lp-comment-composer'));
        self::assertCount(1, $latest->filter('form[name="strike_passage_form"]'));
        self::assertCount(2, $latest->filter('button[name="submit_review_form[verdict]"]'));
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

    public function test_a_heading_with_no_derivable_label_is_left_out_of_the_table_of_contents(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-blanktoc', 'owner-blanktoc@example.com');
        $project = $this->project($em, $owner);

        // The middle heading is an image with no alt text: nothing to label it with,
        // so it must not become a blank link between the two real entries.
        $markdown = "## First\n\nBody.\n\n## ![](diagram.png)\n\nMore.\n\n## Second\n\nEnd.\n";
        $doc = new Document(owner: $owner, project: $project, title: 'Illustrated Doc');
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
        // It keeps its id in the document, so anything already linking to it resolves.
        self::assertStringContainsString('id="heading-section"', (string) $client->getResponse()->getContent());
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

    public function test_both_ends_of_a_reference_render_it_and_an_archived_target_is_marked(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-refs', 'owner-refs@example.com');
        $project = $this->project($em, $owner);

        $target = new Document(owner: $owner, project: $project, title: 'The Retired Spec');
        $target->addVersion('# Spec', '<h1>Spec</h1>');
        // Archiving takes a document out of the list, not out of the documents
        // that point at it — the link stays, and says the target is archived.
        $target->archivedAt = new \DateTimeImmutable();
        $target->archiveReason = 'superseded by the v2 plan';
        $em->persist($target);

        $source = new Document(owner: $owner, project: $project, title: 'The Companion Thread');
        $source->addVersion('# Thread', '<h1>Thread</h1>');
        $source->references->add($target);
        $em->persist($source);
        $em->flush();

        $projectId = (string) $project->id;
        $sourceId = (string) $source->id;
        $targetId = (string) $target->id;
        $em->clear();

        $client->loginUser($owner);

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$sourceId.'/review');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.lp-doc-references', 'The Retired Spec');
        self::assertCount(1, $crawler->filter('.lp-doc-references__archived'));
        self::assertSelectorTextContains('.lp-doc-references__archive-reason', 'superseded by the v2 plan');
        self::assertCount(
            1,
            $crawler->filter('.lp-doc-references__link[href="/projects/'.$projectId.'/documents/'.$targetId.'/review"]'),
        );

        // The same row, read from the other end.
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$targetId.'/review');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.lp-doc-references', 'The Companion Thread');
        self::assertCount(0, $crawler->filter('.lp-doc-references__archived'));
    }

    /**
     * A reference archived from the app carries no reason, which is the ordinary
     * case: the Archived chip still shows, and nothing stands in for the reason
     * that was never asked for.
     */
    public function test_an_archived_reference_with_no_reason_renders_the_chip_alone(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-ref-noreason', 'owner-ref-noreason@example.com');
        $project = $this->project($em, $owner);

        $target = new Document(owner: $owner, project: $project, title: 'Quietly Retired');
        $target->addVersion('# Spec', '<h1>Spec</h1>');
        $target->archivedAt = new \DateTimeImmutable();
        $em->persist($target);

        $source = new Document(owner: $owner, project: $project, title: 'The Companion Thread');
        $source->addVersion('# Thread', '<h1>Thread</h1>');
        $source->references->add($target);
        $em->persist($source);
        $em->flush();

        $projectId = (string) $project->id;
        $sourceId = (string) $source->id;
        $em->clear();

        $client->loginUser($owner);

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$sourceId.'/review');
        self::assertResponseIsSuccessful();
        // The chip proves the reference rendered, so the missing reason below is
        // the template's choice rather than an empty page.
        self::assertCount(1, $crawler->filter('.lp-doc-references__archived'));
        self::assertCount(0, $crawler->filter('.lp-doc-references__archive-reason'));
    }

    /**
     * The contents panel and the reference list are separate features that landed
     * in the same region of the document head, so one page has to render both.
     */
    public function test_the_contents_panel_and_the_reference_list_render_together(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-both', 'owner-both@example.com');
        $project = $this->project($em, $owner);

        $target = new Document(owner: $owner, project: $project, title: 'The Spec');
        $target->addVersion('# Spec', '<h1>Spec</h1>');
        $em->persist($target);

        $markdown = "## First\n\nBody.\n\n## Second\n\nMore.\n";
        $source = new Document(owner: $owner, project: $project, title: 'Sectioned Companion');
        $source->addVersion($markdown, new MarkdownRenderer(new NullLogger())->render($markdown));
        $source->references->add($target);
        $em->persist($source);
        $em->flush();

        $projectId = (string) $project->id;
        $sourceId = (string) $source->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$sourceId.'/review');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('.lp-review-contents__link'));
        self::assertSelectorTextContains('.lp-doc-references', 'The Spec');
    }

    public function test_a_document_with_no_references_renders_no_reference_block(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-norefs', 'owner-norefs@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Standalone Doc');
        $doc->addVersion('# v1', '<h1>v1</h1>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.lp-doc-references'));
    }

    public function test_the_version_switcher_links_to_each_version_s_diff(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-diff-link', 'owner-diff-link@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Linked Doc');
        $doc->addVersion('# v1', '<h1>v1</h1>');
        $doc->addVersion('# v2', '<h1>v2</h1>');
        $doc->addVersion('# v3', '<h1>v3</h1>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseIsSuccessful();
        $base = '/projects/'.$projectId.'/documents/'.$id.'/review/diff/';
        self::assertSame(
            [$base.'2/3', $base.'1/2'],
            $crawler->filter('.lp-version-entry__diff')->each(
                static fn (\Symfony\Component\DomCrawler\Crawler $node): string => (string) $node->attr('href'),
            ),
            'the first version has no predecessor to compare against',
        );
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
