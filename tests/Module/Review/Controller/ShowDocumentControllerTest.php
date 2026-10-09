<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Command\SetDocumentHighlightsCommand;
use App\Module\Review\Command\SetDocumentHighlightsHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\DocumentVersion;
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
use Symfony\Component\Translation\IdentityTranslator;
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
        // The document being read, and the Comments panel that lists its threads.
        self::assertSelectorExists('.lp-review-doc');
        self::assertSelectorExists('#review-panel-comments .lp-review-margin');
        self::assertSelectorExists('.lp-review-doc__back[aria-label="Back to documents"]');
        self::assertSelectorTextContains('.lp-review-workspace-nav .lp-tabs', 'Document');
        self::assertSelectorTextContains('.lp-review-workspace-nav .lp-tabs', 'History');
        self::assertSelectorExists('.lp-review-workspace-nav .lp-tabs__tab[aria-current="page"]');
    }

    public function test_the_page_menu_lists_the_card_linked_to_the_document(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'linked-card-owner', 'linked-card-owner@example.com');
        $project = $this->project($em, $owner);
        $document = new Document(owner: $owner, project: $project, title: 'Linked document');
        $document->addVersion('# Linked', '<h1>Linked</h1>');
        $column = new BoardColumn($project, 'Ready', 'ready', 0, backlog: true);
        $card = new Card($project, $column, 'Implement the document', '', 1);
        $em->persist($document);
        $em->persist($column);
        $em->persist($card);
        $em->flush();
        $card->syncDocuments($document);
        $em->flush();

        $client->loginUser($owner);
        $crawler = $client->request(
            Request::METHOD_GET,
            '/projects/'.$project->id.'/documents/'.$document->id.'/review',
        );

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('#review-page-menu [data-page-menu-group="card"] .lp-page-menu__row');
        self::assertCount(1, $row);
        self::assertSame('#1 Implement the document', trim($row->filter('.lp-page-menu__label')->text()));
        self::assertSame('Linked card · Feature · Ready', trim($row->filter('.lp-page-menu__line')->text()));
        self::assertStringEndsWith('/cards/'.$card->id, (string) $row->attr('href'));
        self::assertCount(0, $crawler->filter('#review-page-menu .lp-page-menu__heading'), 'one link needs no heading');
    }

    public function test_the_page_menu_heads_a_kind_with_several_links_and_holds_back_all_but_three(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'many-links-owner', 'many-links-owner@example.com');
        $project = $this->project($em, $owner);
        $document = new Document(owner: $owner, project: $project, title: 'Hub document');
        $document->addVersion('# Hub', '<h1>Hub</h1>');
        $em->persist($document);
        foreach (range(1, 5) as $number) {
            $target = new Document(owner: $owner, project: $project, title: 'Target '.$number);
            $target->addVersion('# T', '<h1>T</h1>');
            $em->persist($target);
            $document->references->add($target);
        }
        $em->flush();

        $client->loginUser($owner);
        $crawler = $client->request(
            Request::METHOD_GET,
            '/projects/'.$project->id.'/documents/'.$document->id.'/review',
        );

        self::assertResponseIsSuccessful();
        $group = $crawler->filter('#review-page-menu [data-page-menu-group="outgoing"]');
        self::assertSame('Links to (5)', trim($group->filter('.lp-page-menu__heading')->text()));
        self::assertCount(3, $group->children('.lp-page-menu__row'));
        self::assertSame('Show 2 more', trim($group->filter('.lp-page-menu__more-toggle')->text()));
        self::assertCount(2, $group->filter('.lp-page-menu__more .lp-page-menu__row'));
        self::assertSame('Links to · In review', trim($group->filter('.lp-page-menu__line')->first()->text()));
    }

    public function test_the_linked_card_shows_its_title_and_not_its_body(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'linked-card-body-owner', 'linked-card-body-owner@example.com');
        $project = $this->project($em, $owner);
        $document = new Document(owner: $owner, project: $project, title: 'Linked document');
        $document->addVersion('# Linked', '<h1>Linked</h1>');
        $column = new BoardColumn($project, 'Ready', 'ready', 0, backlog: true);
        $card = new Card($project, $column, 'Implement the document', 'Linked body marker text', 1);
        $em->persist($document);
        $em->persist($column);
        $em->persist($card);
        $em->flush();
        $card->syncDocuments($document);
        $em->flush();

        $client->loginUser($owner);
        $crawler = $client->request(
            Request::METHOD_GET,
            '/projects/'.$project->id.'/documents/'.$document->id.'/review',
        );

        self::assertResponseIsSuccessful();
        $menu = $crawler->filter('#review-page-menu');
        self::assertStringContainsString('Implement the document', $menu->filter('.lp-page-menu__label')->text());
        self::assertStringNotContainsString('Linked body marker text', $menu->text());
    }

    public function test_the_page_menu_leaves_out_the_tags(): void
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
        self::assertSelectorExists('#review-page-menu');
        self::assertSelectorTextNotContains('#review-page-menu', 'architecture');
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
        self::assertSelectorTextContains('.lp-review-doc__byline', 'Ribbonowner');
        self::assertSelectorTextNotContains('.lp-review-doc__byline', 'sections approved');
        self::assertSelectorCount(3, '.lp-review-toolbar__button[aria-pressed]');
        self::assertSelectorTextContains('.lp-review-toolbar', 'Comments');
        // Nothing to answer, so Decisions stays in the toolbar, off and disabled.
        self::assertSelectorExists('.lp-review-toolbar__button[data-review-panels-name-param="decisions"][aria-disabled="true"][aria-pressed="false"]');
        self::assertSelectorExists('#review-panel-decisions[hidden]');
        self::assertSelectorExists('.lp-review-doc__byline [aria-controls="review-page-menu"]');
        self::assertSelectorExists('input[name="submit_review_form[verdict]"][value="approved"]');
        self::assertSelectorExists('input[name="submit_review_form[verdict]"][value="changes-requested"]');
        self::assertSelectorNotExists('.lp-verdict-chip');
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
        // Both anchored threads sit in the same list, in no status order.
        self::assertCount(2, $crawler->filter('.lp-review-margin .lp-comment-row'));
        self::assertCount(1, $crawler->filter('.lp-comment-row--resolved'));
        // Each thread carries its derived anchor status and says it in words.
        self::assertSelectorExists('[data-anchor-status="pending"]');
        self::assertSelectorExists('[data-anchor-status="resolved"]');
        self::assertSelectorTextContains('.lp-comment-status--pending', 'Open');
        self::assertSelectorTextContains('.lp-comment-status--resolved', 'Resolved');
        self::assertSelectorExists('.lp-comment-thread--resolved');
    }

    public function test_the_comments_panel_lists_each_thread_as_a_row_that_opens_its_card(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'rowowner', 'rows@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Row Doc');
        $version = $doc->addVersion('# Body', '<h1>Body</h1>');
        $em->persist($doc);

        $open = new Comment($version, $owner, 'Say more here', new Anchor('Body', '', '', 0));
        $resolved = new Comment($version, $owner, 'Already fixed', new Anchor('Body', '', '', 0));
        $resolved->status = CommentStatus::Resolved;
        $orphan = new Comment($version, $owner, 'About removed text', new Anchor('Gone words', '', '', 0));
        $orphan->orphaned = true;
        $general = new Comment($version, $owner, 'On the whole thing', new Anchor('', '', '', 0));
        $strike = new Comment($version, $owner, '', new Anchor('Body', '', '', 0), replacement: '');
        foreach ([$open, $resolved, $orphan, $general, $strike] as $comment) {
            $em->persist($comment);
        }
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $openId = (string) $open->id;
        $orphanId = (string) $orphan->id;
        $strikeId = (string) $strike->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseIsSuccessful();
        $panel = $crawler->filter('#review-panel-comments');
        self::assertCount(5, $panel->filter('#comment-rows .lp-comment-row'));

        // Two lines: the quoted text, then the comment.
        $row = $panel->filter('#comment-row-'.$openId);
        self::assertSame('comment-thread-'.$openId, $row->attr('data-thread-id'));
        self::assertSame('Body', trim($row->filter('.lp-comment-row__quote')->text()));
        self::assertSame('Say more here', trim($row->filter('.lp-comment-row__body')->text()));

        self::assertCount(1, $panel->filter('.lp-comment-row--resolved'));
        self::assertSelectorTextContains('.lp-orphan-group__title', 'No longer in the text · 1');
        self::assertCount(1, $panel->filter('.lp-orphan-group #comment-row-'.$orphanId.'.lp-comment-row--orphaned'));
        self::assertSame('Whole document', trim($panel->filter('.lp-general-comments .lp-comment-row__quote')->text()));
        self::assertCount(1, $panel->filter('#comment-row-'.$strikeId.' .lp-comment-row__quote del'));
        self::assertSame('Strike', trim($panel->filter('#comment-row-'.$strikeId.' .lp-comment-row__body')->text()));

        // The panel ends with the general comment button.
        self::assertSame('Comment on the whole document', trim($panel->filter('.lp-comment-whole-document')->text()));

        // The cards sit outside the panel, which is hidden by default, as popovers.
        self::assertCount(0, $panel->filter('.lp-comment-thread'));
        self::assertCount(5, $crawler->filter('#comment-threads > .lp-comment-thread[popover="auto"]'));
        self::assertCount(1, $crawler->filter('#comment-thread-'.$openId.'[popover="auto"]'));
    }

    public function test_replying_returns_the_card_and_its_row_as_one_stream(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'replyowner', 'reply@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Reply Doc');
        $version = $doc->addVersion('# Body', '<h1>Body</h1>');
        $em->persist($doc);

        $comment = new Comment($version, $owner, 'Please fix this', new Anchor('Body', '', '', 0));
        $em->persist($comment);
        $em->flush();

        $commentId = (string) $comment->id;
        $formName = 'reply_'.$comment->id?->toBase32();
        $em->clear();

        $client->loginUser($owner);
        $client->request(
            Request::METHOD_POST,
            '/comments/'.$commentId.'/reply',
            [$formName => ['body' => 'Done in v2', '_token' => 'csrf-token']],
            server: [
                'HTTP_ACCEPT' => TurboBundle::STREAM_MEDIA_TYPE,
                'HTTP_ORIGIN' => 'http://localhost',
            ],
        );

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('target="comment-thread-'.$commentId.'"', $content);
        self::assertStringContainsString('target="comment-row-'.$commentId.'"', $content);
        self::assertStringContainsString('Done in v2', $content);
        self::assertStringContainsString('popover="auto"', $content);
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

    public function test_the_orphan_group_counts_threads_and_not_their_replies(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'orphanowner', 'orphans@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Orphan Doc');
        $version = $doc->addVersion('# Body', '<h1>Body</h1>');
        $em->persist($doc);

        $root = new Comment($version, $owner, 'A comment on removed text', new Anchor('Gone', '', '', 0));
        $root->orphaned = true;
        // A reply copies its parent's anchor, so re-anchoring flags it too. One
        // broken anchor is one thing to fix, so the banner must say one.
        $reply = new Comment($version, $owner, 'A reply', $root->anchor, $root);
        $reply->orphaned = true;
        $em->persist($root);
        $em->persist($reply);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseIsSuccessful();
        // The group leads the comment column and holds the thread itself, so the
        // heading counts threads while the column below it holds none of them.
        self::assertSelectorTextContains('.lp-orphan-group__title', 'No longer in the text · 1');
        self::assertCount(1, $crawler->filter('.lp-orphan-group .lp-comment-row--orphaned'));
        self::assertCount(0, $crawler->filter('.lp-comment-rail > .lp-comment-row'));
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

        // The stream replaces the cards and the panel rows, not a single card.
        self::assertStringContainsString('target="comment-threads"', $content);
        self::assertStringContainsString('target="comment-rows"', $content);
        self::assertStringContainsString('lp-comment-row--resolved', $content);
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
        self::assertStringContainsString('target="comment-rows"', $content);
        self::assertStringNotContainsString('lp-comment-thread--resolved', $content);
        self::assertStringNotContainsString('lp-comment-row--resolved', $content);
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
        self::assertCount(1, $crawler->filter('.lp-verdict-chip__undo'));

        $client->submit($crawler->filter('.lp-verdict-chip__undo button')->form());

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
        self::assertSelectorNotExists('.lp-verdict-chip');
        // The top bar carries the pair above lg and the review menu below it.
        self::assertCount(2, $crawler->filter('dialog input[name="submit_review_form[verdict]"]'));
        self::assertCount(1, $crawler->filter('.lp-review-menu__verdict'));
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

    public function test_an_approved_document_offers_to_change_its_verdict(): void
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
        self::assertSelectorExists('.lp-verdict-chip--approved');
        self::assertSelectorTextContains('.lp-verdict-chip__title', 'Approved');
        self::assertSelectorNotExists('input[name="undo_verdict_form[reviewId]"]');
        self::assertSelectorTextSame('.lp-verdict-chip button[data-action="click->review-finish#open"]', 'Change verdict');
        self::assertSelectorExists('.lp-review[data-controller~="modal"] dialog[data-modal-target="dialog"]');
        self::assertSelectorExists('dialog input[name="submit_review_form[verdict]"][value="approved"]');
        // The verdict chip is the one entry point once a verdict stands.
        self::assertSelectorNotExists('.lp-review-menu__verdict');
        self::assertSelectorNotExists('.lp-review-topbar-actions button.lp-btn--primary');
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

        // The history page is the way back — a route with no entry point would
        // leave the discussion exactly as unreachable as before.
        $historyUrl = '/projects/'.$projectId.'/documents/'.$id.'/review/history';
        self::assertCount(1, $latest->filter('.lp-review-workspace-nav .lp-tabs a[href="'.$historyUrl.'"]'));

        $versionUrl = '/projects/'.$projectId.'/documents/'.$id.'/review/versions/1';
        $history = $client->request(Request::METHOD_GET, $historyUrl);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $history->filter('.lp-history a[href="'.$versionUrl.'"]'));

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
        self::assertCount(0, $earlier->filter('input[name="submit_review_form[verdict]"]'), 'the verdict applies to the document as it stands');
        self::assertCount(0, $earlier->filter('.lp-comment-thread form'), 'reply, resolve and delete all act on the live discussion');

        // Same page on the current version, to prove the assertions above are not
        // passing simply because the selectors never match anything.
        $latest = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');
        // Two composers: one for a comment, one for a rewording.
        self::assertCount(2, $latest->filter('.lp-comment-composer'));
        self::assertCount(1, $latest->filter('form[name="strike_passage_form"]'));
        self::assertCount(2, $latest->filter('dialog input[name="submit_review_form[verdict]"]'));
        self::assertCount(1, $latest->filter('.lp-review-menu__verdict'));
        self::assertGreaterThan(0, $latest->filter('.lp-comment-thread form')->count());
        self::assertCount(1, $latest->filter('.lp-comment-thread__footer .lp-comment-action--delete'));
        self::assertCount(1, $latest->filter('.lp-comment-thread__footer [data-comment-reply-target="form"][hidden]'));
        self::assertCount(1, $latest->filter('.lp-comment-thread__footer .lp-comment-action--resolve'));
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

    /** The review page carries no version note, and the history page carries every note. */
    public function test_the_history_shows_every_version_note(): void
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

        self::assertSelectorTextNotContains('#review-page-menu', 'Replaced the rollout section');

        $history = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review/history');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Replaced the rollout section with a phased plan.', 'The original brief.'],
            $history->filter('.lp-history__note')->each(
                static fn (\Symfony\Component\DomCrawler\Crawler $node): string => trim($node->text()),
            ),
        );
    }

    /**
     * A document with one version has no switching to do, but its description is
     * the only account of what that version is. The history page shows it.
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
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review/history');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.lp-history__note', 'The original brief.');
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

    public function test_several_versions_without_descriptions_still_link_to_history(): void
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
        self::assertCount(1, $crawler->filter('.lp-review-workspace-nav .lp-tabs a[href="/projects/'.$projectId.'/documents/'.$id.'/review/history"]'));
        self::assertCount(1, $crawler->filter('#review-page-menu a[href="/projects/'.$projectId.'/documents/'.$id.'/review/history"]'));
        self::assertSame('2 versions', trim($crawler->filter('#review-page-menu [href$="/review/history"] .lp-page-menu__line')->text()));
        self::assertCount(1, $crawler->filter('#review-page-menu a[href="/projects/'.$projectId.'/documents/'.$id.'/review/diff/1/2"]'));
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
        $doc->addVersion($markdown, new MarkdownRenderer(new NullLogger(), new IdentityTranslator())->render($markdown));
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
            $crawler->filter('[data-panel="contents"] .lp-review-contents__link')->each(static fn ($node): string => (string) $node->attr('href')),
        );
        // The panel must sit outside the prose container, whose textContent has to
        // stay identical to DocumentVersion::plainText() for anchors to resolve.
        self::assertCount(0, $crawler->filter('[data-comment-anchor-target="doc"] .lp-review-contents'));
    }

    public function test_a_heading_with_no_derivable_label_is_listed_under_its_own_id(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-blanktoc', 'owner-blanktoc@example.com');
        $project = $this->project($em, $owner);

        // The middle heading is an image with no alt text, so nothing labels it.
        // It takes a row labelled by its own id rather than a blank one.
        $markdown = "## First\n\nBody.\n\n## ![](diagram.png)\n\nMore.\n\n## Second\n\nEnd.\n";
        $doc = new Document(owner: $owner, project: $project, title: 'Illustrated Doc');
        $doc->addVersion($markdown, new MarkdownRenderer(new NullLogger(), new IdentityTranslator())->render($markdown));
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['#heading-first', '#heading-section', '#heading-second'],
            $crawler->filter('[data-panel="contents"] .lp-review-contents__link')->each(static fn ($node): string => (string) $node->attr('href')),
        );
        // Labelled by its id, never blank: a blank link is what the old rule avoided.
        self::assertSame(
            ['First', 'heading-section', 'Second'],
            $crawler->filter('[data-panel="contents"] .lp-review-contents__link')->each(static fn ($node): string => trim($node->text())),
        );
        // It keeps its id in the document, so anything already linking to it resolves.
        self::assertStringContainsString('id="heading-section"', (string) $client->getResponse()->getContent());
    }

    public function test_a_document_with_one_heading_still_reports_that_section(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-nocontents', 'owner-nocontents@example.com');
        $project = $this->project($em, $owner);

        $markdown = "## Only\n\nBody.\n";
        $doc = new Document(owner: $owner, project: $project, title: 'Flat Doc');
        $doc->addVersion($markdown, new MarkdownRenderer(new NullLogger(), new IdentityTranslator())->render($markdown));
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseIsSuccessful();
        // One section is enough for the panel to list.
        self::assertCount(1, $crawler->filter('#review-panel-outline'));
        self::assertCount(1, $crawler->filter('[data-panel="contents"] .lp-review-contents__link'));
        self::assertSame('1', trim($crawler->filter('#review-panel-outline .lp-review-panel__count')->text()));
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
        $row = $crawler->filter('#review-page-menu [data-page-menu-group="outgoing"] .lp-page-menu__row');
        self::assertSame('The Retired Spec', trim($row->filter('.lp-page-menu__label')->text()));
        self::assertSame('Links to · Archived', trim($row->filter('.lp-page-menu__line')->text()));
        self::assertSame('/projects/'.$projectId.'/documents/'.$targetId.'/review', $row->attr('href'));

        // The same row, read from the other end.
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$targetId.'/review');
        self::assertResponseIsSuccessful();
        $row = $crawler->filter('#review-page-menu [data-page-menu-group="incoming"] .lp-page-menu__row');
        self::assertSame('The Companion Thread', trim($row->filter('.lp-page-menu__label')->text()));
        self::assertSame('Links to this document · In review', trim($row->filter('.lp-page-menu__line')->text()));
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
        $source->addVersion($markdown, new MarkdownRenderer(new NullLogger(), new IdentityTranslator())->render($markdown));
        $source->references->add($target);
        $em->persist($source);
        $em->flush();

        $projectId = (string) $project->id;
        $sourceId = (string) $source->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$sourceId.'/review');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-panel="contents"] .lp-review-contents__link'));
        self::assertSelectorTextContains('#review-page-menu [data-page-menu-group="outgoing"]', 'The Spec');
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
        self::assertCount(0, $crawler->filter('#review-page-menu [data-page-menu-group]'));
        self::assertCount(1, $crawler->filter('#review-page-menu [href$="/review/history"]'));
    }

    public function test_the_diff_tab_links_to_the_current_version_s_diff(): void
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
            [$base.'2/3'],
            $crawler->filter('.lp-review-workspace-nav .lp-tabs a[href="'.$base.'2/3"]')->each(
                static fn (\Symfony\Component\DomCrawler\Crawler $node): string => (string) $node->attr('href'),
            ),
            'the Diff tab compares the current version with its predecessor',
        );
    }

    public function test_the_new_text_switch_is_off_for_the_first_version_and_on_for_a_later_one(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-new-text', 'owner-new-text@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Switch Doc');
        $doc->addVersion('# v1', '<h1>v1</h1>');
        $doc->addVersion('# v2', '<h1>v2</h1>');
        $em->persist($doc);
        $em->flush();

        $base = '/projects/'.$project->id.'/documents/'.$doc->id.'/review';
        $client->loginUser($owner);

        $crawler = $client->request(Request::METHOD_GET, $base);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.lp-btn--ghost.lp-btn--sm.lp-new-text-switch[role="switch"][aria-checked="false"][data-controller="review-new-text"]:not([aria-disabled])'));
        self::assertStringEndsWith(
            $base.'/new-text/2',
            (string) $crawler->filter('[role="switch"]')->attr('data-review-new-text-url-value'),
        );

        $crawler = $client->request(Request::METHOD_GET, $base.'/versions/1');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[role="switch"][aria-disabled="true"]'));

        $crawler = $client->request(Request::METHOD_GET, $base.'/diff/1/2');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[role="switch"]'));
    }

    /**
     * The invariant every comment anchor rests on: the pane the browser walks
     * reads exactly as DocumentVersion::plainText(), which is what the server
     * measures a quote against. A single stray character in that element puts
     * every offset after it wrong.
     */
    public function test_the_document_pane_reads_exactly_as_the_version_s_plain_text(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $renderer = static::getContainer()->get(MarkdownRenderer::class);

        $owner = $this->createUser($em, 'owner-basis', 'owner-basis@example.com');
        $project = $this->project($em, $owner);

        $source = "# Plan\n\nThe rollout takes three steps 🚀.\n\n- one\n- two\n\n| a | b |\n| --- | --- |\n| 1 | 2 |\n";
        $doc = new Document(owner: $owner, project: $project, title: 'Basis Doc');
        $doc->addVersion($source, $renderer->render($source));
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $latest = $doc->versions->last();
        self::assertInstanceOf(DocumentVersion::class, $latest);
        $versionId = $latest->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseIsSuccessful();
        $version = $em->find(DocumentVersion::class, $versionId);
        self::assertNotNull($version);

        $pane = $crawler->filter('[data-comment-anchor-target="doc"]');
        self::assertCount(1, $pane);
        self::assertSame($version->plainText(), $pane->text(null, false));
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

    public function test_mentions_of_a_defined_id_are_marked_and_the_definitions_reach_the_page(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'refowner', 'refowner@example.com');
        $project = $this->project($em, $owner);

        $glossary = new Document(owner: $owner, project: $project, title: 'Glossary');
        $glossary->addVersion('- **H2: Hold the lock.** Detail.', '<ul><li><strong>H2: Hold the lock.</strong> Detail.</li></ul>');
        $doc = new Document(owner: $owner, project: $project, title: 'Plan');
        $version = $doc->addVersion(
            '1. **R1: Keep the cache.** Detail.',
            '<ol><li><strong>R1: Keep the cache.</strong> Detail.</li></ol><p>See R1 and H2.</p>',
        );
        $doc->addReference($glossary);
        $em->persist($glossary);
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $glossaryId = (string) $glossary->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseIsSuccessful();
        $pane = $crawler->filter('[data-comment-anchor-target="doc"]');
        self::assertCount(1, $pane->filter('li#ref-R1'));
        self::assertCount(1, $pane->filter('.lp-ref[data-ref="R1"]'));
        self::assertCount(1, $pane->filter('.lp-ref[data-ref="H2"]'));
        self::assertSame($version->plainText(), $pane->text(null, false));
        self::assertCount(0, $pane->filter('[data-reference-tooltip-target]'));

        $definitions = json_decode(
            (string) $crawler->filter('[data-reference-tooltip-definitions-value]')->attr('data-reference-tooltip-definitions-value'),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        self::assertSame(['text' => 'Keep the cache.', 'source' => null, 'href' => '#ref-R1'], $definitions['R1']);
        self::assertSame('Glossary', $definitions['H2']['source']);
        self::assertSame('/projects/'.$projectId.'/documents/'.$glossaryId.'/review#ref-H2', $definitions['H2']['href']);
    }

    public function test_an_undefined_id_stays_plain_text(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'plainowner', 'plainowner@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Plan');
        $doc->addVersion(
            '1. **R1: Keep the cache.** Detail.',
            '<ol><li><strong>R1: Keep the cache.</strong> Detail.</li></ol><p>See R1 and Q9.</p>',
        );
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseIsSuccessful();
        $pane = $crawler->filter('[data-comment-anchor-target="doc"]');
        self::assertCount(1, $pane->filter('.lp-ref[data-ref="R1"]'));
        self::assertCount(0, $pane->filter('[data-ref="Q9"]'));
        self::assertStringContainsString('See R1 and Q9.', $pane->text());
    }

    public function test_a_document_without_definitions_carries_no_tooltip(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'notipowner', 'notipowner@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Plain');
        $doc->addVersion('See R1.', '<p>See R1.</p>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-comment-anchor-target="doc"]', 'See R1.');
        self::assertCount(0, $crawler->filter('.lp-ref'));
        self::assertCount(0, $crawler->filter('[data-reference-tooltip-definitions-value]'));
    }
}
