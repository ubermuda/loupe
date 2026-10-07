<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Module\Board\Entity\CardEventKind;
use App\Module\Bridge\Entity\ExperimentDefinition;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Tests\Module\Bridge\ExperimentScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class ShowExperimentControllerTest extends WebTestCase
{
    use ExperimentScenario;

    public function test_the_owner_compares_the_variants_on_each_metric(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiment-owner@example.com');
        $project = $this->boardProject($em, $owner);
        $em->persist(new ExperimentDefinition($project, 'model-test', [['name' => 'a', 'weight' => 3], ['name' => 'b', 'weight' => 1]]));
        // Every run of a fails and every run of b succeeds, so the stop rate gives a clear answer.
        for ($i = 1; $i <= 5; ++$i) {
            $card = $this->experimentCard($em, $project, $i, merged: true);
            $this->seedUsage($em, $this->experimentRun($em, $project, $card, 'a', state: WorkerRunState::Failed), costUsd: '2.000000');
            if (1 === $i) {
                $this->cardEvent($em, $card, CardEventKind::FixRequested, ['reason' => 'conflict', 'pullRequest' => 1], '-90 minutes');
            }
            if (2 === $i) {
                $this->cardEvent($em, $card, CardEventKind::FixRequested, ['reason' => 'rebase-needed', 'pullRequest' => 2], '-90 minutes');
            }
            $card = $this->experimentCard($em, $project, $i + 5, merged: true);
            $this->seedUsage($em, $this->experimentRun($em, $project, $card, 'b'), costUsd: '1.000000');
        }
        $switched = $this->experimentCard($em, $project, 11);
        $this->experimentRun($em, $project, $switched, 'b', switchedFrom: 'a');
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/experiments/model-test');

        self::assertResponseIsSuccessful();
        self::assertSame('Experiments', trim($crawler->filter('.lp-analytics-tabs [aria-current="page"]')->text()));
        self::assertSame('Comparison', trim($crawler->filter('.lp-experiment-tabs [aria-current="page"]')->text()));
        self::assertSame(['a', 'model-a', '3', '5', '5', '5', '$10.00'], $this->cells($crawler->filter('[data-experiment-variant="a"]')));
        self::assertSame(['b', 'model-b', '1', '5', '5', '5', '$5.00'], $this->cells($crawler->filter('[data-experiment-variant="b"]')));

        $headline = $crawler->filter('[data-experiment-headline]')->text();
        self::assertStringContainsString('b costs 50% less per merged card than a.', $headline);
        self::assertStringContainsString('The merge rate and the fix rounds do not give a clear answer yet.', $headline);

        self::assertSame(['a', 'Likely range', 'b', 'Likely range'], \array_slice($this->cells($crawler->filter('[data-experiment-metrics] thead tr')), 1));
        self::assertSame(['Cost per merged card', '$2.00', '$2.00–$2.00', '$1.00', '$1.00–$1.00'], $this->cells($crawler->filter('[data-metric="cost"]')));
        self::assertSame('100%', $this->cells($crawler->filter('[data-metric="merge-rate"]'))[1]);
        self::assertSame('100%', $this->cells($crawler->filter('[data-metric="stop-rate"]'))[1]);
        self::assertSame('0%', $this->cells($crawler->filter('[data-metric="stop-rate"]'))[3]);
        self::assertCount(0, $crawler->filter('[data-metric="stop-rate"] [data-metric-unclear]'));
        self::assertSame('Too few cards', trim($crawler->filter('[data-metric="hours-to-merge"] [data-metric-unclear]')->text()));
        // No card has a pull request, so no variant has an hour to show.
        self::assertSame(['–', '–', '–', '–'], \array_slice($this->cells($crawler->filter('[data-metric="hours-to-merge"]')), 1));
        self::assertStringStartsWith('Conflict', $this->cells($crawler->filter('[data-metric-part="conflict"]'))[0]);
        self::assertSame('0.2', $this->cells($crawler->filter('[data-metric-part="conflict"]'))[1]);
        self::assertStringStartsWith('Other reason: rebase-needed', $this->cells($crawler->filter('[data-metric-part="rebase-needed"]'))[0]);

        $leftOut = $crawler->filter('[data-experiment-left-out]');
        self::assertStringContainsString('1 card is left out', $leftOut->text());
        self::assertSame('/projects/'.$projectId.'/analytics/experiments/model-test/cards?left-out=1', $leftOut->filter('a')->attr('href'));

        $analyse = $crawler->filter('[data-experiment-analyse]');
        self::assertSame('Analyse this experiment', trim($analyse->text()));
        self::assertSame('/projects/'.$projectId.'/analytics/reports?topic=experiment&experiment=model-test', $analyse->attr('href'));
    }

    public function test_the_table_shows_the_declared_metrics_and_names_the_unknown_ones(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiment-declared@example.com');
        $project = $this->boardProject($em, $owner);
        $definition = new ExperimentDefinition($project, 'model-test', [['name' => 'a', 'weight' => 1]]);
        $definition->metrics = ['duration', 'foo', 'input-tokens', 'bar'];
        $em->persist($definition);
        $this->seedUsage($em, $this->experimentRun($em, $project, $this->experimentCard($em, $project, 1, merged: true), 'a'), costUsd: '1.000000', inputTokens: 1234);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/experiments/model-test');

        self::assertResponseIsSuccessful();
        self::assertSame(['duration', 'input-tokens'], $crawler->filter('[data-metric]')->each(static fn (Crawler $row): ?string => $row->attr('data-metric')));
        $duration = $this->cells($crawler->filter('[data-metric="duration"]'));
        self::assertStringStartsWith('Run time per merged card (minutes)', $duration[0]);
        self::assertSame(['5.0', '5.0–5.0'], \array_slice($duration, 1));
        $inputTokens = $this->cells($crawler->filter('[data-metric="input-tokens"]'));
        self::assertStringStartsWith('Input tokens per merged card', $inputTokens[0]);
        self::assertSame(['1,234', '1,234–1,234'], \array_slice($inputTokens, 1));
        self::assertSame('The bridge rule declares metrics that this server does not know: foo, bar.', trim($crawler->filter('[data-experiment-unknown-metrics]')->text()));
    }

    public function test_a_variant_with_no_usage_shows_no_cost(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiment-no-usage@example.com');
        $project = $this->boardProject($em, $owner);
        $this->seedUsage($em, $this->experimentRun($em, $project, $this->experimentCard($em, $project, 1), 'a'), costUsd: '1.000000');
        $this->experimentRun($em, $project, $this->experimentCard($em, $project, 2), 'b');
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/experiments/model-test');

        self::assertResponseIsSuccessful();
        self::assertSame('$1.00', $this->cells($crawler->filter('[data-experiment-variant="a"]'))[6]);
        self::assertSame('–', $this->cells($crawler->filter('[data-experiment-variant="b"]'))[6]);
    }

    public function test_an_unknown_experiment_is_not_found(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiment-unknown@example.com');
        $project = $this->boardProject($em, $owner);
        $this->experimentRun($em, $project, $this->experimentCard($em, $project, 1), 'a');
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/experiments/another-test');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_another_users_project_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiment-theirs@example.com');
        $stranger = $this->user($em, 'experiment-stranger@example.com');
        $project = $this->boardProject($em, $owner);
        $this->experimentRun($em, $project, $this->experimentCard($em, $project, 1), 'a');
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/experiments/model-test');

        self::assertResponseStatusCodeSame(403);
    }

    /** @return list<string> */
    private function cells(Crawler $row): array
    {
        self::assertCount(1, $row);

        return $row->filter('th, td')->each(static fn (Crawler $cell): string => trim($cell->text()));
    }
}
