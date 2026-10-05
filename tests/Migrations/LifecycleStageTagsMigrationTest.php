<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Tag;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261005184706;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261005184706.php';

final class LifecycleStageTagsMigrationTest extends KernelTestCase
{
    use ActionScenario;

    /** @var array<string, Tag> */
    private array $tags = [];

    public function test_a_product_design_loses_the_design_tag_and_every_stage_tag_takes_its_new_name(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-stage-tags');
        $product = $this->document($project, ['product', 'design']);
        $tech = $this->document($project, ['design', 'decisions']);
        $archived = $this->document($project, ['product']);
        $archived->archivedAt = new \DateTimeImmutable('2026-10-01 12:00:00');
        $this->em()->flush();

        $this->migrate();
        $this->migrate();

        self::assertSame(['product-design'], $this->tagNames($product));
        self::assertSame(['decisions', 'tech-design'], $this->tagNames($tech));
        self::assertSame(['product-design'], $this->tagNames($archived));
        self::assertSame(['decisions', 'product-design', 'tech-design'], $this->projectTagNames($project));
    }

    public function test_a_design_tag_that_only_a_product_design_carried_is_deleted(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-stage-tags-unused');
        $product = $this->document($project, ['product', 'design']);

        $this->migrate();

        self::assertSame(['product-design'], $this->tagNames($product));
        self::assertSame(['product-design'], $this->projectTagNames($project));
    }

    public function test_a_project_that_already_has_the_new_tag_keeps_one_tag_row_with_every_document(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-stage-tags-merge');
        $old = $this->document($project, ['design']);
        $new = $this->document($project, ['tech-design']);
        $both = $this->document($project, ['design', 'tech-design']);

        $this->migrate();

        self::assertSame(['tech-design'], $this->projectTagNames($project));
        foreach ([$old, $new, $both] as $document) {
            self::assertSame(['tech-design'], $this->tagNames($document));
        }
    }

    public function test_the_stored_truth_of_the_two_approved_rules_resets_and_other_rules_keep_theirs(): void
    {
        self::bootKernel();
        $lifecycle = $this->workflowProject('migration-stage-tags-rules');
        $this->bindLifecycle($lifecycle);
        $simple = $this->workflowProject('migration-stage-tags-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($simple, 'simple', []));
        $card = $this->card($lifecycle, 'tech-design');
        $other = $this->card($simple, 'tech-design');
        foreach ([[$card, 'product-design-approved'], [$card, 'tech-design-approved'], [$card, 'implement'], [$other, 'tech-design-approved']] as [$owner, $ruleId]) {
            $state = $this->state($owner, $ruleId);
            $state->truth = true;
            $this->em()->persist($state);
        }
        $this->em()->flush();
        $connection = $this->em()->getConnection();
        $connection->executeStatement(
            "UPDATE workflow_bindings SET definition = REPLACE(REPLACE(definition::text, '\"tag\": \"tech-design\"', '\"tag\": \"design\"'), '\"tag\": \"product-design\"', '\"tag\": \"product\"')::jsonb WHERE project_id = ?",
            [($lifecycle->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122()],
        );
        self::assertNotEquals($this->shippedLifecycle(), $this->definition($lifecycle));

        $this->migrate();

        self::assertEquals($this->shippedLifecycle(), $this->definition($lifecycle));
        self::assertSame(['implement' => true, 'product-design-approved' => false, 'tech-design-approved' => false], $this->truths($lifecycle));
        self::assertSame(['tech-design-approved' => true], $this->truths($simple));
    }

    private function migrate(): void
    {
        $connection = $this->em()->getConnection();
        $migration = new Version20261005184706($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        $this->em()->clear();
    }

    /** @param list<string> $tagNames */
    private function document(Project $project, array $tagNames): Document
    {
        $document = new Document($project->owner, $project, 'Design');
        foreach ($tagNames as $name) {
            $key = $project->id.'/'.$name;
            if (!isset($this->tags[$key])) {
                $this->tags[$key] = new Tag($project, $name);
                $this->em()->persist($this->tags[$key]);
            }
            $document->tags->add($this->tags[$key]);
        }
        $this->em()->persist($document);
        $this->em()->flush();

        return $document;
    }

    /** @return list<string> */
    private function tagNames(Document $document): array
    {
        /* @var list<string> */
        return $this->em()->getConnection()->fetchFirstColumn(
            'SELECT t.name FROM document_tags d JOIN tags t ON t.id = d.tag_id WHERE d.document_id = ? ORDER BY t.name',
            [($document->id ?? throw new \LogicException('The document is not flushed.'))->toRfc4122()],
        );
    }

    /** @return list<string> */
    private function projectTagNames(Project $project): array
    {
        /* @var list<string> */
        return $this->em()->getConnection()->fetchFirstColumn('SELECT name FROM tags WHERE project_id = ? ORDER BY name', [$this->projectId($project)]);
    }

    /** @return array<string, bool> */
    private function truths(Project $project): array
    {
        $truths = [];
        foreach ($this->em()->getConnection()->fetchAllAssociative('SELECT rule_id, truth FROM workflow_rule_states WHERE project_id = ? ORDER BY rule_id', [$this->projectId($project)]) as $row) {
            self::assertIsString($row['rule_id']);
            $truths[$row['rule_id']] = (bool) $row['truth'];
        }

        return $truths;
    }

    /** @return array<mixed> */
    private function definition(Project $project): array
    {
        $definition = $this->em()->getConnection()->fetchOne('SELECT definition FROM workflow_bindings WHERE project_id = ?', [$this->projectId($project)]);
        self::assertIsString($definition);
        $decoded = json_decode($definition, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return array<mixed> */
    private function shippedLifecycle(): array
    {
        return $this->service(ShippedTemplates::class)->source('lifecycle');
    }

    private function projectId(Project $project): string
    {
        return ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
    }
}
