<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use Mcp\Capability\Registry;
use Mcp\Server;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * document_set_references is reached only through the published schema, so its
 * name and the shape of the `references` parameter are part of the contract. In
 * particular the item type is inferred from the docblock and silently degrades
 * to "anything" for spellings the SDK does not parse.
 */
final class ReferenceToolRegistrationTest extends KernelTestCase
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

    public function test_the_tool_is_published(): void
    {
        self::assertTrue($this->registry->hasTool('document_set_references'));
    }

    public function test_the_references_parameter_is_published_as_an_array_of_strings(): void
    {
        $schema = $this->registry->getTool('document_set_references')->tool->inputSchema;

        self::assertArrayHasKey('references', $schema['properties']);
        self::assertSame('array', $schema['properties']['references']['type']);
        self::assertSame(['type' => 'string'], $schema['properties']['references']['items']);
    }

    /** Both are required: an empty set is expressed by an empty list, not by omission. */
    public function test_both_parameters_are_required(): void
    {
        $schema = $this->registry->getTool('document_set_references')->tool->inputSchema;

        self::assertSame(['documentId', 'references'], $schema['required']);
    }
}
