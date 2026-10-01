<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

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
            $card = $this->experimentCard($em, $project, $i + 5, merged: true);
            $this->seedUsage($em, $this->experimentRun($em, $project, $card, 'b'), costUsd: '1.000000');
        }
        $switched = $this->experimentCard($em, $project, 11);
        $this->experimentRun($em, $project, $switched, 'b', switchedFrom: 'a');
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/experiments/model-test');

        self::assertResponseIsSuccessful();
        self::assertSame('Experiments', trim($crawler->filter('.lp-activity-tabs [aria-current="page"]')->text()));
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

        $leftOut = $crawler->filter('[data-experiment-left-out]');
        self::assertStringContainsString('1 card is left out', $leftOut->text());
        self::assertSame('/projects/'.$projectId.'/worker-runs/experiments/model-test/cards?left-out=1', $leftOut->filter('a')->attr('href'));
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
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/experiments/another-test');

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
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/experiments/model-test');

        self::assertResponseStatusCodeSame(403);
    }

    /** @return list<string> */
    private function cells(Crawler $row): array
    {
        self::assertCount(1, $row);

        return $row->filter('th, td')->each(static fn (Crawler $cell): string => trim($cell->text()));
    }
}
