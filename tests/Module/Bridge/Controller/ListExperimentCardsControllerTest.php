<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Bridge\Entity\ExperimentPin;
use App\Tests\Module\Bridge\ExperimentScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class ListExperimentCardsControllerTest extends WebTestCase
{
    use ExperimentScenario;

    public function test_a_variant_filter_keeps_the_kept_cards_of_that_variant(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiment-cards-variant@example.com');
        $project = $this->boardProject($em, $owner);
        $a = $this->experimentCard($em, $project, 1);
        $b = $this->experimentCard($em, $project, 2, column: 'in-progress');
        $switched = $this->experimentCard($em, $project, 3);
        $this->seedUsage($em, $this->experimentRun($em, $project, $a, 'a', at: '-3 hours'), costUsd: '1.500000');
        $this->experimentRun($em, $project, $b, 'b', at: '-2 hours');
        $this->experimentRun($em, $project, $b, 'b', at: '-1 hour');
        $this->experimentRun($em, $project, $switched, 'b', switchedFrom: 'a', at: '-30 minutes');
        $projectId = (string) $project->id;
        $base = '/projects/'.$projectId.'/worker-runs/experiments/model-test/cards';
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $base);

        self::assertResponseIsSuccessful();
        self::assertSame('Cards', trim($crawler->filter('.lp-experiment-tabs [aria-current="page"]')->text()));
        self::assertSame([(string) $switched->id, (string) $b->id, (string) $a->id], $crawler->filter('[data-experiment-card]')->each(static fn (Crawler $row): ?string => $row->attr('data-experiment-card')));
        self::assertSame(['All', 'a', 'b', 'Left out'], $crawler->filter('[data-experiment-filter] a')->each(static fn (Crawler $link): string => trim($link->text())));
        self::assertSame($base.'?variant=b', $crawler->filter('[data-experiment-filter] a')->eq(2)->attr('href'));

        $row = $crawler->filter('[data-experiment-card="'.$a->id.'"]');
        self::assertSame('/projects/'.$projectId.'/board/cards/'.$a->id, $row->filter('a')->attr('href'));
        self::assertStringContainsString('#1', $row->text());
        self::assertStringContainsString('Card 1', $row->text());
        self::assertSame('Done', trim($row->filter('[data-experiment-card-column]')->text()));
        self::assertSame('$1.50', trim($row->filter('[data-experiment-card-cost]')->text()));

        $crawler = $client->request(Request::METHOD_GET, $base.'?variant=b');

        self::assertResponseIsSuccessful();
        self::assertSame('b', trim($crawler->filter('[data-experiment-filter] [aria-current="true"]')->text()));
        $rows = $crawler->filter('[data-experiment-card]');
        self::assertCount(1, $rows);
        self::assertSame((string) $b->id, $rows->attr('data-experiment-card'));
        self::assertSame('In progress', trim($rows->filter('[data-experiment-card-column]')->text()));
        self::assertSame('2', trim($rows->filter('[data-experiment-card-runs]')->text()));
        // Its runs reported no usage, which differs from a recorded zero.
        self::assertSame('–', trim($rows->filter('[data-experiment-card-cost]')->text()));
        self::assertCount(0, $rows->filter('.lp-tag'));
    }

    public function test_the_left_out_filter_shows_a_tag_per_reason(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiment-cards-left-out@example.com');
        $project = $this->boardProject($em, $owner);
        $kept = $this->experimentCard($em, $project, 1);
        $leftOut = $this->experimentCard($em, $project, 2);
        $this->experimentRun($em, $project, $kept, 'a');
        $this->experimentRun($em, $project, $leftOut, 'a', at: '-3 hours');
        $this->experimentRun($em, $project, $leftOut, 'b', switchedFrom: 'a', at: '-2 hours');
        $pinned = $this->experimentCard($em, $project, 3);
        $em->persist(new ExperimentPin($project, $pinned->id ?? throw new \LogicException('The card has an id.'), 'model-test', 'a'));
        $em->flush();
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/experiments/model-test/cards?left-out=1');

        self::assertResponseIsSuccessful();
        self::assertSame('Left out', trim($crawler->filter('[data-experiment-filter] [aria-current="true"]')->text()));
        $rows = $crawler->filter('[data-experiment-card]');
        self::assertCount(2, $rows);
        $switched = $crawler->filter('[data-experiment-card="'.$leftOut->id.'"]');
        self::assertSame(['Switched variant', 'Mixed variants'], $switched->filter('.lp-tag')->each(static fn (Crawler $tag): string => trim($tag->text())));
        $pin = $crawler->filter('[data-experiment-card="'.$pinned->id.'"]');
        self::assertSame(['No experiment run'], $pin->filter('.lp-tag')->each(static fn (Crawler $tag): string => trim($tag->text())));
    }

    public function test_the_cards_come_twenty_to_a_page(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiment-cards-pages@example.com');
        $project = $this->boardProject($em, $owner);
        for ($i = 1; $i <= 21; ++$i) {
            $this->experimentRun($em, $project, $this->experimentCard($em, $project, $i), 'a', at: \sprintf('-%d minutes', 100 - $i));
        }
        $projectId = (string) $project->id;
        $base = '/projects/'.$projectId.'/worker-runs/experiments/model-test/cards';
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $base.'?variant=a');

        self::assertCount(20, $crawler->filter('[data-experiment-card]'));
        self::assertSame($base.'?variant=a&page=2', $crawler->filter('.lp-pagination a[aria-label="Next page"]')->attr('href'));

        $crawler = $client->request(Request::METHOD_GET, $base.'?variant=a&page=2');

        self::assertCount(1, $crawler->filter('[data-experiment-card]'));
        self::assertStringContainsString('Card 1', $crawler->filter('[data-experiment-card]')->text());

        $client->request(Request::METHOD_GET, $base.'?variant=a&page=9');

        self::assertResponseRedirects($base.'?variant=a&page=2');
    }

    public function test_an_unknown_experiment_is_not_found(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiment-cards-unknown@example.com');
        $project = $this->boardProject($em, $owner);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/experiments/model-test/cards');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_another_users_project_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiment-cards-theirs@example.com');
        $stranger = $this->user($em, 'experiment-cards-stranger@example.com');
        $project = $this->boardProject($em, $owner);
        $this->experimentRun($em, $project, $this->experimentCard($em, $project, 1), 'a');
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/experiments/model-test/cards');

        self::assertResponseStatusCodeSame(403);
    }
}
