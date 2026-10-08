<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Module\Insights\Entity\Analysis;
use App\Module\Insights\Entity\AnalysisState;
use App\Module\Insights\Entity\AnalysisTopic;
use App\Module\Insights\Repository\AnalysisRepository;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class StartAnalysisControllerTest extends WebTestCase
{
    use InsightsScenario;

    public function test_the_form_starts_an_analysis_and_redirects_back_with_a_flash(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('start-analysis');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/reports');
        $client->submitForm('Analyse', [
            'start_analysis_form[topic]' => 'cost',
            'start_analysis_form[range]' => 'ninety-days',
            'start_analysis_form[model]' => 'opus',
            'start_analysis_form[effort]' => 'high',
        ]);

        self::assertResponseRedirects('/projects/'.$projectId.'/analytics/reports');
        $crawler = $client->followRedirect();
        self::assertStringContainsString('The analysis waits for a bridge.', $crawler->filter('body')->text());
        $analyses = $this->analyses($projectId);
        self::assertCount(1, $analyses);
        self::assertSame('opus', $analyses[0]->model);
        self::assertSame('high', $analyses[0]->effort);
        self::assertSame('ninety-days', $analyses[0]->scope->range->value);
        self::assertSame(AnalysisState::Waiting, $analyses[0]->state);
        self::assertNotNull($analyses[0]->workRequestId);
    }

    public function test_the_time_topic_starts_an_analysis(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('start-analysis-time');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/reports');
        $client->submitForm('Analyse', [
            'start_analysis_form[topic]' => 'time',
            'start_analysis_form[range]' => 'thirty-days',
        ]);

        self::assertResponseRedirects('/projects/'.$projectId.'/analytics/reports');
        $analyses = $this->analyses($projectId);
        self::assertCount(1, $analyses);
        self::assertSame('time', $analyses[0]->topic->value);
        self::assertNotNull($analyses[0]->workRequestId);
    }

    public function test_the_form_starts_a_host_analysis(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('start-analysis-host');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/reports');
        $client->submitForm('Analyse', [
            'start_analysis_form[topic]' => 'host',
            'start_analysis_form[range]' => 'thirty-days',
        ]);

        self::assertResponseRedirects('/projects/'.$projectId.'/analytics/reports');
        $analyses = $this->analyses($projectId);
        self::assertCount(1, $analyses);
        self::assertSame(AnalysisTopic::Host, $analyses[0]->topic);
        self::assertSame(AnalysisState::Waiting, $analyses[0]->state);
    }

    public function test_an_empty_model_and_effort_take_the_project_defaults(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('start-analysis-defaults');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/analytics/reports/analyses', [
            'start_analysis_form' => ['topic' => 'cost', 'range' => 'thirty-days', 'model' => '', 'effort' => '', '_token' => 'csrf-token'],
        ], [], ['HTTP_REFERER' => 'http://localhost/']);

        self::assertResponseRedirects('/projects/'.$projectId.'/analytics/reports');
        $analyses = $this->analyses($projectId);
        self::assertSame(['sonnet', 'medium'], [$analyses[0]->model, $analyses[0]->effort]);
    }

    public function test_a_malformed_model_is_shown_on_its_field(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('start-analysis-refused');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/analytics/reports/analyses', [
            'start_analysis_form' => ['topic' => 'cost', 'range' => 'thirty-days', 'model' => 'two words', 'effort' => '', '_token' => 'csrf-token'],
        ], [], ['HTTP_REFERER' => 'http://localhost/']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('A model is one word', $crawler->filter('[data-start-analysis-form] [data-field-errors="model"]')->text());
        self::assertSame([], $this->analyses($projectId));
    }

    public function test_the_form_starts_an_experiment_analysis(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('start-analysis-experiment');
        $this->seedExperiment($project, 'model-test');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/reports');
        $client->submitForm('Analyse', [
            'start_analysis_form[topic]' => 'experiment',
            'start_analysis_form[experiment]' => 'model-test',
            'start_analysis_form[range]' => 'all',
        ]);

        self::assertResponseRedirects('/projects/'.$projectId.'/analytics/reports');
        $analyses = $this->analyses($projectId);
        self::assertCount(1, $analyses);
        self::assertSame('experiment', $analyses[0]->topic->value);
        self::assertSame('model-test', $analyses[0]->scope->experiment);
    }

    public function test_an_experiment_topic_with_no_experiment_is_shown_on_its_field(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('start-analysis-no-experiment');
        $this->seedExperiment($project, 'model-test');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/analytics/reports/analyses', [
            'start_analysis_form' => ['topic' => 'experiment', 'experiment' => '', 'range' => 'all', '_token' => 'csrf-token'],
        ], [], ['HTTP_REFERER' => 'http://localhost/']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('An experiment analysis needs an experiment.', $crawler->filter('[data-start-analysis-form] [data-field-errors="experiment"]')->text());
        self::assertSame([], $this->analyses($projectId));
    }

    public function test_an_unknown_experiment_is_refused_on_its_field(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('start-analysis-unknown-experiment');
        $this->seedExperiment($project, 'model-test');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/analytics/reports/analyses', [
            'start_analysis_form' => ['topic' => 'experiment', 'experiment' => 'no-such-experiment', 'range' => 'all', '_token' => 'csrf-token'],
        ], [], ['HTTP_REFERER' => 'http://localhost/']);

        self::assertResponseStatusCodeSame(422);
        self::assertNotSame('', trim($crawler->filter('[data-start-analysis-form] [data-field-errors="experiment"]')->text()));
        self::assertSame([], $this->analyses($projectId));
    }

    public function test_another_users_project_is_refused(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('start-analysis-theirs');
        $stranger = $this->user($this->em(), 'start-analysis-stranger-'.uniqid().'@example.com');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/analytics/reports/analyses', [
            'start_analysis_form' => ['topic' => 'cost', 'range' => 'thirty-days', '_token' => 'csrf-token'],
        ], [], ['HTTP_REFERER' => 'http://localhost/']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->analyses($projectId));
    }

    /** @return list<Analysis> */
    private function analyses(string $projectId): array
    {
        $this->em()->clear();
        $repository = static::getContainer()->get(AnalysisRepository::class);
        self::assertInstanceOf(AnalysisRepository::class, $repository);

        return array_values($repository->findBy(['project' => $projectId]));
    }
}
