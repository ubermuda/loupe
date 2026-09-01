<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\RefreshDocumentVersionsHtmlCommand;
use App\Module\Review\Command\RefreshDocumentVersionsHtmlHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Service\MarkdownRenderer;
use App\Module\Review\ValueObject\Anchor;
use App\Tests\Support\RecordingAuditor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class RefreshDocumentVersionsHtmlHandlerTest extends KernelTestCase
{
    private const string STRANDING_QUOTE = 'The quoted sentence lives here.';
    private const string STRANDING_STORED_HTML = '<p>The quoted sentence lives here.</p>';

    public function test_stale_rendered_html_is_refreshed_from_markdown_source(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $connection = $container->get(Connection::class);
        $renderer = $container->get(MarkdownRenderer::class);

        $user = new User(fullName: 'Rerender Owner', email: 'rerender-owner@example.com', password: 'hashed-placeholder');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = $container->get(CreateDocumentHandler::class);
        $markdown = '# Fresh title';
        $doc = $createHandler(new CreateDocumentCommand($project, 'Rerender Doc', $markdown));
        $currentVersion = $doc->currentVersion();
        $versionId = $currentVersion->id;
        self::assertNotNull($versionId);

        // Simulate HTML rendered by a stale renderer version: overwrite the
        // column directly (rendered_html is readonly on the entity).
        $connection->executeStatement(
            'UPDATE document_versions SET rendered_html = :html WHERE id = :id::uuid',
            ['html' => '<p>stale</p>', 'id' => (string) $versionId],
        );

        /** @var RefreshDocumentVersionsHtmlHandler $handler */
        $handler = $container->get(RefreshDocumentVersionsHtmlHandler::class);
        $result = $handler(new RefreshDocumentVersionsHtmlCommand());

        self::assertGreaterThanOrEqual(1, $result->total);
        self::assertGreaterThanOrEqual(1, $result->changed);

        $freshHtml = $connection->fetchOne(
            'SELECT rendered_html FROM document_versions WHERE id = :id::uuid',
            ['id' => (string) $versionId],
        );
        self::assertIsString($freshHtml);
        self::assertSame($renderer->render($markdown), $freshHtml);
    }

    public function test_unchanged_rows_are_not_counted_as_changed(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        $user = new User(fullName: 'Rerender Owner 2', email: 'rerender-owner2@example.com', password: 'hashed-placeholder');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = $container->get(CreateDocumentHandler::class);
        $createHandler(new CreateDocumentCommand($project, 'Already Fresh Doc', '# Already fresh'));

        /** @var RefreshDocumentVersionsHtmlHandler $handler */
        $handler = $container->get(RefreshDocumentVersionsHtmlHandler::class);
        $result = $handler(new RefreshDocumentVersionsHtmlCommand());

        self::assertSame(0, $result->changed);
    }

    public function test_it_refuses_and_writes_nothing_when_a_comment_anchor_would_move(): void
    {
        [$container, $connection, $versionId] = $this->seedStrandingVersion('would-move');

        /** @var RefreshDocumentVersionsHtmlHandler $handler */
        $handler = $container->get(RefreshDocumentVersionsHtmlHandler::class);
        $result = $handler(new RefreshDocumentVersionsHtmlCommand());

        self::assertTrue($result->refused);
        self::assertSame(1, $result->atRisk);
        self::assertSame(0, $result->changed);
        // The whole point of refusing: the table is left exactly as it was, not
        // rewritten up to the first at-risk row.
        self::assertSame(self::STRANDING_STORED_HTML, $connection->fetchOne(
            'SELECT rendered_html FROM document_versions WHERE id = :id::uuid',
            ['id' => (string) $versionId],
        ));
    }

    public function test_the_opt_in_flag_lets_it_through(): void
    {
        [$container, $connection, $versionId] = $this->seedStrandingVersion('opt-in');

        /** @var RefreshDocumentVersionsHtmlHandler $handler */
        $handler = $container->get(RefreshDocumentVersionsHtmlHandler::class);
        $result = $handler(new RefreshDocumentVersionsHtmlCommand(acceptCommentOrphaning: true));

        self::assertFalse($result->refused);
        self::assertSame(1, $result->atRisk, 'the count is still reported, so the damage is on the record');
        self::assertGreaterThanOrEqual(1, $result->changed);
        self::assertNotSame(self::STRANDING_STORED_HTML, $connection->fetchOne(
            'SELECT rendered_html FROM document_versions WHERE id = :id::uuid',
            ['id' => (string) $versionId],
        ));
    }

    public function test_a_comment_already_unresolvable_does_not_block_the_run(): void
    {
        // It was stranded before this run and is no worse off after it, so
        // refusing on its account offers the operator nothing but the
        // destructive flag. The quote appears in neither the stored HTML nor the
        // re-rendered HTML; the orphaned flag is set to match how such a comment
        // reaches this state in production, but the guard never reads it.
        [$container] = $this->seedVersion(
            'already-orphaned',
            markdown: '# Fresh title',
            storedHtml: '<p>stale</p>',
            quote: 'a quote that appears nowhere',
            orphaned: true,
        );

        /** @var RefreshDocumentVersionsHtmlHandler $handler */
        $handler = $container->get(RefreshDocumentVersionsHtmlHandler::class);
        $result = $handler(new RefreshDocumentVersionsHtmlCommand());

        self::assertFalse($result->refused);
        self::assertSame(0, $result->atRisk);
        self::assertGreaterThanOrEqual(1, $result->changed);
    }

    public function test_a_flagged_comment_whose_quote_came_back_is_still_protected(): void
    {
        // Why resolvability is measured rather than read off comments.orphaned:
        // the flag is only written when a document is revised, so a comment can
        // carry it while its quote has since reappeared. Skipping every flagged
        // comment would let this run silently take it away again.
        [$container] = $this->seedVersion(
            'flag-is-stale',
            markdown: '# Fresh title',
            storedHtml: '<p>The quoted sentence lives here.</p>',
            quote: 'The quoted sentence lives here.',
            orphaned: true,
        );

        /** @var RefreshDocumentVersionsHtmlHandler $handler */
        $handler = $container->get(RefreshDocumentVersionsHtmlHandler::class);
        $result = $handler(new RefreshDocumentVersionsHtmlCommand());

        self::assertTrue($result->refused);
        self::assertSame(1, $result->atRisk);
    }

    public function test_an_edit_that_leaves_every_anchor_resolvable_does_not_refuse(): void
    {
        // The guard has to fire on stranded anchors, not on any text change at
        // all. Here the stored HTML carries an extra paragraph the fresh render
        // drops — plain text differs, but the quoted sentence is still there, so
        // every comment resolves and there is nothing to warn about. Refusing
        // here is what would teach people to pass the override by reflex.
        [$container, $connection, $versionId] = $this->seedVersion(
            'resolvable',
            markdown: "The quoted sentence lives here.\n",
            storedHtml: "<p>The quoted sentence lives here.</p>\n<p>A paragraph since removed.</p>\n",
            quote: 'The quoted sentence lives here.',
        );

        /** @var RefreshDocumentVersionsHtmlHandler $handler */
        $handler = $container->get(RefreshDocumentVersionsHtmlHandler::class);
        $result = $handler(new RefreshDocumentVersionsHtmlCommand());

        self::assertFalse($result->refused);
        self::assertSame(0, $result->atRisk);
        self::assertGreaterThanOrEqual(1, $result->changed, 'the re-render should still have happened');
        self::assertStringNotContainsString('A paragraph since removed.', (string) $connection->fetchOne(
            'SELECT rendered_html FROM document_versions WHERE id = :id::uuid',
            ['id' => (string) $versionId],
        ));
    }

    public function test_an_untargeted_comment_is_not_treated_as_at_risk(): void
    {
        // An empty quote is the storage sentinel for a comment attached to no
        // span, and ReanchoringService never relocates one. Counting it would
        // raise an alarm that cannot come true, which is how an opt-in flag
        // turns into something people pass by reflex.
        [$container] = $this->seedStaleVersionWithComment('untargeted', '');

        /** @var RefreshDocumentVersionsHtmlHandler $handler */
        $handler = $container->get(RefreshDocumentVersionsHtmlHandler::class);
        $result = $handler(new RefreshDocumentVersionsHtmlCommand());

        self::assertFalse($result->refused);
        self::assertSame(0, $result->atRisk);
    }

    public function test_reanchoring_moves_an_anchor_onto_the_re_rendered_text(): void
    {
        // "title" survives the re-render but sits at a different offset in it;
        // the seeded hint of 0 is what the old text said.
        [$container, $connection, $versionId] = $this->seedVersion('reanchor-moves', '# Fresh title', '<p>stale</p>', 'title');

        $handler = $container->get(RefreshDocumentVersionsHtmlHandler::class);
        $result = $handler(new RefreshDocumentVersionsHtmlCommand(reanchor: true));

        self::assertFalse($result->refused);
        self::assertSame(1, $result->reanchored);
        self::assertSame(0, $result->orphaned);

        $row = $connection->fetchAssociative(
            'SELECT c.anchor_offset_hint, c.anchor_prefix, c.anchor_suffix, c.orphaned FROM comments c WHERE c.version_id = :id::uuid',
            ['id' => (string) $versionId],
        );
        self::assertIsArray($row);
        self::assertSame(6, (int) $row['anchor_offset_hint'], 'the offset of "title" in "Fresh title"');
        self::assertFalse((bool) $row['orphaned']);
        // The context, not just the offset. The browser never receives
        // offsetHint and re-locates by quote and surrounding text, so an anchor
        // whose prefix still described the old rendering would keep sending it
        // to the wrong occurrence of a repeated quote.
        self::assertSame('Fresh ', $row['anchor_prefix']);
        self::assertSame('', trim((string) $row['anchor_suffix']), '"title" ends the rendered text');
    }

    public function test_a_second_reanchor_run_reports_no_further_work(): void
    {
        [$container] = $this->seedStrandingVersion('reanchor-twice');

        $handler = $container->get(RefreshDocumentVersionsHtmlHandler::class);
        self::assertSame(1, $handler(new RefreshDocumentVersionsHtmlCommand(reanchor: true))->orphaned);

        // Nothing changed the second time, and both counts must say so —
        // otherwise every re-run re-reports the same work as if it were new.
        $second = $handler(new RefreshDocumentVersionsHtmlCommand(reanchor: true));
        self::assertSame(0, $second->orphaned);
        self::assertSame(0, $second->reanchored);
    }

    public function test_reanchoring_marks_a_comment_the_new_text_no_longer_contains(): void
    {
        [$container, $connection, $versionId] = $this->seedStrandingVersion('reanchor-strands');

        $handler = $container->get(RefreshDocumentVersionsHtmlHandler::class);
        $result = $handler(new RefreshDocumentVersionsHtmlCommand(reanchor: true));

        // Reanchoring answers the refusal rather than being stopped by it.
        self::assertFalse($result->refused);
        self::assertSame(1, $result->orphaned);
        self::assertSame(0, $result->reanchored);

        $row = $connection->fetchAssociative(
            'SELECT c.anchor_offset_hint, c.anchor_quote, c.orphaned FROM comments c WHERE c.version_id = :id::uuid',
            ['id' => (string) $versionId],
        );
        self::assertIsArray($row);
        self::assertTrue((bool) $row['orphaned'], 'flagged here rather than waiting for someone to revise');
        // The anchor itself is left alone so a later revision can still try to
        // place it — only the flag records that this rendering cannot.
        self::assertSame(self::STRANDING_QUOTE, $row['anchor_quote']);
        self::assertSame(0, (int) $row['anchor_offset_hint']);

        // And the re-render did land: the point is both halves commit together.
        self::assertNotSame(self::STRANDING_STORED_HTML, $connection->fetchOne(
            'SELECT rendered_html FROM document_versions WHERE id = :id::uuid',
            ['id' => (string) $versionId],
        ));
    }

    /**
     * One record for the whole sweep. Not one per comment, which would put a row
     * in the trail for every comment in the database, and not none, which left
     * the only operation that rewrites every version unrecorded.
     */
    public function test_the_sweep_is_recorded_as_one_batch_record(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $this->seedStrandingVersion('audit-batch');
        $audit->forget();

        $handler = self::getContainer()->get(RefreshDocumentVersionsHtmlHandler::class);
        self::assertInstanceOf(RefreshDocumentVersionsHtmlHandler::class, $handler);
        $result = $handler(new RefreshDocumentVersionsHtmlCommand(reanchor: true));

        self::assertSame(1, $result->orphaned);
        self::assertGreaterThanOrEqual(1, $result->changed);

        self::assertSame(['review.document_version.rerendered'], $audit->operations());

        $record = $audit->record('review.document_version.rerendered');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNull($record->subject, 'the sweep walks the table, so no single row is its subject');
        self::assertSame([
            'total' => $result->total,
            'changed' => $result->changed,
            'reanchored' => $result->reanchored,
            'orphaned' => $result->orphaned,
            'atRisk' => $result->atRisk,
        ], $record->context);
    }

    /** A refusal wrote nothing, so the record must not claim a sweep happened. */
    public function test_a_refused_sweep_is_recorded_as_refused(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $this->seedStrandingVersion('audit-refused');
        $audit->forget();

        $handler = self::getContainer()->get(RefreshDocumentVersionsHtmlHandler::class);
        self::assertInstanceOf(RefreshDocumentVersionsHtmlHandler::class, $handler);
        $result = $handler(new RefreshDocumentVersionsHtmlCommand());

        self::assertTrue($result->refused);

        $record = $audit->record('review.document_version.rerendered');
        self::assertSame(AuditOutcome::Refused, $record->outcome);
        self::assertSame([
            'total' => 0,
            'changed' => 0,
            'reanchored' => 0,
            'orphaned' => 0,
            'atRisk' => 1,
        ], $record->context);
    }

    /**
     * A version whose stored HTML no longer matches its Markdown, carrying one
     * comment with $quote as its anchor.
     *
     * @return array{ContainerInterface, Connection, Uuid}
     */
    private function seedStaleVersionWithComment(string $slug, string $quote): array
    {
        return $this->seedVersion($slug, '# Fresh title', '<p>stale</p>', $quote);
    }

    /**
     * A comment that resolves against the stored HTML and stops resolving after
     * the re-render — the only shape that is genuinely damaged by this run.
     *
     * @return array{ContainerInterface, Connection, Uuid}
     */
    private function seedStrandingVersion(string $slug): array
    {
        return $this->seedVersion($slug, '# Fresh title', self::STRANDING_STORED_HTML, self::STRANDING_QUOTE);
    }

    /**
     * @return array{ContainerInterface, Connection, Uuid}
     */
    private function seedVersion(string $slug, string $markdown, string $storedHtml, string $quote, bool $orphaned = false): array
    {
        // Only when the test has not booted already: rebooting would discard a
        // service the test put in the container before it seeded.
        if (null === self::$kernel) {
            self::bootKernel();
        }

        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $connection = $container->get(Connection::class);

        $user = new User(fullName: 'Rerender Owner', email: "rerender-{$slug}@example.com", password: 'hashed-placeholder');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = $container->get(CreateDocumentHandler::class);
        $document = $createHandler(new CreateDocumentCommand($project, "Doc {$slug}", $markdown));
        $version = $document->currentVersion();

        $comment = new Comment($version, $user, 'probe', new Anchor($quote, '', '', 0));
        $comment->orphaned = $orphaned;
        $em->persist($comment);
        $em->flush();

        $versionId = $version->id;
        self::assertNotNull($versionId);
        $connection->executeStatement(
            'UPDATE document_versions SET rendered_html = :html WHERE id = :id::uuid',
            ['html' => $storedHtml, 'id' => (string) $versionId],
        );

        return [$container, $connection, $versionId];
    }
}
