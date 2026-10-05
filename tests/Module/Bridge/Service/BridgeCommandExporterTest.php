<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Service\BridgeCommandExporter;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BridgeCommandExporterTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_it_exports_the_commands_on_the_users_bridges_and_the_ones_the_user_asked_for(): void
    {
        self::bootKernel();
        $em = $this->em();
        $exporting = $this->user($em, 'commands-export-mine@example.com');
        $other = $this->user($em, 'commands-export-other@example.com');
        $ownRun = $this->seedRun($em, $this->project($em, $exporting, 'Own Commands'), cardNumber: 4);
        $own = $this->seedCommand(
            $em,
            $ownRun,
            state: BridgeCommandState::Refused,
            requestedAt: new \DateTimeImmutable('2026-09-29T11:00:00+00:00'),
            kind: BridgeCommandKind::ResumeRun,
        );
        $own->reason = 'the run was gone';
        $own->settledAt = new \DateTimeImmutable('2026-09-29T11:01:00+00:00');
        $own->context = new WorkRequestContext(42, null, 'abc1234', 'conflict');
        $otherRun = $this->seedRun($em, $this->project($em, $other, 'Other Commands'), cardNumber: 9);
        $asked = $this->seedCommand($em, $otherRun, requestedAt: new \DateTimeImmutable('2026-09-29T11:30:00+00:00'), requestedBy: $exporting);
        $this->seedCommand($em, $this->seedRun($em, $this->project($em, $other, 'Unrelated Commands')));
        $em->flush();
        $em->clear();

        $rows = iterator_to_array($this->exporter()->export($exporting), false);

        self::assertSame([
            [
                'commandId' => (string) $own->id,
                'project' => 'Own Commands',
                'bridgeId' => (string) $ownRun->bridgeId,
                'runId' => (string) $ownRun->id,
                'cardNumber' => 4,
                'kind' => 'resume-run',
                'state' => 'refused',
                'reason' => 'the run was gone',
                'cause' => 'person',
                'context' => ['pullRequestNumber' => 42, 'pullRequestUrl' => null, 'headSha' => 'abc1234', 'reason' => 'conflict', 'documentId' => null],
                'requestedByYou' => false,
                'requestedAt' => '2026-09-29T11:00:00+00:00',
                'expiresAt' => '2026-09-29T11:15:00+00:00',
                'settledAt' => '2026-09-29T11:01:00+00:00',
            ],
            [
                'commandId' => (string) $asked->id,
                'project' => 'Other Commands',
                'bridgeId' => (string) $otherRun->bridgeId,
                'runId' => (string) $otherRun->id,
                'cardNumber' => 9,
                'kind' => 'stop-run',
                'state' => 'pending',
                'reason' => null,
                'cause' => 'person',
                'context' => ['pullRequestNumber' => null, 'pullRequestUrl' => null, 'headSha' => null, 'reason' => null, 'documentId' => null],
                'requestedByYou' => true,
                'requestedAt' => '2026-09-29T11:30:00+00:00',
                'expiresAt' => '2026-09-29T11:45:00+00:00',
                'settledAt' => null,
            ],
        ], $rows);
    }

    public function test_the_file_is_named_for_the_commands(): void
    {
        self::bootKernel();

        self::assertSame('bridge_commands.json', $this->exporter()->filename());
    }

    private function exporter(): BridgeCommandExporter
    {
        $exporter = self::getContainer()->get(BridgeCommandExporter::class);
        self::assertInstanceOf(BridgeCommandExporter::class, $exporter);

        return $exporter;
    }
}
