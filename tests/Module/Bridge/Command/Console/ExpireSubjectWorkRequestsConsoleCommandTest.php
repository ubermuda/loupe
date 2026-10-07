<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command\Console;

use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ExpireSubjectWorkRequestsConsoleCommandTest extends KernelTestCase
{
    use BridgeScenario;

    /** No module registers a subject type yet, so only a card request can exist, and the workflow expires that. */
    public function test_the_command_leaves_an_old_card_request_and_reports_the_count(): void
    {
        $kernel = self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'expire-console@example.com'), 'Expire Console');
        $card = $this->seedWorkRequest($em, $project, createdAt: new \DateTimeImmutable('-1 day'));

        $tester = new CommandTester(new Application($kernel)->find('app:expire-subject-work-requests'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Expired 0 work request(s).', $tester->getDisplay());
        $em->clear();
        self::assertSame(WorkRequestState::Open, $em->find(WorkRequest::class, $card->id)?->state);
    }
}
