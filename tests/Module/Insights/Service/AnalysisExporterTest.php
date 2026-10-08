<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service;

use App\Module\Insights\Entity\AnalysisState;
use App\Module\Insights\Service\AnalysisExporter;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AnalysisExporterTest extends KernelTestCase
{
    use InsightsScenario;

    public function test_it_exports_the_analyses_of_the_owner_with_their_proposals(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->scenarioProject('analysis-export');
        $analysis = $this->seedAnalysis($em, $project, AnalysisState::Done);
        $proposal = $this->seedProposal($em, $analysis);
        $this->seedAnalysis($em, $this->scenarioProject('analysis-export-other'));

        $exporter = self::getContainer()->get(AnalysisExporter::class);
        self::assertInstanceOf(AnalysisExporter::class, $exporter);
        $rows = iterator_to_array($exporter->export($project->owner), false);

        self::assertSame('insights_analyses.json', $exporter->filename());
        self::assertCount(1, $rows);
        self::assertSame((string) $analysis->id, $rows[0]['analysisId']);
        self::assertSame($project->name, $rows[0]['project']);
        self::assertSame('cost', $rows[0]['topic']);
        self::assertSame(['range' => 'ninety-days'], $rows[0]['scope']);
        self::assertSame('done', $rows[0]['state']);
        self::assertNull($rows[0]['costMicroUsd']);
        self::assertSame((string) $proposal->id, $rows[0]['proposals'][0]['proposalId']);
        self::assertSame('Cache the dependencies', $rows[0]['proposals'][0]['title']);
        self::assertSame('proposed', $rows[0]['proposals'][0]['state']);
    }
}
