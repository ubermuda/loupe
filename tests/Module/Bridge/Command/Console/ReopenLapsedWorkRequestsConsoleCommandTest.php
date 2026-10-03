<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command\Console;

use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

final class ReopenLapsedWorkRequestsConsoleCommandTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_the_command_reopens_the_lapsed_claims_and_reports_the_count(): void
    {
        $kernel = self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'reopen-console@example.com'), 'Reopen Console');
        $lapsed = $this->seedWorkRequest($em, $project, state: WorkRequestState::Claimed, bridgeId: Uuid::v4(), claimToken: Uuid::v4(), leaseUntil: new \DateTimeImmutable('-5 minutes'));

        $tester = new CommandTester(new Application($kernel)->find('app:reopen-lapsed-work-requests'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Reopened 1 work request(s).', $tester->getDisplay());
        $em->clear();
        self::assertSame(WorkRequestState::Open, $em->find(WorkRequest::class, $lapsed->id)?->state);
    }
}
