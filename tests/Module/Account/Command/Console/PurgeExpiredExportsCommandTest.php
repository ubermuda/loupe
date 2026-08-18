<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command\Console;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command is the manual backstop for the scheduled purge — the thing an
 * operator reaches for when the worker has been down. Its attachment to the
 * schedule now belongs to PurgeExpiredExportsTask and is asserted there; what
 * matters here is that the command itself still runs.
 */
final class PurgeExpiredExportsCommandTest extends KernelTestCase
{
    public function test_the_command_runs_and_reports_what_it_purged(): void
    {
        $kernel = self::bootKernel();

        $tester = new CommandTester(new Application($kernel)->find('app:purge-expired-exports'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Purged', $tester->getDisplay());
    }
}
