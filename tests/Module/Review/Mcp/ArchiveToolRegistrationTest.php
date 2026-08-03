<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use Mcp\Capability\Registry;
use Mcp\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The archive tools are reached only through the published schema, so their
 * names and the single documentId parameter are the whole contract. A tool that
 * silently stops being registered — or grows an optional parameter — is
 * invisible to the tool tests, which call the class directly.
 */
final class ArchiveToolRegistrationTest extends KernelTestCase
{
    private Registry $registry;

    protected function setUp(): void
    {
        self::bootKernel();

        // Building the server is what runs the discovery loaders — the registry
        // service on its own has none and reads back empty.
        self::assertInstanceOf(Server::class, self::getContainer()->get('mcp.server'));

        $registry = self::getContainer()->get('mcp.registry');
        self::assertInstanceOf(Registry::class, $registry);
        $this->registry = $registry;
    }

    public function test_both_archive_tools_are_published(): void
    {
        self::assertTrue($this->registry->hasTool('document_archive'));
        self::assertTrue($this->registry->hasTool('document_unarchive'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function archiveTools(): iterable
    {
        yield 'document_archive' => ['document_archive'];
        yield 'document_unarchive' => ['document_unarchive'];
    }

    #[DataProvider('archiveTools')]
    public function test_the_only_parameter_is_a_required_document_id(string $toolName): void
    {
        $schema = $this->registry->getTool($toolName)->tool->inputSchema;

        self::assertSame(['documentId'], array_keys($schema['properties']));
        self::assertSame('string', $schema['properties']['documentId']['type']);
        self::assertSame(['documentId'], $schema['required']);
    }

    /**
     * Archiving takes a document out of the reviewer's default list, so the
     * description has to say so — an agent choosing between tools reads nothing
     * else.
     */
    #[DataProvider('archiveTools')]
    public function test_the_description_names_the_effect_on_the_default_list(string $toolName): void
    {
        $description = $this->registry->getTool($toolName)->tool->description;

        self::assertIsString($description);
        self::assertStringContainsString('default document list', $description);
    }
}
