<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Mcp;

use App\Mcp\FlagGatedToolInterface;
use App\Module\Project\Mcp\AdvertisedTools;
use App\Module\Readiness\Mcp\DiscoveryStartTool;
use App\Module\Readiness\Mcp\ReadinessGetTool;
use App\Module\Readiness\Mcp\ReadinessGuideSetTool;
use Mcp\Capability\Registry;
use Mcp\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The readiness tools are reached only through the published schema, so their names and their order are part of the contract. */
final class ReadinessToolRegistrationTest extends KernelTestCase
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

    /** @return iterable<string, array{string, class-string}> */
    public static function tools(): iterable
    {
        yield 'readiness_get' => [ReadinessGetTool::NAME, ReadinessGetTool::class];
        yield 'discovery_start' => [DiscoveryStartTool::NAME, DiscoveryStartTool::class];
    }

    /** @param class-string $toolClass */
    #[DataProvider('tools')]
    public function test_every_tool_is_published_with_no_flag_and_no_arguments(string $toolName, string $toolClass): void
    {
        self::assertTrue($this->registry->hasTool($toolName));
        $tool = self::getContainer()->get($toolClass);
        self::assertInstanceOf($toolClass, $tool);
        self::assertNotInstanceOf(FlagGatedToolInterface::class, $tool);
        $schema = $this->registry->getTool($toolName)->tool->inputSchema;
        self::assertCount(0, (array) ($schema['properties'] ?? []));
    }

    /** @param class-string $toolClass */
    #[DataProvider('tools')]
    public function test_every_tool_has_a_connect_page_description(string $toolName, string $toolClass): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        $key = 'project.connect.tool.'.$toolName;

        self::assertNotSame($key, $translator->trans($key), $toolClass);
    }

    public function test_the_tools_are_advertised_after_readiness_guide_set(): void
    {
        $advertised = self::getContainer()->get(AdvertisedTools::class);
        self::assertInstanceOf(AdvertisedTools::class, $advertised);

        $names = array_column($advertised->enabled(), 'name');
        $guide = array_search(ReadinessGuideSetTool::NAME, $names, true);
        self::assertIsInt($guide);

        self::assertSame([ReadinessGuideSetTool::NAME, ReadinessGetTool::NAME, DiscoveryStartTool::NAME], \array_slice($names, $guide, 3));
    }
}
