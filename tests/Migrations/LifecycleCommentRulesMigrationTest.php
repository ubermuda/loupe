<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261009191142;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261009191142.php';

final class LifecycleCommentRulesMigrationTest extends KernelTestCase
{
    use ActionScenario;

    private const array COMMENT_RULES = ['comment-fix-run', 'comment-stale-approval'];

    public function test_a_copy_without_the_comment_rules_becomes_the_shipped_copy_and_a_second_run_changes_nothing(): void
    {
        self::bootKernel();
        $connection = $this->em()->getConnection();
        $lifecycleId = $this->lifecycleProject('migration-comment-rules');
        $simpleId = $this->simpleProject('migration-comment-rules-simple');
        $this->dropRules($connection, $lifecycleId, self::COMMENT_RULES);
        self::assertNotEquals($this->shipped(), $this->definition($connection, $lifecycleId));
        $simpleBefore = $this->definition($connection, $simpleId);

        $this->migrate($connection);
        self::assertEquals($this->shipped(), $this->definition($connection, $lifecycleId));
        $this->migrate($connection);

        self::assertEquals($this->shipped(), $this->definition($connection, $lifecycleId));
        self::assertEquals($simpleBefore, $this->definition($connection, $simpleId));
    }

    public function test_the_rules_go_after_the_fix_rule_and_a_custom_value_elsewhere_survives(): void
    {
        self::bootKernel();
        $connection = $this->em()->getConnection();
        $projectId = $this->lifecycleProject('migration-comment-rules-custom');
        $this->dropRules($connection, $projectId, self::COMMENT_RULES);
        $connection->executeStatement(
            "UPDATE workflow_bindings SET definition = jsonb_set(definition, '{workTimeoutMinutes}', '17') WHERE project_id = ?",
            [$projectId],
        );

        $this->migrate($connection);

        $definition = $this->definition($connection, $projectId);
        self::assertSame(17, $definition['workTimeoutMinutes']);
        $ids = $this->ruleIds($definition);
        $fix = array_search('fix-in-review', $ids, true);
        self::assertIsInt($fix);
        self::assertSame(self::COMMENT_RULES, \array_slice($ids, $fix + 1, 2));
        self::assertCount(\count($this->ruleIds($this->shipped())), $ids);
    }

    public function test_a_copy_without_the_fix_rule_gets_the_rules_first(): void
    {
        self::bootKernel();
        $connection = $this->em()->getConnection();
        $projectId = $this->lifecycleProject('migration-comment-rules-no-fix');
        $this->dropRules($connection, $projectId, [...self::COMMENT_RULES, 'fix-in-review']);
        $before = $this->ruleIds($this->definition($connection, $projectId));

        $this->migrate($connection);

        self::assertSame([...self::COMMENT_RULES, ...$before], $this->ruleIds($this->definition($connection, $projectId)));
    }

    private function lifecycleProject(string $name): string
    {
        $project = $this->workflowProject($name);
        $this->bindLifecycle($project);

        return ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
    }

    private function simpleProject(string $name): string
    {
        $project = $this->workflowProject($name);
        $this->bindHandler()(new BindWorkflowTemplateCommand($project, 'simple', []));

        return ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
    }

    /** @param list<string> $ruleIds */
    private function dropRules(Connection $connection, string $projectId, array $ruleIds): void
    {
        $connection->executeStatement(
            "UPDATE workflow_bindings SET definition = (
                SELECT jsonb_set(definition, '{rules}', jsonb_agg(rule ORDER BY position))
                FROM jsonb_array_elements(definition->'rules') WITH ORDINALITY AS rules(rule, position)
                WHERE NOT (rule->>'id' = ANY(CAST(? AS TEXT[])))
            ) WHERE project_id = ?",
            ['{'.implode(',', $ruleIds).'}', $projectId],
        );
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261009191142($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @return array<mixed> */
    private function shipped(): array
    {
        return $this->service(ShippedTemplates::class)->source('lifecycle');
    }

    /**
     * @param array<mixed> $definition
     *
     * @return list<mixed>
     */
    private function ruleIds(array $definition): array
    {
        $rules = $definition['rules'] ?? null;
        self::assertIsArray($rules);

        return array_values(array_map(static fn (mixed $rule): mixed => \is_array($rule) ? ($rule['id'] ?? null) : null, $rules));
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
