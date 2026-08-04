<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use Mcp\Capability\Registry;
use Mcp\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The archive tools are reached only through the published schema, so their
 * names and their parameters are the whole contract. A tool that silently stops
 * being registered — or whose reason slips from required to optional — is
 * invisible to the tool tests, which call the class directly.
 *
 * The asymmetry between the two is deliberate and pinned here: archiving states
 * a reason, restoring does not need one because there is nothing left to
 * explain.
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

    /**
     * A reason is mandatory through MCP and optional everywhere else: a person
     * archiving from the app is standing in front of the document, an agent is
     * not. The schema is the only thing that makes the agent say why.
     */
    public function test_archiving_requires_a_document_id_and_a_reason(): void
    {
        $schema = $this->registry->getTool('document_archive')->tool->inputSchema;

        self::assertSame(['documentId', 'reason'], array_keys($schema['properties']));
        self::assertSame('string', $schema['properties']['documentId']['type']);
        self::assertSame('string', $schema['properties']['reason']['type']);

        // Sorted rather than compared in place: the SDK decides the order, and
        // pinning it would make an unrelated upgrade look like a contract break.
        // A schema with no required list at all collapses to [] here and fails
        // the comparison, which is the outcome that matters.
        $required = $schema['required'] ?? [];
        sort($required);
        self::assertSame(['documentId', 'reason'], $required);
    }

    /** Restoring takes no reason — the archiving it undoes carried the explanation, and it is cleared with it. */
    public function test_the_only_unarchive_parameter_is_a_required_document_id(): void
    {
        $schema = $this->registry->getTool('document_unarchive')->tool->inputSchema;

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
