<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Entity\ExperimentPin;
use App\Module\Bridge\Service\ExperimentPinExporter;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ExperimentPinExporterTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_it_exports_the_pins_of_the_projects_the_user_owns(): void
    {
        self::bootKernel();
        $em = $this->em();
        $exporting = $this->user($em, 'pins-export-mine@example.com');
        $other = $this->user($em, 'pins-export-other@example.com');
        $cardId = Uuid::v7();
        $em->persist(new ExperimentPin(
            project: $this->project($em, $exporting, 'Pinned Project'),
            cardId: $cardId,
            experiment: 'plan-model',
            variant: 'opus',
            createdAt: new \DateTimeImmutable('2026-09-13T10:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-09-14T11:00:00+00:00'),
        ));
        $em->persist(new ExperimentPin($this->project($em, $other, 'Other Project'), Uuid::v7(), 'plan-model', 'sonnet'));
        $em->flush();
        $em->clear();

        $rows = iterator_to_array($this->exporter()->export($exporting), false);

        self::assertSame([[
            'project' => 'Pinned Project',
            'cardId' => (string) $cardId,
            'experiment' => 'plan-model',
            'variant' => 'opus',
            'createdAt' => '2026-09-13T10:00:00+00:00',
            'updatedAt' => '2026-09-14T11:00:00+00:00',
        ]], $rows);
    }

    public function test_the_file_is_named_for_the_pins(): void
    {
        self::bootKernel();

        self::assertSame('experiment_pins.json', $this->exporter()->filename());
    }

    private function exporter(): ExperimentPinExporter
    {
        $exporter = self::getContainer()->get(ExperimentPinExporter::class);
        self::assertInstanceOf(ExperimentPinExporter::class, $exporter);

        return $exporter;
    }
}
