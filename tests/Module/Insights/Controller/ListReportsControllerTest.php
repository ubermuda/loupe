<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Module\Insights\Entity\Analysis;
use App\Module\Insights\Entity\AnalysisState;
use App\Module\Insights\Entity\ProposalKind;
use App\Module\Insights\Entity\ProposalState;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class ListReportsControllerTest extends WebTestCase
{
    use InsightsScenario;

    public function test_the_owner_sees_the_analyses_newest_first_with_their_proposals(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $project = $this->scenarioProject('reports-list');
        $older = $this->seedAnalysis($em, $project);
        $older->createdAt = new \DateTimeImmutable('2026-10-01 09:00:00');
        $older->fail('no-bridge', new \DateTimeImmutable('2026-10-01 10:00:00'));
        $done = $this->seedAnalysis($em, $project);
        $document = $this->seedDocument($em, $project);
        $done->complete($document->id ?? throw new \LogicException(), new \DateTimeImmutable('2026-10-07 10:00:00'));
        $em->flush();
        $proposed = $this->seedProposal($em, $done);
        $created = $this->seedProposal($em, $done, position: 1);
        $created->state = ProposalState::Created;
        $created->cardId = Uuid::v7();
        $dismissed = $this->seedProposal($em, $done, position: 2);
        $dismissed->state = ProposalState::Dismissed;
        $dismissed->dismissReason = 'We cache them already.';
        $em->flush();
        $this->fact($done, 1_500_000);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/reports');

        self::assertResponseIsSuccessful();
        self::assertSame('Reports', trim($crawler->filter('.lp-tabs__tab[aria-current="page"]')->text()));
        self::assertSame([(string) $done->id, (string) $older->id], $crawler->filter('[data-analysis]')->each(static fn ($row): string => (string) $row->attr('data-analysis')));

        $doneRow = $crawler->filter('[data-analysis="'.$done->id.'"]');
        self::assertSame('Cost', trim($doneRow->filter('[data-analysis-topic]')->text()));
        self::assertSame('90 days', trim($doneRow->filter('[data-analysis-range]')->text()));
        self::assertSame('Done', trim($doneRow->filter('[data-analysis-state]')->text()));
        self::assertSame('sonnet, medium', trim($doneRow->filter('[data-analysis-model]')->text()));
        self::assertSame('$1.50', trim($doneRow->filter('[data-analysis-cost]')->text()));
        self::assertSame('/projects/'.$projectId.'/documents/'.$document->id.'/review', $doneRow->filter('[data-analysis-report]')->attr('href'));

        self::assertSame([(string) $proposed->id, (string) $created->id, (string) $dismissed->id], $doneRow->filter('[data-proposal]')->each(static fn ($row): string => (string) $row->attr('data-proposal')));
        $proposedRow = $doneRow->filter('[data-proposal="'.$proposed->id.'"]');
        self::assertSame('Cache the dependencies', trim($proposedRow->filter('[data-proposal-title]')->text()));
        self::assertSame('Each run installs them again.', trim($proposedRow->filter('[data-proposal-body]')->text()));
        self::assertSame('About $4 a week', trim($proposedRow->filter('[data-proposal-saving]')->text()));
        self::assertSame('Proposed', trim($proposedRow->filter('[data-proposal-state]')->text()));
        self::assertCount(1, $proposedRow->filter('form[action$="/accept"]'));
        self::assertCount(1, $proposedRow->filter('form[action$="/dismiss"] input[name="dismiss_proposal_'.$proposed->id.'[reason]"]'));
        $createdRow = $doneRow->filter('[data-proposal="'.$created->id.'"]');
        self::assertSame('Card created', trim($createdRow->filter('[data-proposal-state]')->text()));
        self::assertSame('/projects/'.$projectId.'/board/cards/'.$created->cardId, $createdRow->filter('[data-proposal-card]')->attr('href'));
        self::assertCount(0, $createdRow->filter('form'));
        $dismissedRow = $doneRow->filter('[data-proposal="'.$dismissed->id.'"]');
        self::assertSame('Dismissed: We cache them already.', trim($dismissedRow->filter('[data-proposal-state]')->text()));

        $failedRow = $crawler->filter('[data-analysis="'.$older->id.'"]');
        self::assertSame('Failed: no-bridge', trim($failedRow->filter('[data-analysis-state]')->text()));
        self::assertSame('unknown', trim($failedRow->filter('[data-analysis-cost]')->text()));
        self::assertCount(0, $failedRow->filter('[data-analysis-report]'));
        self::assertCount(0, $crawler->filter('[data-reports-empty]'));
    }

    public function test_a_proposal_body_renders_as_sanitised_markdown(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $project = $this->scenarioProject('reports-proposal-markdown');
        $proposal = $this->seedProposal($em, $this->seedAnalysis($em, $project));
        $proposal->body = "**Cache** the `vendor` folder.\n\n<script>alert(1)</script><img src=x onerror=alert(1)>";
        $em->flush();
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/reports');

        self::assertResponseIsSuccessful();
        $body = $crawler->filter('[data-proposal="'.$proposal->id.'"] [data-proposal-body]');
        self::assertSame('Cache', $body->filter('strong')->text());
        self::assertSame('vendor', $body->filter('code')->text());
        self::assertCount(0, $body->filter('script'));
        self::assertCount(0, $body->filter('[onerror]'));
        self::assertStringNotContainsString('&lt;strong&gt;', (string) $client->getResponse()->getContent());
    }

    public function test_each_state_has_its_label(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $project = $this->scenarioProject('reports-states');
        $waiting = $this->seedAnalysis($em, $project);
        $running = $this->seedAnalysis($em, $project, AnalysisState::Running);
        $paused = $this->seedAnalysis($em, $project, AnalysisState::Running);
        $paused->pause('usage-limit', new \DateTimeImmutable('2026-10-07 10:00:00'));
        $em->flush();
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/reports');

        self::assertSame('Waiting for a bridge', trim($crawler->filter('[data-analysis="'.$waiting->id.'"] [data-analysis-state]')->text()));
        self::assertSame('Running', trim($crawler->filter('[data-analysis="'.$running->id.'"] [data-analysis-state]')->text()));
        self::assertSame('Paused: usage-limit', trim($crawler->filter('[data-analysis="'.$paused->id.'"] [data-analysis-state]')->text()));
    }

    public function test_a_bucket_rule_proposal_offers_dismiss_and_no_create(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $project = $this->scenarioProject('reports-bucket-rule');
        $analysis = $this->seedAnalysis($em, $project);
        $card = $this->seedProposal($em, $analysis);
        $rule = $this->seedProposal($em, $analysis, ProposalKind::BucketRule, 1);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/reports');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-proposal="'.$card->id.'"] form[action$="/accept"]'));
        $ruleRow = $crawler->filter('[data-proposal="'.$rule->id.'"]');
        self::assertCount(1, $ruleRow->filter('form[action$="/dismiss"]'));
        self::assertCount(0, $ruleRow->filter('form[action$="/accept"]'));
    }

    public function test_a_project_with_no_analysis_says_what_an_analysis_needs(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('reports-empty');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/reports');

        self::assertResponseIsSuccessful();
        $empty = $crawler->filter('[data-reports-empty]');
        self::assertSame('No analysis exists yet', trim($empty->filter('.lp-empty-state__title')->text()));
        self::assertStringContainsString('analysis', $empty->filter('.lp-empty-state__body')->text());
        self::assertStringEndsWith('/extending/cli-bridge/#work-requests', (string) $empty->filter('a')->attr('href'));
        self::assertCount(1, $crawler->filter('[data-start-analysis-form]'));
        self::assertSame('sonnet', $crawler->filter('[data-start-analysis-form] input[name="start_analysis_form[model]"]')->attr('placeholder'));
        self::assertSame(['cost'], $crawler->filter('select[name="start_analysis_form[topic]"] option')->each(static fn ($option): string => (string) $option->attr('value')));
        self::assertSame(['', 'low', 'medium', 'high', 'xhigh', 'max'], $crawler->filter('select[name="start_analysis_form[effort]"] option')->each(static fn ($option): string => (string) $option->attr('value')));
        self::assertCount(1, $crawler->filter('[data-analytics-settings-form] input[name="analytics_settings_form[collectFullText]"]'));
    }

    public function test_another_users_project_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $project = $this->scenarioProject('reports-theirs');
        $stranger = $this->user($em, 'reports-stranger-'.uniqid().'@example.com');
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/reports');

        self::assertResponseStatusCodeSame(403);
    }

    private function fact(Analysis $analysis, int $cost): void
    {
        $this->em()->getConnection()->insert('bridge_worker_run_facts', [
            'run_id' => (string) Uuid::v7(),
            'project_id' => (string) $analysis->project->id,
            'subject_type' => Analysis::SUBJECT_TYPE,
            'subject_id' => (string) $analysis->id,
            'kind' => 'worker',
            'work_kind' => 'analysis',
            'outcome' => 'succeeded',
            'started_at' => '2026-10-07 09:00:00',
            'ended_at' => '2026-10-07 09:05:00',
            'received_at' => '2026-10-07 09:05:00',
            'duration_ms' => 300_000,
            'cost_micro_usd' => $cost,
            'usage_source' => 'reported',
        ]);
    }
}
