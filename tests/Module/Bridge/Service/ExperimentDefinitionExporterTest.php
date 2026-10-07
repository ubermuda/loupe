<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Entity\ExperimentDefinition;
use App\Module\Bridge\Service\ExperimentDefinitionExporter;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExperimentDefinitionExporterTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_it_exports_the_weights_of_the_projects_the_user_owns(): void
    {
        self::bootKernel();
        $em = $this->em();
        $exporting = $this->user($em, 'definitions-export-mine@example.com');
        $other = $this->user($em, 'definitions-export-other@example.com');
        $definition = new ExperimentDefinition(
            project: $this->project($em, $exporting, 'Weighted Project'),
            experiment: 'plan-model',
            weights: [['name' => 'opus', 'weight' => 1], ['name' => 'sonnet', 'weight' => 3]],
            reportedAt: new \DateTimeImmutable('2026-09-14T11:00:00+00:00'),
        );
        $definition->metrics = ['merge-rate', 'cost'];
        $em->persist($definition);
        $em->persist(new ExperimentDefinition($this->project($em, $other, 'Other Project'), 'plan-model', [['name' => 'opus', 'weight' => 1]]));
        $em->flush();
        $em->clear();

        $rows = iterator_to_array($this->exporter()->export($exporting), false);

        self::assertSame([[
            'project' => 'Weighted Project',
            'experiment' => 'plan-model',
            'weights' => [['name' => 'opus', 'weight' => 1], ['name' => 'sonnet', 'weight' => 3]],
            'metrics' => ['merge-rate', 'cost'],
            'reportedAt' => '2026-09-14T11:00:00+00:00',
        ]], $rows);
    }

    public function test_the_file_is_named_for_the_definitions(): void
    {
        self::bootKernel();

        self::assertSame('experiment_definitions.json', $this->exporter()->filename());
    }

    private function exporter(): ExperimentDefinitionExporter
    {
        $exporter = self::getContainer()->get(ExperimentDefinitionExporter::class);
        self::assertInstanceOf(ExperimentDefinitionExporter::class, $exporter);

        return $exporter;
    }
}
