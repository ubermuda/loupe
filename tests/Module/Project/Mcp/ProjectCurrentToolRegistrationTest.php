<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Mcp;

use App\Mcp\FlagGatedToolInterface;
use App\Module\Project\Mcp\AdvertisedTools;
use App\Module\Project\Mcp\ProjectCurrentTool;
use Mcp\Capability\Registry;
use Mcp\Server;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A tool is reached only through the published schema, so registration, the
 * advertised order and the description key are the contract rather than the
 * class existing.
 */
final class ProjectCurrentToolRegistrationTest extends KernelTestCase
{
    private Registry $registry;

    protected function setUp(): void
    {
        self::bootKernel();

        // Building the server is what runs the discovery loaders. The registry
        // service on its own has none and reads back empty.
        self::assertInstanceOf(Server::class, self::getContainer()->get('mcp.server'));

        $registry = self::getContainer()->get('mcp.registry');
        self::assertInstanceOf(Registry::class, $registry);
        $this->registry = $registry;
    }

    public function test_the_tool_is_published(): void
    {
        self::assertTrue($this->registry->hasTool(ProjectCurrentTool::NAME));
        self::assertInstanceOf(ProjectCurrentTool::class, self::getContainer()->get(ProjectCurrentTool::class));
    }

    public function test_it_takes_no_arguments_so_a_caller_cannot_redirect_the_project(): void
    {
        $schema = $this->registry->getTool(ProjectCurrentTool::NAME)->tool->inputSchema;

        // An argument-free schema publishes `properties` as an empty JSON
        // object rather than an empty array, so count() reads both shapes.
        self::assertCount(0, (array) ($schema['properties'] ?? []));
        self::assertCount(0, (array) ($schema['required'] ?? []));
    }

    public function test_it_is_advertised_first_and_behind_no_flag(): void
    {
        $advertised = self::getContainer()->get(AdvertisedTools::class);
        self::assertInstanceOf(AdvertisedTools::class, $advertised);

        $names = array_column($advertised->enabled(), 'name');

        // Guard: an empty roster would satisfy the position assertion below.
        self::assertContains('document_create', $names);
        self::assertSame(ProjectCurrentTool::NAME, $names[0]);

        self::assertNotInstanceOf(FlagGatedToolInterface::class, self::getContainer()->get(ProjectCurrentTool::class));
    }

    public function test_its_description_key_is_translated(): void
    {
        $advertised = self::getContainer()->get(AdvertisedTools::class);
        self::assertInstanceOf(AdvertisedTools::class, $advertised);
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $keys = array_column($advertised->enabled(), 'descriptionKey', 'name');
        $key = $keys[ProjectCurrentTool::NAME];

        // An untranslated key comes back as itself, which reads as a label.
        self::assertNotSame($key, $translator->trans($key));
    }
}
