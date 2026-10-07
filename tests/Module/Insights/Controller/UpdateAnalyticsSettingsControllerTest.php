<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Module\Insights\Entity\InsightsProjectSettings;
use App\Module\Insights\Repository\InsightsProjectSettingsRepository;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class UpdateAnalyticsSettingsControllerTest extends WebTestCase
{
    use InsightsScenario;

    public function test_the_form_saves_the_settings_and_redirects_back_with_a_flash(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('settings-form');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/reports');
        $client->submitForm('Save the settings', [
            'analytics_settings_form[model]' => 'opus',
            'analytics_settings_form[effort]' => 'low',
            'analytics_settings_form[collectFullText]' => true,
            'analytics_settings_form[subcommandPrograms]' => ' git, ,bazel ',
        ]);

        self::assertResponseRedirects('/projects/'.$projectId.'/analytics/reports');
        $crawler = $client->followRedirect();
        self::assertStringContainsString('The analysis settings are saved.', $crawler->filter('body')->text());
        self::assertSame('opus', $crawler->filter('input[name="analytics_settings_form[model]"]')->attr('value'));
        $settings = $this->settings($projectId);
        self::assertSame(['opus', 'low', true], [$settings?->defaultModel, $settings?->defaultEffort, $settings?->collectFullText]);
        self::assertSame(['git', 'bazel'], $settings?->subcommandPrograms);
        self::assertSame('git, bazel', $crawler->filter('input[name="analytics_settings_form[subcommandPrograms]"]')->attr('value'));
    }

    public function test_empty_values_clear_the_project_settings(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $project = $this->scenarioProject('settings-form-clear');
        $settings = new InsightsProjectSettings($project);
        $settings->defaultModel = 'opus';
        $settings->defaultEffort = 'high';
        $settings->collectFullText = true;
        $settings->subcommandPrograms = ['git'];
        $em->persist($settings);
        $em->flush();
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/analytics/reports/settings', [
            'analytics_settings_form' => ['model' => '', 'effort' => '', 'subcommandPrograms' => ' , ', '_token' => 'csrf-token'],
        ], [], ['HTTP_REFERER' => 'http://localhost/']);

        self::assertResponseRedirects('/projects/'.$projectId.'/analytics/reports');
        $stored = $this->settings($projectId);
        self::assertSame([null, null, false, null], [$stored?->defaultModel, $stored?->defaultEffort, $stored?->collectFullText, $stored?->subcommandPrograms]);
    }

    public function test_a_malformed_model_is_shown_on_its_field(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('settings-form-refused');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/analytics/reports/settings', [
            'analytics_settings_form' => ['model' => 'two words', 'effort' => '', '_token' => 'csrf-token'],
        ], [], ['HTTP_REFERER' => 'http://localhost/']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('A model is one word', $crawler->filter('[data-analytics-settings-form] [data-field-errors="model"]')->text());
        self::assertNull($this->settings($projectId));
    }

    public function test_a_malformed_program_is_shown_on_its_field(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('settings-form-programs-refused');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/analytics/reports/settings', [
            'analytics_settings_form' => ['model' => '', 'effort' => '', 'subcommandPrograms' => 'git, two words', '_token' => 'csrf-token'],
        ], [], ['HTTP_REFERER' => 'http://localhost/']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('A program name is 1 to 40 characters', $crawler->filter('[data-analytics-settings-form] [data-field-errors="subcommandPrograms"]')->text());
        self::assertNull($this->settings($projectId));
    }

    public function test_another_users_project_is_refused(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('settings-form-theirs');
        $stranger = $this->user($this->em(), 'settings-form-stranger-'.uniqid().'@example.com');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/analytics/reports/settings', [
            'analytics_settings_form' => ['model' => 'opus', '_token' => 'csrf-token'],
        ], [], ['HTTP_REFERER' => 'http://localhost/']);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->settings($projectId));
    }

    private function settings(string $projectId): ?InsightsProjectSettings
    {
        $this->em()->clear();

        $repository = static::getContainer()->get(InsightsProjectSettingsRepository::class);
        self::assertInstanceOf(InsightsProjectSettingsRepository::class, $repository);

        return $repository->findOneBy(['project' => $projectId]);
    }
}
