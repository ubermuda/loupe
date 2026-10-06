<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Tests\Module\Bridge\ExperimentScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ListExperimentsControllerTest extends WebTestCase
{
    use ExperimentScenario;

    public function test_the_owner_sees_each_experiment_with_its_cards_and_last_run(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiments-owner@example.com');
        $project = $this->boardProject($em, $owner);
        $first = $this->experimentCard($em, $project, 1);
        $second = $this->experimentCard($em, $project, 2);
        $this->experimentRun($em, $project, $first, 'a', at: '-3 hours');
        $this->experimentRun($em, $project, $second, 'b', at: '-2 hours');
        $this->experimentRun($em, $project, $first, 'a', experiment: 'prompt-test', at: '-1 hour');
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/experiments');

        self::assertResponseIsSuccessful();
        self::assertSame('Experiments', trim($crawler->filter('.lp-tabs__tab[aria-current="page"]')->text()));
        self::assertSame(['prompt-test', 'model-test'], $crawler->filter('[data-experiment] [data-experiment-name]')->each(static fn ($name): string => trim($name->text())));
        self::assertSame('/projects/'.$projectId.'/analytics/experiments/model-test', $crawler->filter('[data-experiment="model-test"] a')->attr('href'));
        self::assertSame('2 cards', trim($crawler->filter('[data-experiment="model-test"] [data-experiment-cards]')->text()));
        self::assertSame('1 card', trim($crawler->filter('[data-experiment="prompt-test"] [data-experiment-cards]')->text()));
        self::assertCount(2, $crawler->filter('[data-experiment] time'));
        self::assertCount(0, $crawler->filter('[data-experiments-empty]'));
    }

    public function test_a_project_with_no_experiment_says_how_to_start_one(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiments-empty@example.com');
        $project = $this->boardProject($em, $owner);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/experiments');

        self::assertResponseIsSuccessful();
        $empty = $crawler->filter('[data-experiments-empty]');
        self::assertSame('No experiments yet', trim($empty->filter('.lp-empty-state__title')->text()));
        self::assertStringContainsString('experiment', $empty->text());
        self::assertStringEndsWith('/extending/cli-bridge/#experiments', (string) $empty->filter('a')->attr('href'));
        self::assertCount(0, $crawler->filter('[data-experiment]'));
    }

    public function test_another_users_project_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiments-theirs@example.com');
        $stranger = $this->user($em, 'experiments-stranger@example.com');
        $project = $this->boardProject($em, $owner);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/experiments');

        self::assertResponseStatusCodeSame(403);
    }
}
