<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Bridge\Command\RebuildWorkerRunFactsCommand;
use App\Module\Bridge\Command\RebuildWorkerRunFactsHandler;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class RebuildWorkerRunFactsHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    /** A batch of two over three runs makes the loop take a second, shorter batch. */
    public function test_it_rewrites_a_stale_row_and_a_missing_row_and_a_second_call_changes_nothing(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'facts-rebuild@example.com'), 'Facts Rebuild');
        $stale = $this->seedRun($em, $project);
        $missing = $this->seedRun($em, $project, cardNumber: 2);
        $this->seedRun($em, $project, cardNumber: 3);
        $connection = $em->getConnection();
        $expected = $connection->fetchAllAssociative('SELECT * FROM bridge_worker_run_facts ORDER BY run_id');
        self::assertCount(3, $expected);
        $connection->executeStatement("UPDATE bridge_worker_run_facts SET outcome = 'running', duration_ms = NULL WHERE run_id = ?", [(string) $stale->id]);
        $connection->executeStatement('DELETE FROM bridge_worker_run_facts WHERE run_id = ?', [(string) $missing->id]);

        self::assertSame(3, ($this->handler())(new RebuildWorkerRunFactsCommand(batchSize: 2)));
        self::assertSame($expected, $connection->fetchAllAssociative('SELECT * FROM bridge_worker_run_facts ORDER BY run_id'));

        self::assertSame(3, ($this->handler())(new RebuildWorkerRunFactsCommand(batchSize: 2)));
        self::assertSame($expected, $connection->fetchAllAssociative('SELECT * FROM bridge_worker_run_facts ORDER BY run_id'));
    }

    public function test_the_console_command_prints_the_count(): void
    {
        $kernel = self::bootKernel();
        $em = $this->em();
        $this->seedRun($em, $this->project($em, $this->user($em, 'facts-rebuild-console@example.com'), 'Facts Rebuild Console'));
        $runs = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_worker_runs');

        $tester = new CommandTester(new Application($kernel)->find('app:bridge:rebuild-run-facts'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString(\sprintf('Rebuilt the fact rows of %d worker run(s).', $runs), $tester->getDisplay());
    }

    private function handler(): RebuildWorkerRunFactsHandler
    {
        $handler = self::getContainer()->get(RebuildWorkerRunFactsHandler::class);
        self::assertInstanceOf(RebuildWorkerRunFactsHandler::class, $handler);

        return $handler;
    }
}
