<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\DiffDocumentVersionsHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Service\MarkdownRenderer;
use App\Module\Review\ValueObject\Anchor;
use App\Module\Review\ValueObject\DiffRefusal;
use App\Tests\Support\AcceptedTerms;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\IdentityTranslator;
use Ubermuda\AuditBundle\AuditOutcome;

final class DiffDocumentVersionsControllerTest extends WebTestCase
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

    /**
     * The diff is the review page with one pane swapped, so the apparatus around
     * that pane has to still be there — and the pane itself has to hold the
     * document as it reads rather than the Markdown it is written in.
     */
    public function test_the_diff_is_the_review_page_with_the_document_pane_comparing_two_versions(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-diff', 'owner-diff@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Diffed Doc');
        $doc->addVersion("# Plan\n\nThe rollout happens in one step.", '<h1>Plan</h1>', 'The original brief.');
        $doc->addVersion("# Plan\n\nThe rollout happens in three steps.", '<h1>Plan</h1>', 'Phased the rollout.');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review/diff/1/2');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.lp-review-doc'));
        self::assertCount(1, $crawler->filter('.lp-doc-meta'));
        self::assertSelectorTextContains('.lp-review-doc__title', 'Diffed Doc');

        // Rendered HTML, not Markdown source: the heading is an <h1>, and the
        // line-per-line source view this replaced is gone.
        $pane = $crawler->filter('.lp-diff-doc');
        self::assertCount(1, $pane);
        self::assertCount(1, $pane->filter('h1'));
        self::assertCount(0, $crawler->filter('.lp-diff__line'));

        // "one step" and "three steps" are single runs, not four: adjacent changed
        // words separated by nothing but a space are glued into one mark.
        self::assertSame(['one step'], $pane->filter('.lp-diff__mark--deleted')->each(
            static fn (Crawler $node): string => $node->text(),
        ));
        self::assertSame(['three steps'], $pane->filter('.lp-diff__mark--inserted')->each(
            static fn (Crawler $node): string => $node->text(),
        ));

        // What the author claims changed, read next to what actually did.
        self::assertSelectorTextContains('.lp-diff-notes', 'Phased the rollout.');
        self::assertStringNotContainsString('The original brief.', $crawler->filter('.lp-diff-notes')->text());
    }

    /**
     * A rendered diff has no lines, so a change is a run of marks with nothing
     * unchanged between them. A section removed whole is one such run across two
     * block wrappers, and the count the bar reports is the number of runs.
     */
    public function test_every_run_of_changes_is_one_numbered_jump_target(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-diff-nav', 'owner-diff-nav@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Navigable Diff');
        $doc->addVersion("Intro stays put.\n\n## Doomed\n\nThe doomed paragraph.\n\nThe tail says one thing about it.\n", '<p>v1</p>');
        $doc->addVersion("Intro stays put.\n\nThe tail says another thing about it.\n", '<p>v2</p>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review/diff/1/2');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-controller~="diff-navigation"]'));

        // Four marks and two runs: the removed heading and the removed paragraph
        // are two block wrappers with nothing unchanged between them, so they are
        // one change, while the reworded word in the tail is another.
        self::assertCount(4, $crawler->filter('.lp-diff-doc .lp-diff__mark'));
        self::assertSame(
            ['diff-hunk-1', 'diff-hunk-2'],
            $crawler->filter('[data-diff-navigation-target="hunk"]')->each(
                static fn (Crawler $node): string => (string) $node->attr('id'),
            ),
        );
        // Focusable, because the controller focuses the hunk it moves to.
        self::assertSame('-1', $crawler->filter('#diff-hunk-1')->attr('tabindex'));
        self::assertSelectorTextContains('.lp-diff-nav__count', '2 changes');

        // Named for a reader served neither the tint nor the strike-through.
        self::assertSame('Removed', $crawler->filter('#diff-hunk-2')->attr('data-diff-label'));
    }

    /**
     * The rendered view cannot mark front matter, a link reference definition or
     * a setext underline, because none of them has a place of its own in the
     * output. A revision that changes only one of those shows as no change at
     * all, so the source view is the way to see it. Front matter is the case
     * used here, since it is the one an author writes most.
     */
    public function test_the_source_view_shows_a_change_the_rendered_view_cannot_mark(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-diff-views', 'owner-diff-views@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Front Matter Doc');
        $doc->addVersion("---\nstatus: draft\n---\n\nThe body never changes.\n", '<p>v1</p>');
        $doc->addVersion("---\nstatus: final\n---\n\nThe body never changes.\n", '<p>v2</p>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $base = '/projects/'.$projectId.'/documents/'.$id.'/review/diff/1/2';

        $rendered = $client->request(Request::METHOD_GET, $base);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $rendered->filter('.lp-diff-doc'));
        self::assertCount(0, $rendered->filter('.lp-diff-doc .lp-diff__mark'));
        self::assertSelectorTextContains('.lp-diff-nav__count', 'No changes');
        // The way out of the state this view cannot describe. Gating the toggle
        // on a count above zero would strand the reader exactly here.
        self::assertCount(2, $rendered->filter('.lp-diff-views__link'));

        $source = $client->request(Request::METHOD_GET, $base.'?view=source');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $source->filter('.lp-diff'));
        self::assertCount(0, $source->filter('.lp-diff-doc'));
        self::assertSame(['status: draft'], $source->filter('.lp-diff__line--deleted')->each(
            static fn (Crawler $node): string => $node->text(),
        ));
        self::assertSame(['status: final'], $source->filter('.lp-diff__line--inserted')->each(
            static fn (Crawler $node): string => $node->text(),
        ));
        self::assertSelectorTextContains('.lp-diff-nav__count', '1 change');
    }

    /**
     * The notice is worth nothing if it cries wolf, so all three states are here
     * together. It belongs to the one state where the page misleads: the versions
     * differ and the rendered view marked none of it, so the count says "No
     * changes" about a revision that changed something. Where the rendered view
     * marked something the count is already true, and where the versions are
     * identical the pane says so, which a second notice would contradict.
     */
    public function test_the_unmarked_notice_appears_only_where_the_count_would_mislead(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-diff-notice', 'owner-diff-notice@example.com');
        $project = $this->project($em, $owner);

        // Front matter, because it is the construct an author writes most and the
        // condition has to be the real one rather than a shape invented here.
        $unmarked = new Document(owner: $owner, project: $project, title: 'Front Matter Only');
        $unmarked->addVersion("---\nstatus: draft\n---\n\nThe body never changes.\n", '<p>v1</p>');
        $unmarked->addVersion("---\nstatus: final\n---\n\nThe body never changes.\n", '<p>v2</p>');

        $marked = new Document(owner: $owner, project: $project, title: 'Marked Change');
        $marked->addVersion("The rollout takes one step.\n", '<p>v1</p>');
        $marked->addVersion("The rollout takes three steps.\n", '<p>v2</p>');

        $identical = new Document(owner: $owner, project: $project, title: 'No Change At All');
        $identical->addVersion("The body never changes.\n", '<p>v1</p>');
        $identical->addVersion("The body never changes.\n", '<p>v2</p>');

        foreach ([$unmarked, $marked, $identical] as $document) {
            $em->persist($document);
        }
        $em->flush();

        $projectId = (string) $project->id;
        $paths = array_map(
            static fn (Document $document): string => '/projects/'.$projectId.'/documents/'.$document->id.'/review/diff/1/2',
            ['unmarked' => $unmarked, 'marked' => $marked, 'identical' => $identical],
        );
        $em->clear();

        $client->loginUser($owner);

        $crawler = $client->request(Request::METHOD_GET, $paths['unmarked']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.lp-diff-nav__count', 'No changes');
        self::assertSelectorTextContains(
            '#diff-unmarked-notice',
            'Something changed here that this view cannot show. Read it as the Markdown instead.',
        );
        // The notice explains the link rather than duplicating it, so the toggle
        // stays the only way across and carries the explanation to a reader who
        // reaches it by keyboard.
        self::assertSame(
            'diff-unmarked-notice',
            $crawler->filter('.lp-diff-views__link')->eq(1)->attr('aria-describedby'),
        );
        self::assertCount(2, $crawler->filter('.lp-diff-views__link'));

        // The same pair as Markdown omits nothing, so it needs no caveat.
        $client->request(Request::METHOD_GET, $paths['unmarked'].'?view=source');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.lp-diff-nav__count', '1 change');
        self::assertSelectorNotExists('#diff-unmarked-notice');

        $client->request(Request::METHOD_GET, $paths['marked']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.lp-diff-nav__count', '1 change');
        self::assertSelectorNotExists('#diff-unmarked-notice');
        self::assertSelectorNotExists('[aria-describedby="diff-unmarked-notice"]');

        $client->request(Request::METHOD_GET, $paths['identical']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.lp-empty', 'identical');
        self::assertSelectorNotExists('#diff-unmarked-notice');
    }

    /**
     * Only one view may be in the page at a time. The navigation controller reads
     * every `hunk` target it can see, so two sets would give it a count that is
     * wrong for whichever the reader is looking at.
     */
    public function test_each_view_carries_its_own_jump_targets_and_count(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-diff-counts', 'owner-diff-counts@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Two Views');
        $doc->addVersion("Intro stays put.\n\n## Doomed\n\nThe doomed paragraph.\n\nThe tail says one thing about it.\n", '<p>v1</p>');
        $doc->addVersion("Intro stays put.\n\nThe tail says another thing about it.\n", '<p>v2</p>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $base = '/projects/'.$projectId.'/documents/'.$id.'/review/diff/1/2';

        // The source view groups by line, and the revision deleted the blank
        // lines too, so every changed line is one contiguous run. The rendered
        // view groups by what is drawn, where the unchanged words of the tail
        // sentence stand between the removed section and the reworded phrase.
        foreach ([$base => [2, '2 changes'], $base.'?view=source' => [1, '1 change']] as $url => [$hunks, $counter]) {
            $crawler = $client->request(Request::METHOD_GET, $url);

            self::assertResponseIsSuccessful();
            self::assertCount($hunks, $crawler->filter('[data-diff-navigation-target="hunk"]'));
            self::assertSame($counter, trim($crawler->filter('.lp-diff-nav__count')->text()));
            self::assertSame(
                'Change %current% of '.$hunks,
                $crawler->filter('[data-controller~="diff-navigation"]')->attr('data-diff-navigation-position-value'),
            );
        }
    }

    public function test_an_unknown_view_falls_back_to_the_rendered_one(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-diff-bogus', 'owner-diff-bogus@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Bogus View');
        $doc->addVersion("The rollout takes one step.\n", '<p>v1</p>');
        $doc->addVersion("The rollout takes three steps.\n", '<p>v2</p>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $base = '/projects/'.$projectId.'/documents/'.$id.'/review/diff/1/2';

        foreach (['?view=raw', '?view=', '?view[]=source'] as $query) {
            $crawler = $client->request(Request::METHOD_GET, $base.$query);

            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('.lp-diff-doc'));
            self::assertCount(0, $crawler->filter('.lp-diff'));
        }
    }

    /**
     * Two links rather than a control, so each view is addressable and switching
     * needs no JavaScript. The current one is marked for a screen reader as well
     * as tinted.
     */
    public function test_each_view_links_to_the_other_and_marks_itself_current(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-diff-toggle', 'owner-diff-toggle@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Toggle Doc');
        $doc->addVersion("The rollout takes one step.\n", '<p>v1</p>');
        $doc->addVersion("The rollout takes three steps.\n", '<p>v2</p>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $base = '/projects/'.$projectId.'/documents/'.$id.'/review/diff/1/2';

        $rendered = $client->request(Request::METHOD_GET, $base);
        self::assertCount(2, $rendered->filter('.lp-diff-views__link'));
        self::assertSame(
            'As the document reads',
            $rendered->filter('.lp-diff-views__link[aria-current]')->text(),
        );
        self::assertStringContainsString('view=source', (string) $rendered->filter('.lp-diff-views__link')->eq(1)->attr('href'));

        $source = $client->request(Request::METHOD_GET, $base.'?view=source');
        self::assertSame(
            'As the Markdown reads',
            $source->filter('.lp-diff-views__link[aria-current]')->text(),
        );
        // The picker keeps the view, so comparing another pair does not silently
        // send the reader back to the rendered one.
        self::assertStringContainsString(
            'view=source',
            (string) $source->filter('[data-controller="version-compare"]')->attr('data-version-compare-url-value'),
        );
    }

    /**
     * DocumentDiff::oldSource()/newSource() and _version_diff.html.twig walk the
     * same structure, but nothing makes them agree. A separator between segments,
     * a `<br>`, one more wrapping element, and the reconstruction would still pass
     * its own unit test while no longer describing the page. This reads the two
     * sides back out of the rendered pane instead: a line that is not inserted
     * belongs to the old version in full, one that is not deleted to the new
     * version in full, because a line's segments never span both sides.
     */
    public function test_both_sources_are_recoverable_from_the_source_view(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-roundtrip', 'owner-roundtrip@example.com');
        $project = $this->project($em, $owner);

        $old = "# Plan & scope\n\n\tWe ship in **one** step, on Monday.\n\nUnchanged tail — naïve café 🎉\n\n- alpha\n- beta\n";
        $new = "# Plan & scope\n\n\tWe ship in **three** steps, starting Monday.\n\nAn <entirely> new paragraph.\n\nUnchanged tail — naïve café 🎉\n\n- alpha\n- gamma\n";

        $doc = new Document(owner: $owner, project: $project, title: 'Round Trip');
        $doc->addVersion($old, '<h1>Plan</h1>');
        $doc->addVersion($new, '<h1>Plan</h1>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review/diff/1/2?view=source');

        self::assertResponseIsSuccessful();

        $oldLines = [];
        $newLines = [];
        foreach ($crawler->filter('.lp-diff__line') as $node) {
            $line = new Crawler($node);
            $classes = (string) $line->attr('class');
            $text = $line->text(null, false);

            if (!str_contains($classes, 'lp-diff__line--inserted')) {
                $oldLines[] = $text;
            }
            if (!str_contains($classes, 'lp-diff__line--deleted')) {
                $newLines[] = $text;
            }
        }

        self::assertSame($old, implode("\n", $oldLines));
        self::assertSame($new, implode("\n", $newLines));
    }

    /**
     * A comment always lands on the latest version, so a diff that ends anywhere
     * else has nothing to anchor against: the quote would be stored on a version
     * AnchorService never re-anchors from, invisible from the moment it is made.
     */
    public function test_a_diff_that_does_not_end_at_the_current_version_accepts_no_comment(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-diff-ro', 'owner-diff-ro@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Read Only Diff');
        $version = $doc->addVersion('# Body', '<h1>Body</h1>');
        $em->persist($doc);
        $em->persist(new Comment($version, $owner, 'Still open', new Anchor('Body', '', '', 0)));
        $doc->addVersion('# Rewritten body', '<h1>Rewritten body</h1>');
        $doc->addVersion('# Rewritten again', '<h1>Rewritten again</h1>');
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $diff = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review/diff/1/2');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $diff->filter('[data-controller~="comment-anchor"]'));
        self::assertCount(0, $diff->filter('[data-comment-anchor-target="doc"]'));
        self::assertCount(0, $diff->filter('[data-comment-anchor-target="agentHighlight"]'));
        self::assertCount(0, $diff->filter('#comment-threads'));
        self::assertCount(0, $diff->filter('.lp-comment-composer'));
        self::assertCount(0, $diff->filter('.lp-anchor-toolbar'));
        self::assertStringNotContainsString('data-diff-offset', (string) $client->getResponse()->getContent());

        // The review page on the current version, so the assertions above cannot
        // pass merely because the selectors never match anything.
        $latest = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');
        self::assertCount(1, $latest->filter('[data-controller~="comment-anchor"]'));
        self::assertCount(1, $latest->filter('[data-comment-anchor-target="doc"]'));
        self::assertCount(1, $latest->filter('#comment-threads'));
        self::assertCount(2, $latest->filter('button[name="submit_review_form[verdict]"]'));
        // Not an exact count: how many composers the review page offers is the
        // business of whatever review actions exist, and it has already grown from
        // one to two. What this control has to establish is that the selector
        // matches something when comments are accepted.
        self::assertGreaterThan(0, $latest->filter('.lp-comment-composer')->count());
    }

    /**
     * The newer side IS the version a comment lands on, so the pane accepts one.
     * The verdict stays off, because it describes a document rather than a
     * comparison.
     */
    public function test_a_diff_ending_at_the_current_version_accepts_a_comment(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $renderer = static::getContainer()->get(MarkdownRenderer::class);

        $owner = $this->createUser($em, 'owner-diff-rw', 'owner-diff-rw@example.com');
        $project = $this->project($em, $owner);

        $newSource = "# Plan\n\nThe rollout takes three steps.\n";
        $doc = new Document(owner: $owner, project: $project, title: 'Commentable Diff');
        $doc->addVersion("# Plan\n\nThe rollout takes one step.\n", $renderer->render("# Plan\n\nThe rollout takes one step.\n"));
        $doc->addVersion($newSource, $renderer->render($newSource));
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $diff = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review/diff/1/2');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $diff->filter('[data-controller~="comment-anchor"]'));
        self::assertCount(1, $diff->filter('[data-comment-anchor-diff-value="true"]'));
        self::assertCount(1, $diff->filter('.lp-diff-doc[data-comment-anchor-target="doc"]'));
        self::assertCount(1, $diff->filter('#comment-threads'));
        self::assertCount(1, $diff->filter('.lp-anchor-toolbar'));
        self::assertGreaterThan(0, $diff->filter('.lp-comment-composer')->count());

        // Still a comparison: nothing that reports on a single version is offered.
        self::assertCount(0, $diff->filter('button[name="submit_review_form[verdict]"]'));

        // Inserted text carries an offset. Deleted text carries none, which is
        // what lets the browser refuse a selection that touches it.
        $inserted = $diff->filter('.lp-diff__mark--inserted [data-diff-offset]');
        self::assertGreaterThan(0, $inserted->count());
        self::assertCount(0, $diff->filter('.lp-diff__mark--deleted [data-diff-offset]'));

        // The offsets name places in the version a comment would land on.
        $plainText = DocumentVersion::plainTextOf($renderer->render($newSource));
        foreach ($diff->filter('[data-diff-offset]') as $span) {
            self::assertInstanceOf(\DOMElement::class, $span);
            $offset = (int) $span->getAttribute('data-diff-offset');
            $text = $span->textContent;
            self::assertSame($text, mb_substr($plainText, $offset, mb_strlen($text, 'UTF-8'), 'UTF-8'));
        }
    }

    /**
     * A document past the diff's size bound must reach a rendered message rather
     * than a request that runs for a quarter of a minute — this shape measures
     * 14.4s against the bare library, where refusing it takes under 3ms.
     */
    public function test_a_document_too_large_to_compare_still_renders_its_page(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-diff-big', 'owner-diff-big@example.com');
        $project = $this->project($em, $owner);

        $huge = implode("\n", array_fill(0, 10_000, '- a changelog entry that stays the same'));

        $doc = new Document(owner: $owner, project: $project, title: 'Huge Changelog');
        $doc->addVersion($huge, '<p>v1</p>');
        $doc->addVersion($huge."\n- one more entry", '<p>v2</p>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review/diff/1/2');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.lp-diff-doc'));
        self::assertSelectorTextContains('.lp-empty', 'too large to compare');
        // The versions themselves are still readable, which is what the message
        // points the reviewer at.
        self::assertGreaterThan(0, $crawler->filter('.lp-version-pill--link')->count());
    }

    /**
     * A reviewer asked to compare two versions and the app declined, so the
     * refusal belongs in the trail with the bounded reason that caused it.
     */
    public function test_a_refused_comparison_is_recorded_against_the_document(): void
    {
        $client = static::createClient();
        $audit = RecordingAuditor::installedIn(static::getContainer());
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-diff-audit', 'owner-diff-audit@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Private Use Audit Doc');
        $doc->addVersion('A trap here', '<p>v1</p>');
        $doc->addVersion("A \u{fcffc}\u{ff2fb}trap\u{fff41}\u{fcffc} here", '<p>v2</p>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->disableReboot();
        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review/diff/1/2');

        self::assertResponseIsSuccessful();

        $record = $audit->record('review.document_diff_refused');
        self::assertSame(AuditOutcome::Refused, $record->outcome);
        self::assertSame([
            'documentId' => $id,
            'from' => 1,
            'to' => 2,
            'reason' => DiffRefusal::UnsupportedCharacters->value,
        ], $record->context);
        self::assertNotNull($record->subject);
        self::assertSame('document', $record->subject->type);
        self::assertSame($id, $record->subject->id);
    }

    public function test_the_diff_handler_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(DiffDocumentVersionsHandler::class);
    }

    /**
     * The refusal message is keyed off the reason, so each reason needs its own
     * trans-unit — a missing one renders the raw key at the reader.
     */
    public function test_a_version_holding_the_diff_library_s_own_markers_says_so(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-diff-pua', 'owner-diff-pua@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Private Use Doc');
        $doc->addVersion('A trap here', '<p>v1</p>');
        $doc->addVersion("A \u{fcffc}\u{ff2fb}trap\u{fff41}\u{fcffc} here", '<p>v2</p>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review/diff/1/2');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.lp-diff-doc'));
        self::assertSelectorTextContains('.lp-empty', 'cannot handle');
    }

    public function test_two_versions_that_read_the_same_say_so_rather_than_show_an_empty_diff(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-diff-same', 'owner-diff-same@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Unchanged Doc');
        $doc->addVersion("# Plan\n\nUnchanged body.\n", '<h1>Plan</h1>');
        $doc->addVersion("# Plan\n\nUnchanged body.\n", '<h1>Plan</h1>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review/diff/1/2');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.lp-diff-doc'));
        self::assertCount(0, $crawler->filter('[data-controller~="diff-navigation"]'));
        self::assertSelectorTextContains('.lp-empty', 'identical');
    }

    /**
     * The contents panel lists a version's own headings, and a diff's headings
     * belong to two versions: a heading reworded across them appears once,
     * carrying both texts and an id derived from the pair. So the panel stays
     * off, while the ids themselves remain for a document's own in-page links.
     */
    public function test_a_diff_renders_no_contents_panel(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-diff-toc', 'owner-diff-toc@example.com');
        $project = $this->project($em, $owner);

        $renderer = new MarkdownRenderer(new NullLogger(), new IdentityTranslator());
        $old = "## First\n\nBody.\n\n## Second\n\nMore.\n";
        $new = "## First\n\nRevised body.\n\n## Second\n\nMore.\n";

        $doc = new Document(owner: $owner, project: $project, title: 'Sectioned Diff');
        $doc->addVersion($old, $renderer->render($old));
        $doc->addVersion($new, $renderer->render($new));
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $diff = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review/diff/1/2');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $diff->filter('.lp-review-contents'));

        // The review page for the same document, so the assertion cannot pass
        // merely because this document never had a contents panel.
        $latest = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');
        self::assertCount(1, $latest->filter('.lp-review-contents'));
    }

    public function test_a_diff_that_does_not_run_forwards_is_not_found(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-diff-404', 'owner-diff-404@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Backwards Diff');
        $doc->addVersion('# v1', '<h1>v1</h1>');
        $doc->addVersion('# v2', '<h1>v2</h1>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $base = '/projects/'.$projectId.'/documents/'.$id.'/review/diff/';

        $client->request(Request::METHOD_GET, $base.'2/1');
        self::assertResponseStatusCodeSame(404);

        $client->request(Request::METHOD_GET, $base.'1/1');
        self::assertResponseStatusCodeSame(404);

        $client->request(Request::METHOD_GET, $base.'1/9');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The only links into a diff compare a version with the one before it, so
     * without a picker on the page itself, comparing v1 with v4 means editing
     * the URL by hand.
     */
    public function test_the_diff_offers_a_picker_for_any_pair_of_versions(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-diff-pick', 'owner-diff-pick@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Four Versions');
        foreach (range(1, 4) as $number) {
            $doc->addVersion("# Plan\n\nRevision {$number} of the body.", '<h1>Plan</h1>');
        }
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $base = '/projects/'.$projectId.'/documents/'.$id.'/review/diff/';
        $crawler = $client->request(Request::METHOD_GET, $base.'3/4');

        self::assertResponseIsSuccessful();
        $picker = $crawler->filter('[data-controller="version-compare"]');
        self::assertCount(1, $picker);
        self::assertSame($base.'3/4', $picker->attr('data-version-compare-url-value'));
        // The newest version is never an earlier side and the oldest never a
        // later one, so neither appears in the select it could only 404 from.
        self::assertSame(['1', '2', '3'], $crawler->filter('[data-version-compare-target="from"] option')->each(
            static fn (Crawler $node): string => (string) $node->attr('value'),
        ));
        self::assertSame(['2', '3', '4'], $crawler->filter('[data-version-compare-target="to"] option')->each(
            static fn (Crawler $node): string => (string) $node->attr('value'),
        ));
        self::assertSame('3', $crawler->filter('[data-version-compare-target="from"] option[selected]')->attr('value'));
        self::assertSame('4', $crawler->filter('[data-version-compare-target="to"] option[selected]')->attr('value'));

        // The non-adjacent pair the picker exists to reach.
        $spanning = $client->request(Request::METHOD_GET, $base.'1/4');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $spanning->filter('.lp-diff-doc'));
        self::assertSame($base.'1/4', $spanning->filter('[data-controller="version-compare"]')->attr('data-version-compare-url-value'));
    }

    public function test_unauthenticated_user_is_redirected(): void
    {
        $client = static::createClient();
        $client->request(
            Request::METHOD_GET,
            '/projects/00000000-0000-0000-0000-000000000000/documents/00000000-0000-0000-0000-000000000000/review/diff/1/2',
        );

        self::assertResponseRedirects('/login');
    }
}
