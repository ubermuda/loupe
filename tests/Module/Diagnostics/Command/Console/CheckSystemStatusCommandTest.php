<?php

declare(strict_types=1);

namespace App\Tests\Module\Diagnostics\Command\Console;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Full-stack run: the command resolves the real container handler, so every
 * registered check runs against this instance exactly as /admin/status does.
 */
final class CheckSystemStatusCommandTest extends KernelTestCase
{
    public function test_it_reports_every_registered_check_with_a_translated_label(): void
    {
        $tester = $this->tester();
        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('System status:', $display);
        // The labels are translated, so a raw key on screen means the command
        // rendered the message id instead of the message.
        self::assertStringNotContainsString('account.system_status.check.', $display);
    }

    public function test_an_unknown_result_is_never_silently_counted_as_a_pass(): void
    {
        // The worker check cannot observe a running consumer, so it reports
        // unknown on a test kernel — which the command must call out rather
        // than fold into a green summary.
        $tester = $this->tester();
        $tester->execute([]);

        self::assertStringContainsString('Unknown is not a pass', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        return new CommandTester(
            new Application(self::bootKernel())->find('app:system-status'),
        );
    }
}
