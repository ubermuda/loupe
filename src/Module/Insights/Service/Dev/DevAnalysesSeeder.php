<?php

declare(strict_types=1);

namespace App\Module\Insights\Service\Dev;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunUsage;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Insights\Command\StartAnalysisHandler;
use App\Module\Insights\Entity\Analysis;
use App\Module\Insights\Entity\AnalysisScope;
use App\Module\Insights\Entity\AnalysisTopic;
use App\Module\Insights\Entity\Proposal;
use App\Module\Insights\Entity\ProposalKind;
use App\Module\Insights\Entity\ProposalState;
use App\Module\Insights\Repository\AnalysisRepository;
use App\Module\Insights\Service\AnalysisSettings;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Service\DocumentSearchIndexer;
use App\Module\Review\Service\MarkdownRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Writes a finished cost analysis with its report, two proposals and a costed
 * run, a waiting one, and a finished experiment analysis, so the Reports page
 * of a dev project shows each state. The waiting analysis opens no work
 * request, so no bridge claims it.
 */
#[When('dev')]
final readonly class DevAnalysesSeeder
{
    private const string REPORT = <<<'MARKDOWN'
        # Cost report: last 90 days

        ## Summary

        Most of the cost comes from the implementation stage. Each run installs the dependencies again, which takes about a fifth of its tokens.

        ## Proposals

        1. Cache the dependencies between runs.
        2. Run the lint stage on a smaller model.
        MARKDOWN;

    private const string EXPERIMENT_REPORT = <<<'MARKDOWN'
        # Experiment report: implement

        ## Summary

        The sonnet variant costs about 60% less per merged card, and the cost gap is clear. Its merge rate is lower and it needs more fix rounds, but neither gap is clear yet. Run more cards before a decision.

        ## Proposals

        1. Run 10 more cards on each variant.
        MARKDOWN;

    public function __construct(
        private EntityManagerInterface $em,
        private AnalysisRepository $analyses,
        private MarkdownRenderer $renderer,
        private DocumentSearchIndexer $documentSearch,
    ) {
    }

    /**
     * Each part writes only when the project holds no analysis of its topic, so
     * a second run adds nothing and a project seeded before the experiment part
     * gains it.
     */
    public function seed(Project $project, string $experiment): void
    {
        $topics = array_map(static fn (Analysis $analysis): AnalysisTopic => $analysis->topic, $this->analyses->findByProject($project));
        if (!\in_array(AnalysisTopic::Cost, $topics, true)) {
            $this->seedCost($project);
        }
        if (!\in_array(AnalysisTopic::Experiment, $topics, true)) {
            $this->seedExperiment($project, $experiment);
        }
    }

    private function seedCost(Project $project): void
    {
        $report = new Document($project->owner, $project, 'Cost report: last 90 days');
        $report->addVersion(self::REPORT, $this->renderer->render(self::REPORT));
        $this->em->persist($report);

        $done = new Analysis($project, AnalysisTopic::Cost, new AnalysisScope(MetricRange::NinetyDays), null, AnalysisSettings::DEFAULT_MODEL, AnalysisSettings::DEFAULT_EFFORT, new \DateTimeImmutable('-2 days'));
        $this->em->persist($done);
        $this->em->flush();
        $done->start();
        $done->complete($report->id ?? throw new \LogicException('A stored document has an id.'), new \DateTimeImmutable('-2 days +20 minutes'));
        $this->seedRun($done, new \DateTimeImmutable('-2 days +20 minutes'));

        $this->em->persist(new Proposal($done, ProposalKind::Card, 'Cache the dependencies between runs', "Each run installs the dependencies again.\nA shared cache keyed on the lock file skips that step.", null, 'About $4 a week', 0));
        $dismissed = new Proposal($done, ProposalKind::Card, 'Run the lint stage on a smaller model', 'The lint stage reads short diffs, so a smaller model can do it.', null, 'About $1 a week', 1);
        $dismissed->state = ProposalState::Dismissed;
        $dismissed->dismissReason = 'The lint stage already runs on the smallest model.';
        $this->em->persist($dismissed);

        $this->em->persist(new Analysis($project, AnalysisTopic::Cost, new AnalysisScope(MetricRange::ThirtyDays), null, AnalysisSettings::DEFAULT_MODEL, AnalysisSettings::DEFAULT_EFFORT, new \DateTimeImmutable('-10 minutes')));
        $this->em->flush();
        $this->documentSearch->index($report);
    }

    private function seedExperiment(Project $project, string $experiment): void
    {
        $report = new Document($project->owner, $project, 'Experiment report: '.$experiment);
        $report->addVersion(self::EXPERIMENT_REPORT, $this->renderer->render(self::EXPERIMENT_REPORT));
        $this->em->persist($report);

        $analysis = new Analysis($project, AnalysisTopic::Experiment, new AnalysisScope(MetricRange::All, $experiment), null, AnalysisSettings::DEFAULT_MODEL, AnalysisSettings::DEFAULT_EFFORT, new \DateTimeImmutable('-1 day'));
        $this->em->persist($analysis);
        $this->em->flush();
        $analysis->start();
        $analysis->complete($report->id ?? throw new \LogicException('A stored document has an id.'), new \DateTimeImmutable('-1 day +25 minutes'));
        $this->seedRun($analysis, new \DateTimeImmutable('-1 day +25 minutes'));
        $this->em->persist(new Proposal($analysis, ProposalKind::Card, 'Run 10 more cards on each variant before a decision', "The cost gap is clear: $1.74 against $4.33 per merged card.\nThe merge rate and the fix rounds give no clear answer with 6 finished cards per variant.", null, null, 0));
        $this->em->flush();
        $this->documentSearch->index($report);
    }

    /** WorkerRunFactListener writes the fact row on flush, and AnalysisRepository::costOf() sums it. */
    private function seedRun(Analysis $analysis, \DateTimeImmutable $endedAt): void
    {
        $run = new WorkerRun(
            project: $analysis->project,
            bridgeId: Uuid::v4(),
            subjectType: Analysis::SUBJECT_TYPE,
            subjectId: $analysis->id ?? throw new \LogicException('A stored analysis has an id.'),
            cardNumber: null,
            workKind: StartAnalysisHandler::WORK_KIND,
            state: WorkerRunState::Succeeded,
            runKey: Uuid::v4(),
            startedAt: $endedAt->modify('-18 minutes'),
            endedAt: $endedAt,
            exitCode: 0,
            hasResult: true,
            output: 'The report is written and the analysis is done.',
            receivedAt: $endedAt,
        );
        $run->usageSource = WorkerRunUsageSource::Reported;
        $this->em->persist($run);
        $this->em->persist(new WorkerRunUsage($run, $analysis->project, $run->subjectType, $run->subjectId, $run->workKind, 'claude-sonnet-5-5', WorkerRunUsageSource::Reported, 182_000, 9_400, 1_250_000, 64_000, '1.840000'));
    }
}
