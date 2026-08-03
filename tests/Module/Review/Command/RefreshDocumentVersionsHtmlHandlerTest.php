<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\RefreshDocumentVersionsHtmlCommand;
use App\Module\Review\Command\RefreshDocumentVersionsHtmlHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Service\MarkdownRenderer;
use App\Module\Review\ValueObject\Anchor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class RefreshDocumentVersionsHtmlHandlerTest extends KernelTestCase
{
    public function test_stale_rendered_html_is_refreshed_from_markdown_source(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $connection = $container->get(Connection::class);
        $renderer = $container->get(MarkdownRenderer::class);

        $user = new User(username: 'rerender-owner', fullName: 'Rerender Owner', email: 'rerender-owner@example.com', password: 'hashed-placeholder');
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

        $user = new User(username: 'rerender-owner2', fullName: 'Rerender Owner 2', email: 'rerender-owner2@example.com', password: 'hashed-placeholder');
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
        [$container, $connection, $versionId] = $this->seedStaleVersionWithComment('would-move', 'a quote');

        /** @var RefreshDocumentVersionsHtmlHandler $handler */
        $handler = $container->get(RefreshDocumentVersionsHtmlHandler::class);
        $result = $handler(new RefreshDocumentVersionsHtmlCommand());

        self::assertTrue($result->refused);
        self::assertSame(1, $result->atRisk);
        self::assertSame(0, $result->changed);
        // The whole point of refusing: the table is left exactly as it was, not
        // rewritten up to the first at-risk row.
        self::assertSame('<p>stale</p>', $connection->fetchOne(
            'SELECT rendered_html FROM document_versions WHERE id = :id::uuid',
            ['id' => (string) $versionId],
        ));
    }

    public function test_the_opt_in_flag_lets_it_through(): void
    {
        [$container, $connection, $versionId] = $this->seedStaleVersionWithComment('opt-in', 'a quote');

        /** @var RefreshDocumentVersionsHtmlHandler $handler */
        $handler = $container->get(RefreshDocumentVersionsHtmlHandler::class);
        $result = $handler(new RefreshDocumentVersionsHtmlCommand(acceptCommentOrphaning: true));

        self::assertFalse($result->refused);
        self::assertSame(1, $result->atRisk, 'the count is still reported, so the damage is on the record');
        self::assertGreaterThanOrEqual(1, $result->changed);
        self::assertNotSame('<p>stale</p>', $connection->fetchOne(
            'SELECT rendered_html FROM document_versions WHERE id = :id::uuid',
            ['id' => (string) $versionId],
        ));
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

    /**
     * A version whose stored HTML no longer matches its Markdown, carrying one
     * comment with $quote as its anchor.
     *
     * @return array{ContainerInterface, Connection, Uuid}
     */
    private function seedStaleVersionWithComment(string $slug, string $quote): array
    {
        // '# Fresh title' renders to "Fresh title", which does not contain the
        // quote — so an anchored comment here is genuinely stranded.
        return $this->seedVersion($slug, '# Fresh title', '<p>stale</p>', $quote);
    }

    /**
     * @return array{ContainerInterface, Connection, Uuid}
     */
    private function seedVersion(string $slug, string $markdown, string $storedHtml, string $quote): array
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $connection = $container->get(Connection::class);

        $user = new User(username: "rerender-{$slug}", fullName: 'Rerender Owner', email: "rerender-{$slug}@example.com", password: 'hashed-placeholder');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = $container->get(CreateDocumentHandler::class);
        $document = $createHandler(new CreateDocumentCommand($project, "Doc {$slug}", $markdown));
        $version = $document->currentVersion();

        $em->persist(new Comment($version, $user, 'probe', new Anchor($quote, '', '', 0)));
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
