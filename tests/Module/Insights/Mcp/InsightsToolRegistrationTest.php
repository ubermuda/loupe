<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Mcp;

use App\Mcp\FlagGatedToolInterface;
use App\Module\Bridge\Mcp\BridgeListTool;
use App\Module\Bridge\Mcp\MetricQueryTool;
use App\Module\Insights\Command\ReportAnalysisHandler;
use App\Module\Insights\Mcp\AnalysisGetTool;
use App\Module\Insights\Mcp\AnalysisReportTool;
use App\Module\Insights\Mcp\AnalyticsSettingsGetTool;
use App\Module\Insights\Mcp\AnalyticsSettingsUpdateTool;
use App\Module\Project\Mcp\AdvertisedTools;
use Mcp\Capability\Registry;
use Mcp\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/** An agent reaches the analysis tools only through the published schema, so their names and arguments are the contract. */
final class InsightsToolRegistrationTest extends KernelTestCase
{
    private Registry $registry;

    protected function setUp(): void
    {
        self::bootKernel();

        self::assertInstanceOf(Server::class, self::getContainer()->get('mcp.server'));
        $registry = self::getContainer()->get('mcp.registry');
        self::assertInstanceOf(Registry::class, $registry);
        $this->registry = $registry;
    }

    /** @return iterable<string, array{string, class-string}> */
    public static function insightsTools(): iterable
    {
        yield 'analysis_get' => [AnalysisGetTool::NAME, AnalysisGetTool::class];
        yield 'analysis_report' => [AnalysisReportTool::NAME, AnalysisReportTool::class];
        yield 'analytics_settings_get' => [AnalyticsSettingsGetTool::NAME, AnalyticsSettingsGetTool::class];
        yield 'analytics_settings_update' => [AnalyticsSettingsUpdateTool::NAME, AnalyticsSettingsUpdateTool::class];
    }

    /** @param class-string $toolClass */
    #[DataProvider('insightsTools')]
    public function test_every_insights_tool_is_published_with_no_flag(string $toolName, string $toolClass): void
    {
        self::assertTrue($this->registry->hasTool($toolName));
        $tool = self::getContainer()->get($toolClass);
        self::assertInstanceOf($toolClass, $tool);
        self::assertNotInstanceOf(FlagGatedToolInterface::class, $tool);
    }

    /** @param class-string $toolClass */
    #[DataProvider('insightsTools')]
    public function test_every_insights_tool_has_a_connect_page_description(string $toolName, string $toolClass): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        $key = 'project.connect.tool.'.$toolName;

        self::assertNotSame($key, $translator->trans($key));
    }

    public function test_the_insights_tools_are_advertised_after_metric_query(): void
    {
        $advertised = self::getContainer()->get(AdvertisedTools::class);
        self::assertInstanceOf(AdvertisedTools::class, $advertised);

        $names = array_column($advertised->enabled(), 'name');
        $start = array_search(MetricQueryTool::NAME, $names, true);
        self::assertIsInt($start);

        self::assertSame(
            [MetricQueryTool::NAME, AnalysisGetTool::NAME, AnalysisReportTool::NAME, AnalyticsSettingsGetTool::NAME, AnalyticsSettingsUpdateTool::NAME, BridgeListTool::NAME],
            \array_slice($names, $start, 6),
        );
    }

    public function test_analysis_get_requires_an_analysis_id(): void
    {
        self::assertSame(['analysisId'], $this->registry->getTool(AnalysisGetTool::NAME)->tool->inputSchema['required']);
    }

    public function test_analysis_report_publishes_its_proposals_as_a_bounded_list_of_objects(): void
    {
        $schema = $this->registry->getTool(AnalysisReportTool::NAME)->tool->inputSchema;
        $proposals = $schema['properties']['proposals'];

        self::assertSame(['analysisId', 'documentId'], $schema['required']);
        self::assertSame('array', $proposals['type']);
        self::assertSame(ReportAnalysisHandler::MAX_PROPOSALS, $proposals['maxItems']);
        self::assertSame('object', $proposals['items']['type']);
        self::assertSame(['kind', 'title', 'body'], $proposals['items']['required']);
        self::assertSame(['card', 'bucket-rule'], $proposals['items']['properties']['kind']['enum']);
        self::assertSame('object', $proposals['items']['properties']['payload']['type']);
        self::assertSame('string', $proposals['items']['properties']['estimatedSaving']['type']);
    }

    public function test_the_settings_tools_take_only_optional_arguments(): void
    {
        self::assertSame([], $this->registry->getTool(AnalyticsSettingsGetTool::NAME)->tool->inputSchema['required'] ?? []);
        $update = $this->registry->getTool(AnalyticsSettingsUpdateTool::NAME)->tool->inputSchema;

        self::assertSame([], $update['required'] ?? []);
        foreach (['defaultModel', 'defaultEffort', 'collectFullText'] as $argument) {
            self::assertArrayHasKey($argument, $update['properties'], $argument);
        }
    }
}
