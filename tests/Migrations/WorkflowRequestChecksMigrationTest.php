<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261007182438;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261007182438.php';

final class WorkflowRequestChecksMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_binding_whose_requests_carry_no_checks_gets_the_shipped_copy_and_a_second_run_changes_nothing(): void
    {
        self::bootKernel();
        $lifecycle = $this->workflowProject('migration-request-checks-lifecycle');
        $this->bindLifecycle($lifecycle);
        $simple = $this->workflowProject('migration-request-checks-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($simple, 'simple', []));
        $ids = [
            'lifecycle' => ($lifecycle->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122(),
            'simple' => ($simple->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122(),
        ];
        $connection = $this->em()->getConnection();
        $shipped = $this->service(ShippedTemplates::class);
        foreach ($ids as $key => $projectId) {
            $connection->executeStatement(
                'UPDATE workflow_bindings SET definition = CAST(? AS JSONB) WHERE project_id = ?',
                [json_encode(self::withoutChecks($shipped->source($key)), \JSON_THROW_ON_ERROR), $projectId],
            );
            self::assertNotEquals($shipped->source($key), $this->definition($connection, $projectId));
        }

        $this->migrate($connection);
        $this->migrate($connection);

        foreach ($ids as $key => $projectId) {
            self::assertEquals($shipped->source($key), $this->definition($connection, $projectId));
        }
    }

    /**
     * @param array<mixed> $source
     *
     * @return array<mixed>
     */
    private static function withoutChecks(array $source): array
    {
        self::assertIsArray($source['rules']);
        foreach ($source['rules'] as $index => $rule) {
            self::assertIsArray($rule);
            self::assertIsArray($rule['then']);
            if (\is_array($rule['then']['request'] ?? null)) {
                unset($rule['then']['request']['checks']);
                $source['rules'][$index] = $rule;
            }
        }

        return $source;
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261007182438($connection, new NullLogger());
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
