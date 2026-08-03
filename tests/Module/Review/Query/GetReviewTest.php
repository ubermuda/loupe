<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Query;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\SelectDecisionOptionCommand;
use App\Module\Review\Command\SelectDecisionOptionHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Query\GetDocument;
use App\Module\Review\Query\GetReview;
use App\Module\Review\ValueObject\Anchor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetReviewTest extends KernelTestCase
{
    /**
     * Keys that would name the person behind a comment. `document_get_review` promises in its
     * MCP tool description that it reports none of them, so adding one to the payload for a
     * template's or the dev endpoint's benefit would hand a reviewer's real name to an agent.
     */
    private const array IDENTIFYING_KEYS = [
        'author_id',
        'authorEmail',
        'authorId',
        'authorName',
        'email',
        'fullName',
        'full_name',
        'user',
        'userId',
        'username',
    ];

    private EntityManagerInterface $em;
    private GetReview $getReview;
    private GetDocument $getDocument;
    private User $owner;
    private User $agent;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $getReview = self::getContainer()->get(GetReview::class);
        self::assertInstanceOf(GetReview::class, $getReview);
        $this->getReview = $getReview;

        $getDocument = self::getContainer()->get(GetDocument::class);
        self::assertInstanceOf(GetDocument::class, $getDocument);
        $this->getDocument = $getDocument;

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->agent = $users->agent();

        $this->owner = new User(
            username: 'owner',
            fullName: 'Owner User',
            email: 'owner@example.com',
            password: 'hashed',
        );
        $this->em->persist($this->owner);

        $this->project = new Project($this->owner, 'p-'.uniqid());
        $this->em->persist($this->project);
        $this->em->flush();
    }

    public function test_returns_review_shape_with_verdict_and_threaded_comments(): void
    {
        $doc = new Document(owner: $this->owner, project: $this->project, title: 'Auth PRD');
        $version = $doc->addVersion(
            'Use JWTs for authentication and rate limiting.',
            '<p>Use JWTs for authentication and rate limiting.</p>',
        );

        $rootComment = new Comment(
            $version,
            $this->owner,
            'Why JWTs? Consider opaque tokens.',
            new Anchor('JWTs', 'Use ', ' for', 4),
        );

        // Mirrors what the MCP produces: a human raises the thread, the agent answers it.
        $reply = new Comment(
            $version,
            $this->agent,
            'JWTs allow stateless auth which suits the agent use-case.',
            new Anchor('JWTs', 'Use ', ' for', 4),
            parent: $rootComment,
        );

        $review = new Review($version, Verdict::ChangesRequested, $this->owner);

        $this->em->persist($doc);
        $this->em->persist($rootComment);
        $this->em->persist($reply);
        $this->em->persist($review);
        $this->em->flush();

        $result = ($this->getReview)($doc);

        self::assertSame('in-review', $result['status']);
        self::assertSame('changes-requested', $result['verdict']);
        self::assertSame(1, $result['version']);

        $comments = $result['comments'];
        // Only root comments appear at the top level (not the reply).
        self::assertCount(1, $comments);

        $root = $comments[0];
        // The id is what document_reply_to_comment and
        // document_mark_comment_addressed take, at both levels.
        self::assertSame((string) $rootComment->id, $root['id']);
        self::assertSame('JWTs', $root['quote']);
        self::assertSame('Why JWTs? Consider opaque tokens.', $root['body']);
        self::assertSame('pending', $root['status']);
        self::assertSame('human', $root['author']);
        self::assertFalse($root['orphaned']);

        // The reply must appear in thread, not at the top level.
        self::assertCount(1, $root['thread']);
        $replyData = $root['thread'][0];
        self::assertSame((string) $reply->id, $replyData['id']);
        self::assertSame('JWTs', $replyData['quote']);
        self::assertSame('JWTs allow stateless auth which suits the agent use-case.', $replyData['body']);
        // An agent re-reading the thread must be able to tell its own reply from the human's.
        self::assertSame('agent', $replyData['author']);
        self::assertArrayNotHasKey('status', $replyData, 'Status belongs to the thread, so a reply carries none');
        self::assertFalse($replyData['orphaned']);
    }

    public function test_a_comment_reports_its_author_class_but_never_an_identity(): void
    {
        $doc = new Document(owner: $this->owner, project: $this->project, title: 'Identity');
        $version = $doc->addVersion('Use JWTs.', '<p>Use JWTs.</p>');

        $root = new Comment($version, $this->owner, 'Why JWTs?', new Anchor('JWTs', 'Use ', '.', 4));
        $reply = new Comment($version, $this->agent, 'Stateless auth.', new Anchor('JWTs', 'Use ', '.', 4), parent: $root);

        $this->em->persist($doc);
        $this->em->persist($root);
        $this->em->persist($reply);
        $this->em->flush();

        $comments = ($this->getReview)($doc)['comments'];

        // Assert the entries were built before asserting what they leave out, so the
        // absence checks below cannot pass on an empty payload.
        self::assertSame('human', $comments[0]['author']);
        self::assertSame('agent', $comments[0]['thread'][0]['author']);

        foreach (self::IDENTIFYING_KEYS as $key) {
            self::assertArrayNotHasKey($key, $comments[0], \sprintf('A root comment must not report %s', $key));
            self::assertArrayNotHasKey($key, $comments[0]['thread'][0], \sprintf('A reply must not report %s', $key));
        }
    }

    public function test_a_thread_reports_the_status_held_by_its_root(): void
    {
        $doc = new Document(owner: $this->owner, project: $this->project, title: 'Addressed PRD');
        $version = $doc->addVersion('Use JWTs.', '<p>Use JWTs.</p>');

        $root = new Comment($version, $this->owner, 'Why JWTs?', new Anchor('JWTs', 'Use ', '.', 4));
        $root->status = CommentStatus::Addressed;

        $this->em->persist($doc);
        $this->em->persist($root);
        $this->em->flush();

        $comments = ($this->getReview)($doc)['comments'];

        self::assertCount(1, $comments);
        self::assertSame('addressed', $comments[0]['status']);
    }

    public function test_quotes_are_widened_to_whole_words(): void
    {
        $doc = new Document(owner: $this->owner, project: $this->project, title: 'Snapping');
        $version = $doc->addVersion(
            'We authenticate every request.',
            '<p>We authenticate every request.</p>',
        );

        // A selection that began and ended mid-word: "uthenticate ever".
        $root = new Comment(
            $version,
            $this->owner,
            'Which scheme?',
            new Anchor('uthenticate ever', 'We a', 'y request.', 4),
        );
        $reply = new Comment(
            $version,
            $this->owner,
            'OAuth.',
            new Anchor('uthenticate ever', 'We a', 'y request.', 4),
            parent: $root,
        );

        $this->em->persist($doc);
        $this->em->persist($root);
        $this->em->persist($reply);
        $this->em->flush();

        $result = ($this->getReview)($doc);

        // Replies inherit the parent's anchor, so both entries must widen alike.
        self::assertSame('authenticate every', $result['comments'][0]['quote']);
        self::assertSame('authenticate every', $result['comments'][0]['thread'][0]['quote']);

        // The stored anchor is untouched — widening is reporting only.
        self::assertSame('uthenticate ever', $root->anchor->quote);
    }

    public function test_quotes_already_on_word_edges_are_left_alone(): void
    {
        $doc = new Document(owner: $this->owner, project: $this->project, title: 'No Snapping');
        $version = $doc->addVersion(
            "First line here\nSecond line here",
            '<p>First line here<br>Second line here</p>',
        );

        // "here" ends the first line, so the following newline is already an edge:
        // the second line's "Second" must not be dragged in.
        $lineEnd = new Comment(
            $version,
            $this->owner,
            'Ends a line.',
            new Anchor('here', 'First line ', "\nSecond line here", 11),
        );
        // A whitespace-delimited selection stays exactly as captured.
        $whole = new Comment(
            $version,
            $this->owner,
            'Whole word.',
            new Anchor(' line ', 'First', 'here', 5),
        );
        // An untargeted comment has no context to widen against.
        $untargeted = new Comment($version, $this->owner, 'General note.', Anchor::unanchored());

        $this->em->persist($doc);
        $this->em->persist($lineEnd);
        $this->em->persist($whole);
        $this->em->persist($untargeted);
        $this->em->flush();

        // CommentRepository orders by offsetHint, so the payload order is deterministic.
        $quotes = array_column(($this->getReview)($doc)['comments'], 'quote');

        self::assertSame(['', ' line ', 'here'], $quotes);
    }

    public function test_quotes_are_not_widened_when_the_context_holds_no_word_boundary(): void
    {
        $doc = new Document(owner: $this->owner, project: $this->project, title: 'No Boundary');
        $version = $doc->addVersion('placeholder', '<p>placeholder</p>');

        // Japanese is written without spaces, so the whole 32-character context is one
        // run of non-whitespace. Widening would report a paragraph for a four-character
        // selection.
        $japanese = new Comment(
            $version,
            $this->owner,
            'Which plan?',
            new Anchor(
                '設計方針',
                '本書は前提条件を整理したうえで結論を先に述べる。読者はまず',
                'について検討し、次に運用体制と移行手順を順に確認していく。',
                29,
            ),
        );
        // Same shape in ASCII: a URL has no whitespace to snap to either.
        $url = new Comment(
            $version,
            $this->owner,
            'Broken link.',
            new Anchor('review', 'https://example.test/documents/', '/comments?page=2&sort=created', 31),
        );

        $this->em->persist($doc);
        $this->em->persist($japanese);
        $this->em->persist($url);
        $this->em->flush();

        $quotes = array_column(($this->getReview)($doc)['comments'], 'quote');

        self::assertSame(['設計方針', 'review'], $quotes);
    }

    /**
     * The widening that makes a prose quote readable would corrupt a suggestion: the
     * agent substitutes the reported quote with the replacement, so any character
     * spliced on from the context is a character it silently deletes from the document.
     */
    public function test_a_suggestion_reports_the_verbatim_quote_not_the_widened_one(): void
    {
        $doc = new Document(owner: $this->owner, project: $this->project, title: 'Verbatim');
        $version = $doc->addVersion('We utilise a bespoke solution.', '<p>We utilise a bespoke solution.</p>');

        // Both select mid-word, so widening has something to splice on either side.
        $prose = new Comment(
            $version,
            $this->owner,
            'Odd word.',
            new Anchor('utilis', 'We ', 'e a bespoke solution.', 3),
        );
        $rewording = new Comment(
            $version,
            $this->owner,
            'Plainer.',
            new Anchor('bespok', 'We utilise a ', 'e solution.', 13),
            replacement: 'custom',
        );

        $this->em->persist($doc);
        $this->em->persist($prose);
        $this->em->persist($rewording);
        $this->em->flush();

        $comments = ($this->getReview)($doc)['comments'];

        self::assertSame('utilise', $comments[0]['quote'], 'a prose quote is still widened to whole words');
        self::assertNull($comments[0]['replacement']);
        self::assertSame('bespok', $comments[1]['quote'], 'a suggestion reports exactly what was selected');
        self::assertSame('custom', $comments[1]['replacement']);
    }

    public function test_a_strike_reports_an_empty_replacement(): void
    {
        $doc = new Document(owner: $this->owner, project: $this->project, title: 'Struck');
        $version = $doc->addVersion('Delete this clause, please.', '<p>Delete this clause, please.</p>');

        $strike = new Comment(
            $version,
            $this->owner,
            '',
            new Anchor('this clause', 'Delete ', ', please.', 7),
            replacement: '',
        );

        $this->em->persist($doc);
        $this->em->persist($strike);
        $this->em->flush();

        $comments = ($this->getReview)($doc)['comments'];

        // '' and null must stay distinguishable across the wire: one says "remove
        // this", the other says "no edit proposed".
        self::assertSame('', $comments[0]['replacement']);
        self::assertSame('this clause', $comments[0]['quote']);
    }

    public function test_verdict_is_null_when_no_review_submitted(): void
    {
        $doc = new Document(owner: $this->owner, project: $this->project, title: 'No Review Yet');
        $doc->addVersion('Some content.', '<p>Some content.</p>');

        $this->em->persist($doc);
        $this->em->flush();

        $result = ($this->getReview)($doc);

        self::assertNull($result['verdict']);
        self::assertSame('in-review', $result['status']);
        self::assertSame([], $result['comments']);
    }

    /**
     * Every decision is reported, answered or not — an agent has to be able to
     * tell "still waiting" from "answered", and a payload holding only answers
     * cannot express the first.
     *
     * The option labels are pinned against the block the reviewer actually sees,
     * so the payload cannot drift from the rendered markup without failing here.
     */
    public function test_decisions_are_reported_with_their_options_answered_or_not(): void
    {
        $markdown = "<!-- decision: deploy-target -->\n\n- [ ] Ship to staging first\n- [ ] Ship straight to production\n\n<!-- /decision -->\n\n<!-- decision: rollout -->\n\n- [ ] All at once\n\n<!-- /decision -->\n";

        $create = self::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $create);
        $doc = $create(new CreateDocumentCommand($this->project, 'Deploy plan', $markdown));

        $select = self::getContainer()->get(SelectDecisionOptionHandler::class);
        self::assertInstanceOf(SelectDecisionOptionHandler::class, $select);
        $select(new SelectDecisionOptionCommand($doc, 'deploy-target', 1, displayedVersionNumber: 1));

        $decisions = ($this->getReview)($doc)['decisions'];

        self::assertCount(2, $decisions);

        self::assertSame('deploy-target', $decisions[0]['id']);
        self::assertSame(['Ship to staging first', 'Ship straight to production'], $decisions[0]['options']);
        self::assertSame('Ship straight to production', $decisions[0]['selected']);
        self::assertSame(1, $decisions[0]['selected_index']);
        self::assertNotNull($decisions[0]['answered_at']);

        self::assertSame('rollout', $decisions[1]['id']);
        self::assertNull($decisions[1]['selected']);
        self::assertNull($decisions[1]['selected_index']);
        self::assertNull($decisions[1]['answered_at']);

        // The reported labels are the ones rendered into the version the
        // reviewer answered against, not a second reading of the Markdown.
        $html = $doc->currentVersion()->renderedHtml;
        foreach ($decisions[0]['options'] as $option) {
            self::assertStringContainsString('>'.$option.'</label>', $html);
        }
    }

    public function test_a_document_with_no_decisions_reports_an_empty_list(): void
    {
        $doc = new Document(owner: $this->owner, project: $this->project, title: 'No Decisions');
        $doc->addVersion('Plain.', '<p>Plain.</p>');
        $this->em->persist($doc);
        $this->em->flush();

        self::assertSame([], ($this->getReview)($doc)['decisions']);
    }

    public function test_get_document_returns_correct_shape(): void
    {
        $doc = new Document(owner: $this->owner, project: $this->project, title: 'Shape Test');
        $doc->addVersion('# Hello', '<h1>Hello</h1>');

        $this->em->persist($doc);
        $this->em->flush();

        $result = ($this->getDocument)($doc);

        self::assertSame((string) $doc->id, $result['documentId']);
        self::assertSame('Shape Test', $result['title']);
        self::assertSame('in-review', $result['status']);
        self::assertSame(1, $result['version']);
        self::assertSame('# Hello', $result['markdown']);
    }
}
