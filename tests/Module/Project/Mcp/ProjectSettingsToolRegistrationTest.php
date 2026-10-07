<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Mcp;

use App\Mcp\FlagGatedToolInterface;
use App\Module\Project\Mcp\AdvertisedTools;
use App\Module\Project\Mcp\ProjectCurrentTool;
use App\Module\Project\Mcp\ProjectOriginsSetTool;
use App\Module\Project\Mcp\ProjectUpdateTool;
use App\Module\Readiness\Mcp\ReadinessGuideSetTool;
use Mcp\Capability\Registry;
use Mcp\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The project settings tools are reached only through the published schema, so their names, order and arguments are the contract. */
final class ProjectSettingsToolRegistrationTest extends KernelTestCase
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
        yield 'project_update' => [ProjectUpdateTool::NAME, ProjectUpdateTool::class];
        yield 'project_origins_set' => [ProjectOriginsSetTool::NAME, ProjectOriginsSetTool::class];
        yield 'readiness_guide_set' => [ReadinessGuideSetTool::NAME, ReadinessGuideSetTool::class];
    }

    /** @param class-string $toolClass */
    #[DataProvider('tools')]
    public function test_the_tool_is_published_behind_no_flag_with_a_translated_description(string $toolName, string $toolClass): void
    {
        self::assertTrue($this->registry->hasTool($toolName));
        self::assertNotInstanceOf(FlagGatedToolInterface::class, self::getContainer()->get($toolClass));

        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        $key = 'project.connect.tool.'.$toolName;
        self::assertNotSame($key, $translator->trans($key));
    }

    public function test_the_tools_are_advertised_right_after_project_current(): void
    {
        $advertised = self::getContainer()->get(AdvertisedTools::class);
        self::assertInstanceOf(AdvertisedTools::class, $advertised);

        $names = array_column($advertised->enabled(), 'name');

        self::assertSame(
            [ProjectCurrentTool::NAME, ProjectUpdateTool::NAME, ProjectOriginsSetTool::NAME, ReadinessGuideSetTool::NAME],
            \array_slice($names, 0, 4),
        );
    }

    public function test_project_update_requires_no_argument(): void
    {
        $schema = $this->registry->getTool(ProjectUpdateTool::NAME)->tool->inputSchema;

        foreach (['name', 'description', 'domain', 'searchLanguage'] as $argument) {
            self::assertArrayHasKey($argument, $schema['properties'], $argument);
        }
        self::assertSame([], $schema['required'] ?? []);
    }

    public function test_project_origins_set_publishes_a_list_of_strings(): void
    {
        $schema = $this->registry->getTool(ProjectOriginsSetTool::NAME)->tool->inputSchema;

        self::assertSame(['origins'], $schema['required']);
        self::assertSame('array', $schema['properties']['origins']['type']);
        self::assertSame(['type' => 'string'], $schema['properties']['origins']['items']);
    }

    public function test_readiness_guide_set_requires_shown(): void
    {
        $schema = $this->registry->getTool(ReadinessGuideSetTool::NAME)->tool->inputSchema;

        self::assertSame(['shown'], $schema['required']);
        self::assertSame('boolean', $schema['properties']['shown']['type']);
    }
}
