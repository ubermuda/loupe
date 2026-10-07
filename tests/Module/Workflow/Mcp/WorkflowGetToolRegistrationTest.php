<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Mcp;

use App\Mcp\FlagGatedToolInterface;
use App\Module\Project\Mcp\AdvertisedTools;
use App\Module\Readiness\Mcp\ReadinessReportSubmitTool;
use App\Module\Workflow\Mcp\WorkflowGetTool;
use Mcp\Capability\Registry;
use Mcp\Server;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/** A discovery run reaches workflow_get only through the published schema, so its name and its place are part of the contract. */
final class WorkflowGetToolRegistrationTest extends KernelTestCase
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

    public function test_the_tool_is_published_with_no_flag_and_no_arguments(): void
    {
        self::assertTrue($this->registry->hasTool(WorkflowGetTool::NAME));
        self::assertNotInstanceOf(FlagGatedToolInterface::class, self::getContainer()->get(WorkflowGetTool::class));

        $schema = $this->registry->getTool(WorkflowGetTool::NAME)->tool->inputSchema;
        self::assertCount(0, (array) ($schema['properties'] ?? []));
    }

    public function test_the_tool_has_a_connect_page_description(): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        $key = 'project.connect.tool.'.WorkflowGetTool::NAME;

        self::assertNotSame($key, $translator->trans($key));
    }

    public function test_the_tool_is_advertised_after_readiness_report_submit(): void
    {
        $advertised = self::getContainer()->get(AdvertisedTools::class);
        self::assertInstanceOf(AdvertisedTools::class, $advertised);

        $names = array_column($advertised->enabled(), 'name');
        $report = array_search(ReadinessReportSubmitTool::NAME, $names, true);
        self::assertIsInt($report);

        self::assertSame(WorkflowGetTool::NAME, $names[$report + 1] ?? null);
    }
}
