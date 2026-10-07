<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261006202546;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261006202546.php';

final class LifecycleEpicPullRequestMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_binding_with_no_epic_pull_request_rule_gets_the_shipped_copy_and_a_second_run_changes_nothing(): void
    {
        self::bootKernel();
        $lifecycle = $this->workflowProject('migration-epic-pull-request');
        $this->bindLifecycle($lifecycle);
        $simple = $this->workflowProject('migration-epic-pull-request-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($simple, 'simple', []));
        $lifecycleId = ($lifecycle->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $simpleId = ($simple->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $connection->executeStatement(
            "UPDATE workflow_bindings SET definition = (
                SELECT jsonb_set(definition, '{rules}', jsonb_agg(rule ORDER BY position))
                FROM jsonb_array_elements(definition->'rules') WITH ORDINALITY AS rules(rule, position)
                WHERE rule->>'id' NOT IN ('epic-open-pull-request', 'epic-preview')
            ) WHERE project_id = ?",
            [$lifecycleId],
        );
        $simpleBefore = $this->definition($connection, $simpleId);
        self::assertNotEquals($this->service(ShippedTemplates::class)->source('lifecycle'), $this->definition($connection, $lifecycleId));

        $this->migrate($connection);
        $this->migrate($connection);

        self::assertEquals($this->service(ShippedTemplates::class)->source('lifecycle'), $this->definition($connection, $lifecycleId));
        self::assertEquals($simpleBefore, $this->definition($connection, $simpleId));
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261006202546($connection, new NullLogger());
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
