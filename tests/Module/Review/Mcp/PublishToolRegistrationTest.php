<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Module\Project\Mcp\AdvertisedTools;
use Mcp\Capability\Registry;
use Mcp\Server;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PublishToolRegistrationTest extends KernelTestCase
{
    private Registry $registry;

    protected function setUp(): void
    {
        self::bootKernel();

        // Building the server runs the discovery loaders.
        self::assertInstanceOf(Server::class, self::getContainer()->get('mcp.server'));

        $registry = self::getContainer()->get('mcp.registry');
        self::assertInstanceOf(Registry::class, $registry);
        $this->registry = $registry;
    }

    public function test_the_only_parameter_is_a_required_document_id(): void
    {
        $schema = $this->registry->getTool('document_publish')->tool->inputSchema;

        self::assertSame(['documentId'], array_keys($schema['properties']));
        self::assertSame('string', $schema['properties']['documentId']['type']);
        self::assertSame(['documentId'], $schema['required']);
    }

    public function test_document_create_offers_a_draft_flag(): void
    {
        $schema = $this->registry->getTool('document_create')->tool->inputSchema;

        self::assertSame('boolean', $schema['properties']['draft']['type']);
        self::assertNotContains('draft', $schema['required'] ?? []);
    }

    public function test_it_is_advertised_right_after_document_revise(): void
    {
        $advertised = self::getContainer()->get(AdvertisedTools::class);
        self::assertInstanceOf(AdvertisedTools::class, $advertised);

        $names = array_column($advertised->enabled(), 'name');
        $revise = array_search('document_revise', $names, true);

        self::assertIsInt($revise);
        self::assertSame('document_publish', $names[$revise + 1] ?? null);
    }
}
