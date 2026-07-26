<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Command\Console;

use App\Tests\Support\BillingScenario;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Full-stack run of the console backstop: the command resolves the real
 * container TrialEndSweeper, so the billing.enabled gate reads a real
 * FeatureFlag row and the sweep hits the real database.
 */
final class SweepEndedTrialsCommandTest extends KernelTestCase
{
    public function test_it_sweeps_an_expired_trial_and_is_idempotent(): void
    {
        $kernel = self::bootKernel();

        $scenario = new BillingScenario(static::getContainer());
        $scenario->enableBilling();
        $user = $scenario->verifiedUser('sweepcommand');
        $scenario->profile($user, new \DateTimeImmutable('-1 day'));

        $tester = new CommandTester(
            new Application($kernel)->find('app:sweep-ended-trials'),
        );

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Disabled 1', $tester->getDisplay());
        self::assertNotNull($user->disabledAt);

        // Marker idempotence through the full stack: a second run re-selects
        // nothing and reports all zeroes.
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Disabled 0', $tester->getDisplay());
    }
}
