<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Entity\CardHold;
use App\Module\Bridge\Service\CardHoldExporter;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CardHoldExporterTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_it_exports_the_holds_of_the_projects_the_user_owns(): void
    {
        self::bootKernel();
        $em = $this->em();
        $exporting = $this->user($em, 'holds-export-mine@example.com');
        $other = $this->user($em, 'holds-export-other@example.com');
        $project = $this->project($em, $exporting, 'Held Project');
        $cardId = Uuid::v7();
        $em->persist(new CardHold($project, $cardId, $other, new \DateTimeImmutable('2026-09-13T10:00:00+00:00')));
        $em->persist(new CardHold($this->project($em, $other, 'Other Project'), Uuid::v7(), $exporting, new \DateTimeImmutable()));
        $em->flush();
        $em->clear();

        $rows = iterator_to_array($this->exporter()->export($exporting), false);

        self::assertSame([[
            'project' => 'Held Project',
            'cardId' => (string) $cardId,
            'heldAt' => '2026-09-13T10:00:00+00:00',
        ]], $rows);
    }

    public function test_the_file_is_named_for_the_holds(): void
    {
        self::bootKernel();

        self::assertSame('bridge_card_holds.json', $this->exporter()->filename());
    }

    private function exporter(): CardHoldExporter
    {
        $exporter = self::getContainer()->get(CardHoldExporter::class);
        self::assertInstanceOf(CardHoldExporter::class, $exporter);

        return $exporter;
    }
}
