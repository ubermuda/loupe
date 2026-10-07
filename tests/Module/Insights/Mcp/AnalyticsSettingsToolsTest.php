<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Mcp;

use App\Module\Insights\Entity\InsightsProjectSettings;
use App\Module\Insights\Mcp\AnalyticsSettingsGetTool;
use App\Module\Insights\Mcp\AnalyticsSettingsUpdateTool;
use App\Module\Insights\Repository\InsightsProjectSettingsRepository;
use App\Tests\Module\Insights\InsightsScenario;
use App\Tests\Support\McpTokenScenario;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AnalyticsSettingsToolsTest extends KernelTestCase
{
    use InsightsScenario;
    use McpTokenScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_get_answers_the_coded_defaults_for_a_project_with_no_settings(): void
    {
        $project = $this->scenarioProject('settings-get-none');
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame([
            'defaultModel' => null,
            'defaultEffort' => null,
            'collectFullText' => false,
            'subcommandPrograms' => [],
            'model' => 'sonnet',
            'effort' => 'medium',
        ], $this->getTool()());
    }

    public function test_get_answers_the_project_values(): void
    {
        $em = $this->em();
        $project = $this->scenarioProject('settings-get-own');
        $settings = new InsightsProjectSettings($project);
        $settings->defaultModel = 'opus';
        $settings->collectFullText = true;
        $em->persist($settings);
        $em->flush();
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame([
            'defaultModel' => 'opus',
            'defaultEffort' => null,
            'collectFullText' => true,
            'subcommandPrograms' => [],
            'model' => 'opus',
            'effort' => 'medium',
        ], $this->getTool()());
    }

    public function test_update_changes_only_the_arguments_it_gets(): void
    {
        $project = $this->scenarioProject('settings-update');
        $this->actAsMcpTokenBoundTo($project);

        $this->updateTool()(defaultModel: 'opus', defaultEffort: 'high');
        $result = $this->updateTool()(collectFullText: true);

        self::assertSame([
            'defaultModel' => 'opus',
            'defaultEffort' => 'high',
            'collectFullText' => true,
            'subcommandPrograms' => [],
            'model' => 'opus',
            'effort' => 'high',
        ], $result);
        $this->em()->clear();
        $stored = $this->settingsRepository()->findForProject($this->em()->find($project::class, $project->id) ?? throw new \LogicException());
        self::assertSame('opus', $stored?->defaultModel);
        self::assertTrue($stored->collectFullText);
    }

    public function test_an_empty_string_clears_a_value(): void
    {
        $project = $this->scenarioProject('settings-clear');
        $this->actAsMcpTokenBoundTo($project);
        $this->updateTool()(defaultModel: 'opus', defaultEffort: 'high');

        $result = $this->updateTool()(defaultModel: '', defaultEffort: '');

        self::assertNull($result['defaultModel']);
        self::assertNull($result['defaultEffort']);
        self::assertSame('sonnet', $result['model']);
        self::assertSame('medium', $result['effort']);
    }

    public function test_update_turns_the_domain_errors_into_readable_text(): void
    {
        $project = $this->scenarioProject('settings-refused');
        $this->actAsMcpTokenBoundTo($project);

        try {
            $this->updateTool()(defaultModel: 'two words', defaultEffort: 'extreme');
            self::fail('Expected a refusal.');
        } catch (ToolCallException $e) {
            self::assertSame(
                "defaultModel: A model is one word of at most 64 characters, such as sonnet or opus.\ndefaultEffort: Use one of: low, medium, high, xhigh, max.",
                $e->getMessage(),
            );
        }
    }

    public function test_update_sets_and_clears_the_subcommand_programs(): void
    {
        $project = $this->scenarioProject('settings-programs');
        $this->actAsMcpTokenBoundTo($project);

        $set = $this->updateTool()(subcommandPrograms: ['git', 'bazel', 'git']);
        $kept = $this->updateTool()(defaultModel: 'opus');
        $cleared = $this->updateTool()(subcommandPrograms: []);

        self::assertSame(['git', 'bazel'], $set['subcommandPrograms']);
        self::assertSame(['git', 'bazel'], $kept['subcommandPrograms']);
        self::assertSame([], $cleared['subcommandPrograms']);
        self::assertSame([], $this->getTool()()['subcommandPrograms']);
    }

    public function test_update_refuses_a_malformed_program_in_readable_text(): void
    {
        $project = $this->scenarioProject('settings-programs-refused');
        $this->actAsMcpTokenBoundTo($project);

        try {
            $this->updateTool()(subcommandPrograms: ['git', 'two words']);
            self::fail('Expected a refusal.');
        } catch (ToolCallException $e) {
            self::assertSame('subcommandPrograms: A program name is 1 to 40 characters of letters, digits and . _ + -.', $e->getMessage());
        }
        self::assertSame([], $this->getTool()()['subcommandPrograms']);
    }

    public function test_update_refuses_an_unbound_token(): void
    {
        $project = $this->scenarioProject('settings-unbound');
        $this->actAsUnboundMcpToken($project->owner);

        $this->expectException(ToolCallException::class);

        $this->updateTool()(collectFullText: true);
    }

    private function settingsRepository(): InsightsProjectSettingsRepository
    {
        $repository = self::getContainer()->get(InsightsProjectSettingsRepository::class);
        self::assertInstanceOf(InsightsProjectSettingsRepository::class, $repository);

        return $repository;
    }

    private function getTool(): AnalyticsSettingsGetTool
    {
        $tool = self::getContainer()->get(AnalyticsSettingsGetTool::class);
        self::assertInstanceOf(AnalyticsSettingsGetTool::class, $tool);

        return $tool;
    }

    private function updateTool(): AnalyticsSettingsUpdateTool
    {
        $tool = self::getContainer()->get(AnalyticsSettingsUpdateTool::class);
        self::assertInstanceOf(AnalyticsSettingsUpdateTool::class, $tool);

        return $tool;
    }
}
