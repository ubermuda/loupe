<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Service\MarkdownRenderer;
use App\Module\Review\ValueObject\Anchor;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

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

    public function test_the_diff_replaces_the_document_and_marks_what_changed(): void
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
        self::assertCount(1, $crawler->filter('.lp-diff'));
        self::assertCount(0, $crawler->filter('.lp-review-doc__prose'), 'the diff stands in for the document, not beside it');
        // "one step" and "three steps" are single runs, not four: adjacent changed
        // words separated by nothing but a space are glued into one mark.
        self::assertSame(['one step'], $crawler->filter('.lp-diff__mark--deleted')->each(
            static fn (Crawler $node): string => $node->text(),
        ));
        self::assertSame(['three steps'], $crawler->filter('.lp-diff__mark--inserted')->each(
            static fn (Crawler $node): string => $node->text(),
        ));

        // What the author claims changed, read next to what actually did.
        self::assertSelectorTextContains('.lp-diff-notes', 'Phased the rollout.');
        self::assertStringNotContainsString('The original brief.', $crawler->filter('.lp-diff-notes')->text());
    }

    /**
     * DocumentDiff::oldSource()/newSource() and _version_diff.html.twig walk the
     * same structure, but nothing makes them agree — a separator between
     * segments, a `<br>`, one more wrapping element, and the reconstruction would
     * still pass its own unit test while no longer describing the page. This
     * reads the two sides back out of the rendered pane instead: a line that is
     * not inserted belongs to the old version in full, one that is not deleted to
     * the new version in full, because a line's segments never span both sides.
     */
    public function test_both_sources_are_recoverable_from_the_rendered_diff(): void
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
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review/diff/1/2');

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

    public function test_a_diff_accepts_no_comment_and_leaves_anchoring_unattached(): void
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
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $diff = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review/diff/1/2');

        self::assertResponseIsSuccessful();
        // A diff is a page of its own, and none of the review page's apparatus
        // applies to it: what it shows is diff markup rather than
        // DocumentVersion::plainText(), so every anchor carrier would be holding a
        // quote to be re-located against text that is not there, and every write
        // control would act on a version the reader is not looking at.
        self::assertCount(0, $diff->filter('[data-controller="comment-anchor"]'));
        self::assertCount(0, $diff->filter('[data-comment-anchor-target="doc"]'));
        self::assertCount(0, $diff->filter('[data-comment-anchor-target="agentHighlight"]'));
        self::assertCount(0, $diff->filter('#comment-threads'));
        self::assertCount(0, $diff->filter('.lp-comment-composer'));
        self::assertCount(0, $diff->filter('.lp-anchor-toolbar'));
        self::assertCount(0, $diff->filter('button[name="submit_review_form[verdict]"]'));

        // The review page on the current version, so the assertions above cannot
        // pass merely because the selectors never match anything.
        $latest = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');
        self::assertCount(1, $latest->filter('[data-controller="comment-anchor"]'));
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
        self::assertCount(0, $crawler->filter('.lp-diff'));
        self::assertSelectorTextContains('.lp-empty', 'too large to compare');
        // The versions themselves are still readable, which is what the message
        // points the reviewer at.
        self::assertGreaterThan(0, $crawler->filter('.lp-version-pill--link')->count());
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
        self::assertCount(0, $crawler->filter('.lp-diff'));
        self::assertSelectorTextContains('.lp-empty', 'cannot handle');
    }

    /**
     * The contents panel is built from heading ids that live in the rendered HTML,
     * and diff mode replaces that markup with the Markdown source — so every entry
     * would point at an element no longer on the page. Neither branch that met here
     * could catch it alone: the panel's tests never render a diff, and the diff's
     * never had a panel.
     */
    public function test_a_diff_renders_no_contents_panel_and_no_heading_targets(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-diff-toc', 'owner-diff-toc@example.com');
        $project = $this->project($em, $owner);

        // Rendered for real: the ids the panel links to exist only in
        // MarkdownRenderer's output, not in the Markdown the diff shows.
        $renderer = new MarkdownRenderer(new NullLogger());
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
        // A panel entry pointing into replaced markup would be a broken link rather
        // than a missing feature, so the targets must be gone too.
        self::assertCount(0, $diff->filter('[id^="heading-"]'));

        // The review page for the same document, so neither assertion can pass
        // merely because this document never had a contents panel.
        $latest = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');
        self::assertCount(1, $latest->filter('.lp-review-contents'));
        self::assertCount(2, $latest->filter('[id^="heading-"]'));
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
        // Every version in both selects: which pairs are comparable is settled in
        // the browser, and the route already answers 404 for the rest.
        self::assertCount(4, $crawler->filter('[data-version-compare-target="from"] option'));
        self::assertCount(4, $crawler->filter('[data-version-compare-target="to"] option'));
        self::assertSame('3', $crawler->filter('[data-version-compare-target="from"] option[selected]')->attr('value'));
        self::assertSame('4', $crawler->filter('[data-version-compare-target="to"] option[selected]')->attr('value'));

        // The non-adjacent pair the picker exists to reach.
        $spanning = $client->request(Request::METHOD_GET, $base.'1/4');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $spanning->filter('.lp-diff'));
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
