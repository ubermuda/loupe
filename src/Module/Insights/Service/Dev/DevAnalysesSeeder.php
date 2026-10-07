<?php

declare(strict_types=1);

namespace App\Module\Insights\Service\Dev;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunToolCall;
use App\Module\Bridge\Entity\WorkerRunUsage;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Service\BucketTimeComputer;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Insights\Command\StartAnalysisHandler;
use App\Module\Insights\Entity\Analysis;
use App\Module\Insights\Entity\AnalysisScope;
use App\Module\Insights\Entity\AnalysisTopic;
use App\Module\Insights\Entity\InsightsBucketRule;
use App\Module\Insights\Entity\Proposal;
use App\Module\Insights\Entity\ProposalKind;
use App\Module\Insights\Entity\ProposalState;
use App\Module\Insights\Repository\AnalysisRepository;
use App\Module\Insights\Repository\InsightsBucketRuleRepository;
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
 * run, and a waiting one, so the Reports page of a dev project shows each
 * state. It also writes three time bucket rules, a finished time analysis whose
 * run holds tool calls, and one bucket rule proposal. Each part runs only when
 * the project lacks it. The waiting analysis opens no work request, so no
 * bridge claims it.
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

    private const string TIME_REPORT = <<<'MARKDOWN'
        # Time report: last 30 days

        ## Summary

        A run spends most of its tool time in `make test` and `make build`. No bucket rule takes them, so they fall in `other`.

        ## Proposals

        1. Put the `make` calls in a bucket of their own.
        MARKDOWN;

    /** @var list<array{string, string}> pattern and bucket */
    private const array RULES = [['git *', 'git'], ['grep', 'search'], ['Agent', 'subagent']];

    /** @var list<array{string, int, int, list<string>}> tool, start offset in seconds, duration in milliseconds, signatures */
    private const array CALLS = [
        ['Bash', 10, 45_000, ['git push']],
        ['Bash', 70, 12_000, ['grep']],
        ['Bash', 100, 240_000, ['make test']],
        ['Bash', 400, 180_000, ['make build']],
        ['Agent', 600, 300_000, ['Agent']],
        ['Bash', 950, 8_000, ['git status']],
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private AnalysisRepository $analyses,
        private InsightsBucketRuleRepository $insightsBucketRules,
        private BucketTimeComputer $bucketTimes,
        private MarkdownRenderer $renderer,
        private DocumentSearchIndexer $documentSearch,
    ) {
    }

    /** False when the project already holds every part, so a second run adds nothing. */
    public function seed(Project $project): bool
    {
        $topics = array_map(static fn (Analysis $analysis): AnalysisTopic => $analysis->topic, $this->analyses->findByProject($project));
        $costSeeded = !\in_array(AnalysisTopic::Cost, $topics, true) && $this->seedCost($project);
        $timeSeeded = !\in_array(AnalysisTopic::Time, $topics, true) && $this->seedTime($project);

        return $costSeeded || $timeSeeded;
    }

    private function seedCost(Project $project): bool
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

        return true;
    }

    private function seedTime(Project $project): bool
    {
        $endedAt = new \DateTimeImmutable('-1 day');
        $report = new Document($project->owner, $project, 'Time report: last 30 days');
        $report->addVersion(self::TIME_REPORT, $this->renderer->render(self::TIME_REPORT));
        $this->em->persist($report);

        $done = new Analysis($project, AnalysisTopic::Time, new AnalysisScope(MetricRange::ThirtyDays), null, AnalysisSettings::DEFAULT_MODEL, AnalysisSettings::DEFAULT_EFFORT, $endedAt->modify('-1 hour'));
        $this->em->persist($done);
        $this->em->flush();
        $done->start();
        $done->complete($report->id ?? throw new \LogicException('A stored document has an id.'), $endedAt);
        $this->em->persist(new Proposal($done, ProposalKind::BucketRule, 'Put the make calls in a bucket of their own', "The calls `make test` and `make build` take 7 minutes of an 18 minute run.\nNo rule takes them, so they count in `other`.", ['pattern' => 'make *', 'bucket' => 'build'], null, 0));

        if ([] === $this->insightsBucketRules->findOrdered($project)) {
            foreach (self::RULES as $position => [$pattern, $bucket]) {
                $this->em->persist(new InsightsBucketRule($project, $pattern, $bucket, $position));
            }
        }

        $run = $this->seedRun($done, $endedAt);
        $this->seedToolCalls($run);
        $this->em->flush();
        $this->bucketTimes->recompute($project, [$run->id ?? throw new \LogicException('A stored run has an id.')]);
        $this->documentSearch->index($report);

        return true;
    }

    private function seedToolCalls(WorkerRun $run): void
    {
        foreach (self::CALLS as $index => [$tool, $offset, $durationMs, $signatures]) {
            $this->em->persist(new WorkerRunToolCall(Uuid::v7(), $run, $index + 1, $tool, $run->startedAt?->modify(\sprintf('+%d seconds', $offset)) ?? throw new \LogicException('A seeded run has a start.'), $durationMs, false, false, null, null, $signatures, null));
        }
    }

    /** WorkerRunFactListener writes the fact row on flush, and AnalysisRepository::costOf() sums it. */
    private function seedRun(Analysis $analysis, \DateTimeImmutable $endedAt): WorkerRun
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

        return $run;
    }
}
