<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261009183807;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261009183807.php';

final class LifecycleAgentReviewMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_binding_without_the_agent_review_rules_gets_the_shipped_copy_and_a_second_run_changes_nothing(): void
    {
        self::bootKernel();
        $lifecycle = $this->workflowProject('migration-agent-review');
        $this->bindLifecycle($lifecycle);
        $simple = $this->workflowProject('migration-agent-review-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($simple, 'simple', []));
        $lifecycleId = ($lifecycle->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $simpleId = ($simple->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $connection->executeStatement(
            "UPDATE workflow_bindings SET definition = jsonb_set(definition, '{rules}', (
                SELECT jsonb_agg(rule ORDER BY position)
                FROM jsonb_array_elements(definition->'rules') WITH ORDINALITY AS rules(rule, position)
                WHERE rule->>'id' NOT LIKE '%agent-review%' AND rule->>'id' <> 'review-ready'
            )) WHERE project_id = ?",
            [$lifecycleId],
        );
        self::assertNotEquals($this->service(ShippedTemplates::class)->source('lifecycle'), $this->definition($connection, $lifecycleId));
        $simpleBefore = $this->definition($connection, $simpleId);

        $this->migrate($connection);
        $this->migrate($connection);

        self::assertEquals($this->service(ShippedTemplates::class)->source('lifecycle'), $this->definition($connection, $lifecycleId));
        self::assertEquals($simpleBefore, $this->definition($connection, $simpleId));
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261009183807($connection, new NullLogger());
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
