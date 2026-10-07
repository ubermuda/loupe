<?php

declare(strict_types=1);

namespace App\Module\Insights\Service\Dev;

use App\Module\Bridge\Metric\MetricRange;
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

/**
 * Writes a finished cost analysis with its report and two proposals, and a
 * waiting one, so the Reports page of a dev project shows each state. The
 * waiting analysis opens no work request, so no bridge claims it.
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

    public function __construct(
        private EntityManagerInterface $em,
        private AnalysisRepository $analyses,
        private MarkdownRenderer $renderer,
        private DocumentSearchIndexer $documentSearch,
    ) {
    }

    /** False when the project already holds an analysis, so a second run adds nothing. */
    public function seed(Project $project): bool
    {
        if ([] !== $this->analyses->findByProject($project)) {
            return false;
        }

        $report = new Document($project->owner, $project, 'Cost report: last 90 days');
        $report->addVersion(self::REPORT, $this->renderer->render(self::REPORT));
        $this->em->persist($report);

        $done = new Analysis($project, AnalysisTopic::Cost, new AnalysisScope(MetricRange::NinetyDays), null, AnalysisSettings::DEFAULT_MODEL, AnalysisSettings::DEFAULT_EFFORT, new \DateTimeImmutable('-2 days'));
        $this->em->persist($done);
        $this->em->flush();
        $done->start();
        $done->complete($report->id ?? throw new \LogicException('A stored document has an id.'), new \DateTimeImmutable('-2 days +20 minutes'));

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
}
