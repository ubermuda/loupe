<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261003185202;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261003185202.php';

final class WorkflowTemplateRefreshMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_binding_with_an_old_copy_gets_the_shipped_copy_of_its_template(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-stale');
        $this->bindHandler()(new BindWorkflowTemplateCommand($project, 'simple', []));
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $connection->executeStatement("UPDATE workflow_bindings SET template_version = 0, definition = jsonb_set(definition, '{rules}', '[]'::jsonb) WHERE project_id = ?", [$projectId]);

        $migration = new Version20261003185202($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        $row = $connection->fetchAssociative('SELECT template_version, definition FROM workflow_bindings WHERE project_id = ?', [$projectId]);
        self::assertIsArray($row);
        self::assertIsString($row['definition']);
        $source = $this->service(ShippedTemplates::class)->source('simple');
        self::assertEquals($source, json_decode($row['definition'], true));
        self::assertSame($source['version'] ?? null, (int) $row['template_version']);
    }
}
