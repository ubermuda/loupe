<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShowWorkerRunCostControllerTest extends WebTestCase
{
    use BoardColumnFixtures;
    use BridgeScenario;

    public function test_the_owner_sees_a_bar_and_the_figures_for_each_finished_card_with_usage(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'cost-owner@example.com');
        $project = $this->boardProject($em, $owner);
        $first = $this->finishedCard($em, $project, 7, 'Ship the <b>chart</b>', '-3 days');
        $second = $this->finishedCard($em, $project, 8, 'Second card', '-2 days');
        $this->finishedCard($em, $project, 9, 'No usage', '-1 day');
        $open = $this->finishedCard($em, $project, 10, 'Still open', null);
        $this->seedUsage($em, $this->seedRun($em, $project, ruleName: 'plan', cardId: $first->id), costUsd: '1.250000');
        $this->seedRun($em, $project, ruleName: 'plan', cardId: $first->id);
        $this->seedUsage($em, $this->seedRun($em, $project, ruleName: 'build', cardId: $second->id), source: WorkerRunUsageSource::Estimated, costUsd: '0.750000');
        $this->seedUsage($em, $this->seedRun($em, $project, ruleName: 'build', cardId: $open->id), costUsd: '5.000000');
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/cost');

        self::assertResponseIsSuccessful();
        self::assertSame('Cost', trim($crawler->filter('.lp-tabs__tab[aria-current="page"]')->text()));
        self::assertSame('$1.00', $crawler->filter('[data-cost-median]')->text());
        self::assertSame('$2.00', $crawler->filter('[data-cost-total]')->text());
        self::assertSame('2', $crawler->filter('[data-cost-cards]')->text());
        self::assertCount(2, $crawler->filter('[data-cost-bar]'));
        self::assertSame('/projects/'.$projectId.'/board/cards/'.$first->id, $crawler->filter('[data-cost-bar="'.$first->id.'"]')->attr('href'));
        self::assertCount(1, $crawler->filter('[data-cost-bar="'.$first->id.'"] [data-cost-partial]'));
        self::assertCount(1, $crawler->filter('[data-cost-bar="'.$second->id.'"] [data-cost-estimated]'));
        self::assertStringContainsString('1 run has no usage', $crawler->filter('#cost-card-0')->text());
        self::assertStringContainsString('Ship the &lt;b&gt;chart&lt;/b&gt;', (string) $client->getResponse()->getContent());
        self::assertCount(2, $crawler->filter('[data-cost-table] [data-cost-row]'));
        self::assertStringContainsString('you do not pay this amount', $crawler->filter('[data-cost-basis]')->text());
    }

    public function test_a_rule_filter_and_a_split_narrow_the_bars(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'cost-filter@example.com');
        $project = $this->boardProject($em, $owner);
        $card = $this->finishedCard($em, $project, 1, 'Split card', '-3 days');
        $this->seedUsage($em, $this->seedRun($em, $project, ruleName: 'plan', cardId: $card->id), costUsd: '1.000000');
        $this->seedUsage($em, $this->seedRun($em, $project, ruleName: 'build', cardId: $card->id), costUsd: '3.000000');
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/cost?split=rule');
        self::assertCount(2, $crawler->filter('[data-cost-bar] .lp-cost-chart__segment'));
        self::assertSame(['build', 'plan'], $crawler->filter('[data-cost-legend] li')->each(static fn ($item): string => trim($item->text())));

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/cost?split=rule&rule=plan');
        self::assertSame('$1.00', $crawler->filter('[data-cost-total]')->text());
        self::assertCount(1, $crawler->filter('[data-cost-bar] .lp-cost-chart__segment'));
    }

    public function test_a_range_with_no_finished_card_with_usage_shows_the_empty_state(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'cost-empty@example.com');
        $project = $this->boardProject($em, $owner);
        $old = $this->finishedCard($em, $project, 1, 'Long ago', '-60 days');
        $this->seedUsage($em, $this->seedRun($em, $project, cardId: $old->id));
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/cost?range=thirty-days');

        self::assertResponseIsSuccessful();
        self::assertSame('No finished card with usage in this range.', trim($crawler->filter('[data-cost-empty]')->text()));
        self::assertCount(0, $crawler->filter('[data-cost-bar]'));
        self::assertSame('0', $crawler->filter('[data-cost-cards]')->text());
    }

    public function test_another_users_project_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'cost-theirs@example.com');
        $stranger = $this->user($em, 'cost-stranger@example.com');
        $project = $this->boardProject($em, $owner);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/cost');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_the_runs_tab_links_to_the_cost_tab(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'cost-tabs@example.com');
        $project = $this->boardProject($em, $owner);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs');

        self::assertResponseIsSuccessful();
        self::assertSame('Runs', trim($crawler->filter('.lp-tabs__tab[aria-current="page"]')->text()));
        self::assertSame('/projects/'.$projectId.'/worker-runs/cost', $crawler->filter('.lp-tabs__tab')->eq(1)->attr('href'));
    }

    private function boardProject(EntityManagerInterface $em, User $owner): Project
    {
        $project = $this->project($em, $owner, 'Cost board');
        $this->seedColumns($project);
        $em->flush();

        return $project;
    }

    private function finishedCard(EntityManagerInterface $em, Project $project, int $number, string $title, ?string $completedAgo): Card
    {
        $card = new Card(project: $project, column: $this->column($project, null === $completedAgo ? 'backlog' : 'done'), title: $title, body: '', number: $number);
        $card->completedAt = null === $completedAgo ? null : new \DateTimeImmutable($completedAgo);
        $em->persist($card);
        $em->flush();

        return $card;
    }
}
