<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Service\WorkRequestExporter;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WorkRequestExporterTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_it_exports_the_work_requests_of_the_projects_the_user_owns_without_the_claim_token(): void
    {
        self::bootKernel();
        $em = $this->em();
        $exporting = $this->user($em, 'work-requests-export-mine@example.com');
        $other = $this->user($em, 'work-requests-export-other@example.com');
        $project = $this->project($em, $exporting, 'Own Work Requests');
        $cardId = Uuid::v7();
        $bridgeId = Uuid::v4();
        $claimed = $this->seedWorkRequest(
            $em,
            $project,
            cardId: $cardId,
            capability: 'claude',
            state: WorkRequestState::Claimed,
            createdAt: new \DateTimeImmutable('2026-10-01T12:00:00+00:00'),
            bridgeId: $bridgeId,
            claimToken: Uuid::v4(),
            leaseUntil: new \DateTimeImmutable('2026-10-01T12:05:00+00:00'),
        );
        $claimed->claims = 2;
        $refused = $this->seedWorkRequest($em, $project, kind: 'merge', createdAt: new \DateTimeImmutable('2026-10-01T12:10:00+00:00'));
        $refused->state = WorkRequestState::Refused;
        $refused->reason = 'no-capacity';
        $refused->settledAt = new \DateTimeImmutable('2026-10-01T12:11:00+00:00');
        $this->seedWorkRequest($em, $this->project($em, $other, 'Other Work Requests'));
        $em->flush();
        $em->clear();

        $rows = iterator_to_array($this->exporter()->export($exporting), false);

        self::assertSame([
            [
                'workRequestId' => (string) $claimed->id,
                'project' => 'Own Work Requests',
                'cardId' => (string) $cardId,
                'cardNumber' => 7,
                'kind' => 'implement',
                'capability' => 'claude',
                'ruleId' => 'implement-on-entry',
                'state' => 'claimed',
                'bridgeId' => (string) $bridgeId,
                'claims' => 2,
                'leaseUntil' => '2026-10-01T12:05:00+00:00',
                'reason' => null,
                'createdAt' => '2026-10-01T12:00:00+00:00',
                'settledAt' => null,
            ],
            [
                'workRequestId' => (string) $refused->id,
                'project' => 'Own Work Requests',
                'cardId' => (string) $refused->cardId,
                'cardNumber' => 7,
                'kind' => 'merge',
                'capability' => null,
                'ruleId' => 'implement-on-entry',
                'state' => 'refused',
                'bridgeId' => null,
                'claims' => 0,
                'leaseUntil' => null,
                'reason' => 'no-capacity',
                'createdAt' => '2026-10-01T12:10:00+00:00',
                'settledAt' => '2026-10-01T12:11:00+00:00',
            ],
        ], $rows);
    }

    public function test_the_file_is_named_for_the_work_requests(): void
    {
        self::bootKernel();

        self::assertSame('bridge_work_requests.json', $this->exporter()->filename());
    }

    private function exporter(): WorkRequestExporter
    {
        $exporter = self::getContainer()->get(WorkRequestExporter::class);
        self::assertInstanceOf(WorkRequestExporter::class, $exporter);

        return $exporter;
    }
}
