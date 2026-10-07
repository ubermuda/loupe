<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights;

use App\Module\Bridge\Metric\MetricRange;
use App\Module\Insights\Entity\Analysis;
use App\Module\Insights\Entity\AnalysisScope;
use App\Module\Insights\Entity\AnalysisState;
use App\Module\Insights\Entity\AnalysisTopic;
use App\Module\Insights\Entity\Proposal;
use App\Module\Insights\Entity\ProposalKind;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\ORM\EntityManagerInterface;

/** Fixtures the Insights module's database tests share. Each helper flushes. */
trait InsightsScenario
{
    use BridgeScenario;

    private function scenarioProject(string $name): Project
    {
        $em = $this->em();

        return $this->project($em, $this->user($em, $name.'-'.uniqid().'@example.com'), 'Project '.$name);
    }

    private function seedAnalysis(EntityManagerInterface $em, Project $project, AnalysisState $state = AnalysisState::Waiting): Analysis
    {
        $analysis = new Analysis($project, AnalysisTopic::Cost, new AnalysisScope(MetricRange::NinetyDays), null, 'sonnet', 'medium', new \DateTimeImmutable('2026-10-07 09:00:00'));
        $analysis->state = $state;
        $em->persist($analysis);
        $em->flush();

        return $analysis;
    }

    /** @param array<mixed>|null $payload */
    private function seedProposal(EntityManagerInterface $em, Analysis $analysis, ProposalKind $kind = ProposalKind::Card, int $position = 0, ?array $payload = null): Proposal
    {
        $proposal = new Proposal($analysis, $kind, 'Cache the dependencies', 'Each run installs them again.', $payload, 'About $4 a week', $position);
        $em->persist($proposal);
        $em->flush();

        return $proposal;
    }

    private function seedDocument(EntityManagerInterface $em, Project $project): Document
    {
        $document = new Document($project->owner, $project, 'Cost report');
        $em->persist($document);
        $em->flush();

        return $document;
    }
}
