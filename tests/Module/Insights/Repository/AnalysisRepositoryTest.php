<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Repository;

use App\Module\Bridge\Service\WorkerRunFactWriter;
use App\Module\Insights\Entity\Analysis;
use App\Module\Insights\Repository\AnalysisRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class AnalysisRepositoryTest extends KernelTestCase
{
    use InsightsScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_the_cost_sums_the_fact_rows_of_the_analysis_alone(): void
    {
        $em = $this->em();
        $project = $this->scenarioProject('analysis-cost');
        $analysis = $this->seedAnalysis($em, $project);
        $this->seedCostedRun($project, $analysis->id, '0.250000');
        $this->seedCostedRun($project, $analysis->id, '0.125000');
        $this->seedCostedRun($project, Uuid::v7(), '9.000000');
        $this->seedCostedRun($project, $analysis->id, '5.000000', 'card');

        self::assertSame(375000, $this->repository()->costOf($analysis));
    }

    public function test_a_later_fact_write_shows_in_the_next_read(): void
    {
        $em = $this->em();
        $project = $this->scenarioProject('analysis-cost-later');
        $analysis = $this->seedAnalysis($em, $project);
        $this->seedCostedRun($project, $analysis->id, '0.100000');
        self::assertSame(100000, $this->repository()->costOf($analysis));

        $this->seedCostedRun($project, $analysis->id, '0.200000');

        self::assertSame(300000, $this->repository()->costOf($analysis));
    }

    public function test_the_costs_of_several_analyses_come_from_one_read_keyed_by_analysis(): void
    {
        $em = $this->em();
        $project = $this->scenarioProject('analysis-costs');
        $first = $this->seedAnalysis($em, $project);
        $second = $this->seedAnalysis($em, $project);
        $unpriced = $this->seedAnalysis($em, $project);
        $this->seedCostedRun($project, $first->id, '0.250000');
        $this->seedCostedRun($project, $first->id, '0.125000');
        $this->seedCostedRun($project, $second->id, '1.000000');
        $this->seedCostedRun($project, $unpriced->id, '7.000000', 'card');

        self::assertEquals(
            [(string) $first->id => 375000, (string) $second->id => 1000000],
            $this->repository()->costsOf($project, [$first, $second, $unpriced]),
        );
        self::assertSame([], $this->repository()->costsOf($project, []));
    }

    public function test_the_project_list_takes_a_limit_and_reads_newest_first(): void
    {
        $em = $this->em();
        $project = $this->scenarioProject('analysis-limit');
        $this->seedAnalysis($em, $project);
        $this->seedAnalysis($em, $project);
        $newest = $this->seedAnalysis($em, $project);

        $limited = $this->repository()->findByProject($project, 2);

        self::assertCount(2, $limited);
        self::assertSame($newest, $limited[0]);
        self::assertCount(3, $this->repository()->findByProject($project));
    }

    public function test_an_analysis_with_no_runs_has_no_cost(): void
    {
        $analysis = $this->seedAnalysis($this->em(), $this->scenarioProject('analysis-cost-none'));

        self::assertNull($this->repository()->costOf($analysis));
    }

    public function test_an_analysis_of_another_project_is_not_found(): void
    {
        $em = $this->em();
        $project = $this->scenarioProject('analysis-find');
        $analysis = $this->seedAnalysis($em, $project);

        self::assertSame($analysis, $this->repository()->findOneByIdAndProject((string) $analysis->id, $project));
        self::assertNull($this->repository()->findOneByIdAndProject((string) $analysis->id, $this->scenarioProject('analysis-find-other')));
        self::assertNull($this->repository()->findOneByIdAndProject('nope', $project));
    }

    private function seedCostedRun(Project $project, ?Uuid $subjectId, string $costUsd, string $subjectType = Analysis::SUBJECT_TYPE): void
    {
        $em = $this->em();
        $run = $this->seedRun($em, $project, cardId: $subjectId, subjectType: $subjectType);
        $this->seedUsage($em, $run, costUsd: $costUsd);
        $writer = self::getContainer()->get(WorkerRunFactWriter::class);
        self::assertInstanceOf(WorkerRunFactWriter::class, $writer);
        $writer->upsert([$run->id ?? throw new \LogicException('A stored run has an id.')]);
    }

    private function repository(): AnalysisRepository
    {
        $repository = self::getContainer()->get(AnalysisRepository::class);
        self::assertInstanceOf(AnalysisRepository::class, $repository);

        return $repository;
    }
}
