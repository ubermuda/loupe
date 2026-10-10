<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261010200258;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261010200258.php';

final class LifecycleAgentReviewWaitsMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_binding_whose_review_rules_do_not_wait_gets_the_shipped_copy_and_keeps_its_epic_branch(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-agent-review-waits');
        $this->bindLifecycle($project);
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $connection->executeStatement(
            "UPDATE workflow_bindings SET definition = jsonb_set(definition, '{rules}', (
                SELECT jsonb_agg(CASE
                    WHEN rule->>'id' IN ('agent-review', 'agent-review-in-review')
                    THEN jsonb_set(rule, '{when,all}', (rule->'when'->'all') - 3 - 3 - 3 - 3)
                    ELSE rule
                END ORDER BY position)
                FROM jsonb_array_elements(definition->'rules') WITH ORDINALITY AS rules(rule, position)
            )) || '{\"epicBranch\": \"feature/epic-{number}\"}' WHERE project_id = ?",
            [$projectId],
        );
        $shipped = $this->service(ShippedTemplates::class)->source('lifecycle');
        $expected = [...$shipped, 'epicBranch' => 'feature/epic-{number}'];
        self::assertNotEquals($expected, $this->definition($connection, $projectId));

        $this->migrate($connection);
        $this->migrate($connection);

        self::assertEquals($expected, $this->definition($connection, $projectId));
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261010200258($connection, new NullLogger());
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
