<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Condition;

use App\Module\Workflow\Condition\Conditions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;

final class ConditionCatalogueTest extends KernelTestCase
{
    public function test_the_container_registers_exactly_the_catalogue(): void
    {
        $keys = static::getContainer()->get(Conditions::class)->keys();
        sort($keys);

        self::assertSame([
            'card.children_finished',
            'card.document_approved',
            'card.document_changes_requested',
            'card.has_children',
            'card.has_open_blocker',
            'card.in_slot',
            'card.is_child',
            'card.type',
            'pr.all_finished_one_merged',
            'pr.approval_covers_head',
            'pr.base_is_epic_branch',
            'pr.base_is_merge_target',
            'pr.behind',
            'pr.changes_requested',
            'pr.checks_failed',
            'pr.checks_passed',
            'pr.conflicting',
            'pr.draft',
            'pr.linked',
            'pr.open',
            'pr.parent_merged',
            'pr.stacked',
            'run.last_refusal',
            'run.work_active',
        ], $keys);
    }

    public function test_each_condition_has_a_plain_and_a_negated_english_waiting_sentence(): void
    {
        $conditions = static::getContainer()->get(Conditions::class);
        $translator = static::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        $catalogue = $translator->getCatalogue('en');

        foreach ($conditions->keys() as $key) {
            $suffix = str_replace('.', '_', $key);
            foreach (['workflow.waiting.'.$suffix, 'workflow.waiting.not.'.$suffix] as $message) {
                self::assertTrue($catalogue->defines($message), \sprintf('"%s" has no English string.', $message));
            }
        }
    }
}
