<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261009145806;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261009145806.php';

final class WorkflowEpicBranchMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_each_copy_gets_the_pattern_of_its_project_and_a_second_run_changes_nothing(): void
    {
        self::bootKernel();
        $custom = $this->bound('epic-branch-custom', 'feature/epic-{number}');
        $padded = $this->bound('epic-branch-padded', '  release/{number}  ');
        $blank = $this->bound('epic-branch-blank', '  ');
        $none = $this->bound('epic-branch-none', null);
        $default = $this->bound('epic-branch-default', BoardAutomationSettings::DEFAULT_EPIC_BRANCH_PATTERN);
        $noRow = $this->bound('epic-branch-no-row', false);
        $kept = $this->bound('epic-branch-kept', 'feature/{number}');
        $connection = $this->em()->getConnection();
        $this->setEpicBranch($connection, $kept, 'own/{number}');
        $this->removeEpicBranch($connection, $custom, $padded, $blank, $none, $default, $noRow);
        $ruleCounts = array_map(fn (string $id): int => \count($this->definition($connection, $id)['rules']), [$custom, $kept]);

        $this->migrate($connection);
        $this->migrate($connection);

        self::assertSame('feature/epic-{number}', $this->epicBranch($connection, $custom));
        self::assertSame('release/{number}', $this->epicBranch($connection, $padded));
        self::assertNull($this->epicBranch($connection, $blank));
        self::assertNull($this->epicBranch($connection, $none));
        self::assertSame('epic/{number}', $this->epicBranch($connection, $default));
        self::assertSame('epic/{number}', $this->epicBranch($connection, $noRow));
        self::assertSame('own/{number}', $this->epicBranch($connection, $kept));
        self::assertSame($ruleCounts, array_map(fn (string $id): int => \count($this->definition($connection, $id)['rules']), [$custom, $kept]));
    }

    /** @param string|false|null $pattern false stores no settings row */
    private function bound(string $name, string|false|null $pattern): string
    {
        $project = $this->workflowProject($name);
        $this->bindHandler()(new BindWorkflowTemplateCommand($project, 'simple', []));
        if (false !== $pattern) {
            $this->em()->persist(new BoardAutomationSettings($project, epicBranchPattern: $pattern));
        }
        $this->em()->flush();

        return $this->idOf($project);
    }

    private function idOf(Project $project): string
    {
        return ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
    }

    private function setEpicBranch(Connection $connection, string $projectId, string $value): void
    {
        $connection->executeStatement("UPDATE workflow_bindings SET definition = definition || jsonb_build_object('epicBranch', CAST(? AS TEXT)) WHERE project_id = ?", [$value, $projectId]);
    }

    private function removeEpicBranch(Connection $connection, string ...$projectIds): void
    {
        foreach ($projectIds as $projectId) {
            $connection->executeStatement("UPDATE workflow_bindings SET definition = definition - 'epicBranch' WHERE project_id = ?", [$projectId]);
        }
    }

    private function epicBranch(Connection $connection, string $projectId): ?string
    {
        $value = $this->definition($connection, $projectId)['epicBranch'] ?? null;
        self::assertTrue(null === $value || \is_string($value));

        return $value;
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261009145806($connection, new NullLogger());
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
