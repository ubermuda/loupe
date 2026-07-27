<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\RefreshDocumentVersionsHtmlCommand;
use App\Module\Review\Command\RefreshDocumentVersionsHtmlHandler;
use App\Module\Review\Service\MarkdownRenderer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

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
}
