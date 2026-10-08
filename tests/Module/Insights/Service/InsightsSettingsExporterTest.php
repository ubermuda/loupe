<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service;

use App\Module\Insights\Entity\InsightsProjectSettings;
use App\Module\Insights\Service\InsightsSettingsExporter;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InsightsSettingsExporterTest extends KernelTestCase
{
    use InsightsScenario;

    public function test_it_exports_the_analysis_settings_of_the_projects_the_user_owns(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->scenarioProject('settings-export');
        $settings = new InsightsProjectSettings($project);
        $settings->defaultModel = 'claude-opus-5-5';
        $settings->defaultEffort = 'high';
        $settings->collectFullText = true;
        $settings->subcommandPrograms = ['git', 'bazel'];
        $em->persist($settings);
        $em->persist(new InsightsProjectSettings($this->scenarioProject('settings-export-other')));
        $em->flush();

        $exporter = self::getContainer()->get(InsightsSettingsExporter::class);
        self::assertInstanceOf(InsightsSettingsExporter::class, $exporter);
        $rows = iterator_to_array($exporter->export($project->owner), false);

        self::assertSame('insights_project_settings.json', $exporter->filename());
        self::assertSame([[
            'projectId' => (string) $project->id,
            'project' => $project->name,
            'defaultModel' => 'claude-opus-5-5',
            'defaultEffort' => 'high',
            'collectFullText' => true,
            'subcommandPrograms' => ['git', 'bazel'],
        ]], $rows);
    }
}
