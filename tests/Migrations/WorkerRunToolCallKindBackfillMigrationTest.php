<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261008122922;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261008122922.php';

final class WorkerRunToolCallKindBackfillMigrationTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_a_stored_call_takes_the_kind_of_its_tool_name(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'tool-call-kind-backfill@example.com'), 'Tool call kind backfill');
        $run = $this->seedRun($em, $project);
        foreach (['Bash', 'Agent', 'Task', 'Read'] as $index => $tool) {
            $this->seedToolCall($run, $index + 1, $tool);
        }
        $connection = $em->getConnection();

        foreach (['down', 'up'] as $direction) {
            $migration = new Version20261008122922($connection, new NullLogger());
            $migration->{$direction}(new Schema());
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }

        self::assertSame(
            ['Bash' => 'shell', 'Agent' => 'subagent', 'Task' => 'subagent', 'Read' => 'tool'],
            $connection->fetchAllKeyValue('SELECT tool, kind FROM bridge_worker_run_tool_calls WHERE run_id = ? ORDER BY seq', [(string) $run->id]),
        );
    }
}
