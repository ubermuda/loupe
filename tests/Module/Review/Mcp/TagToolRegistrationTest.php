<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use Mcp\Capability\Registry;
use Mcp\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The tag tools are reached only through the published schema, so their names
 * and the shape of the `tags` parameter are part of the contract. In particular
 * the item type is inferred from the docblock and silently degrades to "anything"
 * for spellings the SDK does not parse.
 */
final class TagToolRegistrationTest extends KernelTestCase
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

    public function test_both_tag_tools_are_published(): void
    {
        self::assertTrue($this->registry->hasTool('document_set_tags'));
        self::assertTrue($this->registry->hasTool('tag_list'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function toolsTakingTags(): iterable
    {
        yield 'document_create' => ['document_create'];
        yield 'document_set_tags' => ['document_set_tags'];
    }

    #[DataProvider('toolsTakingTags')]
    public function test_the_tags_parameter_is_published_as_an_array_of_strings(string $toolName): void
    {
        $schema = $this->registry->getTool($toolName)->tool->inputSchema;

        self::assertArrayHasKey('tags', $schema['properties']);
        self::assertSame('array', $schema['properties']['tags']['type']);
        self::assertSame(['type' => 'string'], $schema['properties']['tags']['items']);
    }

    public function test_document_set_tags_requires_the_complete_set(): void
    {
        $schema = $this->registry->getTool('document_set_tags')->tool->inputSchema;

        self::assertSame(['documentId', 'tags'], $schema['required']);
    }
}
