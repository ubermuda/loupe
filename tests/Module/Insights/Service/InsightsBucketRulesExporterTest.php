<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service;

use App\Module\Insights\Entity\InsightsBucketRule;
use App\Module\Insights\Service\InsightsBucketRulesExporter;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InsightsBucketRulesExporterTest extends KernelTestCase
{
    use InsightsScenario;

    public function test_it_exports_the_rules_of_the_projects_the_user_owns_in_order(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->scenarioProject('rules-export');
        $em->persist(new InsightsBucketRule($project, 'Bash:just *', 'just', 5));
        $em->persist(new InsightsBucketRule($project, 'Bash:git *', 'git', 2));
        $em->persist(new InsightsBucketRule($this->scenarioProject('rules-export-other'), 'Bash:npm *', 'npm', 0));
        $em->flush();

        $exporter = self::getContainer()->get(InsightsBucketRulesExporter::class);
        self::assertInstanceOf(InsightsBucketRulesExporter::class, $exporter);
        $rows = iterator_to_array($exporter->export($project->owner), false);

        self::assertSame('insights_bucket_rules.json', $exporter->filename());
        self::assertSame([
            ['projectId' => (string) $project->id, 'project' => $project->name, 'position' => 2, 'pattern' => 'Bash:git *', 'bucket' => 'git'],
            ['projectId' => (string) $project->id, 'project' => $project->name, 'position' => 5, 'pattern' => 'Bash:just *', 'bucket' => 'just'],
        ], $rows);
    }
}
