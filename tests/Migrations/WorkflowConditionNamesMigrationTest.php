<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261009082941;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261009082941.php';

final class WorkflowConditionNamesMigrationTest extends KernelTestCase
{
    use ActionScenario;

    private const array OLD_NAMES = [
        'card.blocker.open' => 'card.has_open_blocker',
        'card.parent.exists' => 'card.is_child',
        'card.children.exist' => 'card.has_children',
        'card.children.finished' => 'card.children_finished',
        'card.children.merged_into_epic_branch' => 'card.child_merged_into_epic_branch',
        'card.document.linked' => 'card.document',
        'card.document.approved' => 'card.document_approved',
        'card.document.changes_requested' => 'card.document_changes_requested',
        'card.parent.document.approved' => 'parent.document_approved',
        'card.parent.in_slot' => 'parent.in_slot',
        'card.pr.linked' => 'pr.linked',
        'card.pr.all_finished_one_merged' => 'pr.all_finished_one_merged',
        'card.run.work_active' => 'run.work_active',
        'card.run.worker_active' => 'run.worker_active',
        'card.run.last_refusal' => 'run.last_refusal',
        'card.parent.run.active' => 'parent.work_active',
    ];

    public function test_a_binding_with_the_old_condition_names_gets_the_shipped_copy_and_a_second_run_changes_nothing(): void
    {
        self::bootKernel();
        $lifecycle = $this->workflowProject('migration-condition-names-lifecycle');
        $this->bindLifecycle($lifecycle);
        $simple = $this->workflowProject('migration-condition-names-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($simple, 'simple', []));
        $ids = [
            'lifecycle' => ($lifecycle->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122(),
            'simple' => ($simple->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122(),
        ];
        $connection = $this->em()->getConnection();
        $shipped = $this->service(ShippedTemplates::class);
        foreach ($ids as $key => $projectId) {
            $old = self::withOldNames($shipped->source($key));
            $connection->executeStatement(
                'UPDATE workflow_bindings SET definition = CAST(? AS JSONB) WHERE project_id = ?',
                [json_encode($old, \JSON_THROW_ON_ERROR), $projectId],
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
    private static function withOldNames(array $source): array
    {
        $json = json_encode($source, \JSON_THROW_ON_ERROR);
        foreach (self::OLD_NAMES as $new => $old) {
            $json = str_replace('"'.$new.'":', '"'.$old.'":', $json);
        }
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261009082941($connection, new NullLogger());
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
