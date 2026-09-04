<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Module\Project\Mcp\AdvertisedTools;
use Mcp\Capability\Registry;
use Mcp\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The series tools are reached only through the published schema, so their
 * names and the shape of the placement parameters are part of the contract.
 */
final class SeriesToolRegistrationTest extends KernelTestCase
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

    public function test_every_series_tool_is_published(): void
    {
        self::assertTrue($this->registry->hasTool('document_set_series'));
        self::assertTrue($this->registry->hasTool('series_list'));
        self::assertTrue($this->registry->hasTool('series_rename'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function toolsTakingAPlacement(): iterable
    {
        yield 'document_create' => ['document_create'];
        yield 'document_revise' => ['document_revise'];
        yield 'document_set_series' => ['document_set_series'];
    }

    #[DataProvider('toolsTakingAPlacement')]
    public function test_the_placement_parameters_are_published(string $toolName): void
    {
        $schema = $this->registry->getTool($toolName)->tool->inputSchema;

        self::assertArrayHasKey('series', $schema['properties']);
        self::assertArrayHasKey('seriesOrdinal', $schema['properties']);
        // Optional on every tool: a document that belongs to no series is the
        // ordinary case, and a required parameter would force one on every call.
        self::assertNotContains('series', $schema['required'] ?? []);
        self::assertNotContains('seriesOrdinal', $schema['required'] ?? []);
    }

    public function test_series_rename_requires_both_names(): void
    {
        $schema = $this->registry->getTool('series_rename')->tool->inputSchema;

        self::assertSame(['series', 'newName'], $schema['required']);
    }

    /** A tool missing from the reading order is advertised at the end of the list. */
    public function test_the_series_tools_are_placed_in_the_advertised_reading_order(): void
    {
        $advertised = self::getContainer()->get(AdvertisedTools::class);
        self::assertInstanceOf(AdvertisedTools::class, $advertised);

        $names = array_column($advertised->enabled(), 'name');
        $order = array_flip($names);

        self::assertArrayHasKey('document_set_series', $order);
        self::assertArrayHasKey('series_list', $order);
        self::assertArrayHasKey('series_rename', $order);
        self::assertLessThan($order['series_list'], $order['document_set_series']);
        self::assertLessThan($order['series_rename'], $order['series_list']);
    }
}
