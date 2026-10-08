<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261008022512;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261008022512.php';

final class LifecycleUnplannedChildMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_binding_without_the_ask_rule_gets_the_shipped_copy_and_a_second_run_changes_nothing(): void
    {
        self::bootKernel();
        $lifecycle = $this->workflowProject('migration-unplanned-child');
        $this->bindLifecycle($lifecycle);
        $simple = $this->workflowProject('migration-unplanned-child-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($simple, 'simple', []));
        $lifecycleId = ($lifecycle->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $simpleId = ($simple->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $connection->executeStatement(
            "UPDATE workflow_bindings SET definition = jsonb_set(definition, '{rules}', (
                SELECT jsonb_agg(rule ORDER BY position)
                FROM jsonb_array_elements(definition->'rules') WITH ORDINALITY AS rules(rule, position)
                WHERE rule->>'id' <> 'unplanned-child'
            )) WHERE project_id = ?",
            [$lifecycleId],
        );
        $old = $this->definition($connection, $lifecycleId);
        self::assertNotContains('unplanned-child', array_column($old['rules'], 'id'));
        $simpleBefore = $this->definition($connection, $simpleId);

        $this->migrate($connection);
        $this->migrate($connection);

        $shipped = $this->service(ShippedTemplates::class)->source('lifecycle');
        self::assertContains('unplanned-child', array_column($shipped['rules'], 'id'));
        self::assertEquals($shipped, $this->definition($connection, $lifecycleId));
        self::assertEquals($simpleBefore, $this->definition($connection, $simpleId));
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261008022512($connection, new NullLogger());
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
