<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Board\Entity\Card;
use App\Module\Workflow\Entity\WorkflowBinding;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Template\Rule;
use App\Module\Workflow\Template\RuleOrigin;
use App\Module\Workflow\Template\TemplateSource;
use App\Tests\Module\Workflow\WorkflowProjects;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261003140305;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261003140305.php';

final class WorkflowClosedUnmergedRuleDropMigrationTest extends KernelTestCase
{
    use WorkflowProjects;

    private const array MERGED = ['id' => 'merged', 'when' => ['pr.all_finished_one_merged' => []], 'then' => ['move' => ['to' => '@terminal']]];
    private const array CLOSED_UNMERGED = [
        'id' => 'closed-unmerged',
        'when' => ['all' => [['not' => ['card.in_slot' => ['slot' => '@backlog']]], ['pr.all_closed_unmerged' => ['minutes' => 10]]]],
        'then' => ['move' => ['to' => '@backlog']],
    ];

    public function test_a_stored_copy_loses_the_rule_that_reads_the_removed_condition_and_parses_again(): void
    {
        self::bootKernel();
        $stale = $this->binding('stale', [self::MERGED, self::CLOSED_UNMERGED]);
        $current = $this->binding('current', [self::MERGED]);

        $this->migrate();

        $this->em()->clear();
        self::assertEquals([self::MERGED], $this->rulesOf($stale));
        self::assertEquals([self::MERGED], $this->rulesOf($current));
        $template = self::getContainer()->get(TemplateSource::class)->forProject($stale->project->id ?? throw new \LogicException('The project is flushed.'));
        $templateRules = array_filter($template->rulesFor(null), static fn (Rule $rule): bool => RuleOrigin::Template === $rule->origin);
        self::assertSame(['merged'], array_values(array_map(static fn (Rule $rule): string => $rule->id, $templateRules)));
    }

    public function test_the_rule_memory_of_a_dropped_rule_goes_so_its_retry_never_comes_due(): void
    {
        self::bootKernel();
        $binding = $this->binding('memory', [self::MERGED, self::CLOSED_UNMERGED]);
        $card = new Card($binding->project, $this->column($binding->project, 'in-progress'), 'Card', '', 1);
        $this->em()->persist($card);
        foreach (['closed-unmerged', 'merged'] as $ruleId) {
            $state = new WorkflowRuleState($card->id ?? throw new \LogicException('The card is persisted.'), $card->project, $ruleId);
            $state->attempts = 1;
            $state->dueAt = new \DateTimeImmutable('2026-10-02 12:10:00');
            $this->em()->persist($state);
        }
        $this->em()->flush();

        $this->migrate();

        self::assertSame(['merged'], $this->em()->getConnection()->fetchFirstColumn('SELECT rule_id FROM workflow_rule_states WHERE card_id = ?', [(string) $card->id]));
    }

    /** @param list<array<string, mixed>> $rules */
    private function binding(string $name, array $rules): WorkflowBinding
    {
        $binding = new WorkflowBinding($this->workflowProject('migration-'.$name), 'simple', 1, [
            'key' => 'simple',
            'version' => 1,
            'defaultType' => 'feature',
            'types' => [
                ['key' => 'feature', 'label' => 'board.card.type.feature', 'tone' => 'lime'],
                ['key' => 'bug', 'label' => 'board.card.type.bug', 'tone' => 'amber'],
                ['key' => 'security', 'label' => 'board.card.type.security', 'tone' => 'red'],
                ['key' => 'tooling', 'label' => 'board.card.type.tooling', 'tone' => 'neutral'],
                ['key' => 'docs', 'label' => 'board.card.type.docs', 'tone' => 'green'],
                ['key' => 'idea', 'label' => 'board.card.type.idea', 'tone' => 'purple'],
                ['key' => 'epic', 'label' => 'board.card.type.epic', 'tone' => 'blue', 'capabilities' => ['children', 'lane']],
            ],
            'slots' => [],
            'manualMoves' => [['from' => '*', 'to' => '*']],
            'backoffMinutes' => [10, 60, 360],
            'workTimeoutMinutes' => 120,
            'rules' => $rules,
        ]);
        $this->em()->persist($binding);
        $this->em()->flush();

        return $binding;
    }

    /** @return mixed the stored rules of the binding */
    private function rulesOf(WorkflowBinding $binding): mixed
    {
        $definition = $this->em()->getConnection()->fetchOne('SELECT definition FROM workflow_bindings WHERE id = ?', [(string) $binding->id]);
        self::assertIsString($definition);

        return json_decode($definition, true, flags: \JSON_THROW_ON_ERROR)['rules'];
    }

    private function migrate(): void
    {
        $connection = $this->em()->getConnection();
        $migration = new Version20261003140305($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
