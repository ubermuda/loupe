<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service\Dev;

use App\Module\Bridge\Repository\WorkerRunBucketTimeRepository;
use App\Module\Bridge\Service\BucketTimeComputer;
use App\Module\Insights\Entity\AnalysisState;
use App\Module\Insights\Entity\AnalysisTopic;
use App\Module\Insights\Entity\ProposalKind;
use App\Module\Insights\Entity\ProposalState;
use App\Module\Insights\Repository\AnalysisRepository;
use App\Module\Insights\Repository\InsightsBucketRuleRepository;
use App\Module\Insights\Repository\ProposalRepository;
use App\Module\Insights\Service\Dev\DevAnalysesSeeder;
use App\Module\Review\Service\DocumentSearchIndexer;
use App\Module\Review\Service\MarkdownRenderer;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DevAnalysesSeederTest extends KernelTestCase
{
    use InsightsScenario;

    private const string EXPERIMENT = 'model-test';

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_it_seeds_the_cost_time_and_experiment_analyses_with_rules_and_bucket_times(): void
    {
        $project = $this->scenarioProject('dev-seed');

        self::assertTrue($this->seeder()->seed($project, self::EXPERIMENT));

        $this->em()->clear();
        $analyses = $this->service(AnalysisRepository::class)->findByProject($project);
        self::assertCount(4, $analyses);
        $time = array_values(array_filter($analyses, static fn ($analysis): bool => AnalysisTopic::Time === $analysis->topic));
        self::assertCount(1, $time);
        self::assertSame(AnalysisState::Done, $time[0]->state);
        self::assertNotNull($time[0]->documentId);
        self::assertCount(1, array_filter($analyses, static fn ($analysis): bool => AnalysisTopic::Experiment === $analysis->topic && self::EXPERIMENT === $analysis->scope->experiment));

        $proposals = $this->service(ProposalRepository::class)->findByAnalysis($time[0]);
        self::assertCount(1, $proposals);
        self::assertSame(ProposalKind::BucketRule, $proposals[0]->kind);
        self::assertSame(ProposalState::Proposed, $proposals[0]->state);
        self::assertSame(['pattern' => 'make *', 'bucket' => 'build'], $proposals[0]->payload);

        $rules = $this->service(InsightsBucketRuleRepository::class)->findOrdered($project);
        self::assertSame(['git *', 'grep', 'Agent'], array_map(static fn ($rule): string => $rule->pattern, $rules));

        $names = $this->service(WorkerRunBucketTimeRepository::class)->findBucketNamesOfProject($project);
        sort($names);
        self::assertSame(['git', 'other', 'search', 'subagent'], $names);
    }

    public function test_a_second_run_adds_nothing(): void
    {
        $project = $this->scenarioProject('dev-seed-twice');
        $this->seeder()->seed($project, self::EXPERIMENT);

        self::assertFalse($this->seeder()->seed($project, self::EXPERIMENT));

        $this->em()->clear();
        self::assertCount(4, $this->service(AnalysisRepository::class)->findByProject($project));
        self::assertCount(3, $this->service(InsightsBucketRuleRepository::class)->findOrdered($project));
    }

    private function seeder(): DevAnalysesSeeder
    {
        return new DevAnalysesSeeder(
            $this->em(),
            $this->service(AnalysisRepository::class),
            $this->service(InsightsBucketRuleRepository::class),
            $this->service(BucketTimeComputer::class),
            $this->service(MarkdownRenderer::class),
            $this->service(DocumentSearchIndexer::class),
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
