<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261008143545;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261008143545.php';

final class LifecycleChildrenStartInNextMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_binding_that_starts_children_from_the_backlog_gets_the_shipped_copy_and_a_second_run_changes_nothing(): void
    {
        self::bootKernel();
        $lifecycle = $this->workflowProject('migration-children-start-in-next');
        $this->bindLifecycle($lifecycle);
        $simple = $this->workflowProject('migration-children-start-in-next-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($simple, 'simple', []));
        $lifecycleId = ($lifecycle->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $simpleId = ($simple->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $connection->executeStatement(
            "UPDATE workflow_bindings SET definition = jsonb_set(definition, '{rules}', (
                SELECT jsonb_agg(CASE WHEN rule->>'id' IN ('child-unblocked', 'unplanned-child')
                    THEN rule || '{\"slot\": \"@backlog\"}'::jsonb ELSE rule END ORDER BY position)
                FROM jsonb_array_elements(definition->'rules') WITH ORDINALITY AS rules(rule, position)
                WHERE rule->>'id' NOT IN ('child-to-next', 'epic-entered-implementation')
            )) WHERE project_id = ?",
            [$lifecycleId],
        );
        $old = $this->definition($connection, $lifecycleId);
        self::assertNotContains('child-to-next', array_column($old['rules'], 'id'));
        self::assertNotContains('epic-entered-implementation', array_column($old['rules'], 'id'));
        $simpleBefore = $this->definition($connection, $simpleId);

        $this->migrate($connection);
        $this->migrate($connection);

        $shipped = $this->service(ShippedTemplates::class)->source('lifecycle');
        self::assertContains('child-to-next', array_column($shipped['rules'], 'id'));
        self::assertEquals($shipped, $this->definition($connection, $lifecycleId));
        self::assertEquals($simpleBefore, $this->definition($connection, $simpleId));
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261008143545($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @return array<mixed> */
    private function definition(Connection $connection, string $projectId): array
    {
        $definition = $connection->fetchOne('SELECT definition FROM workflow_bindings WHERE project_id = ?', [$projectId]);
        self::assertIsString($definition);
        $decoded = json_decode($definition, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
