<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Condition;

use App\Module\Workflow\Condition\CardDocument;
use App\Module\Workflow\Condition\Conditions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ConditionCatalogueTest extends KernelTestCase
{
    public function test_the_container_registers_exactly_the_catalogue(): void
    {
        $keys = self::catalogueKeys(static::getContainer()->get(Conditions::class));
        sort($keys);

        self::assertSame([
            'card.children_finished',
            'card.document',
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

        foreach (self::catalogueKeys($conditions) as $key) {
            $suffix = str_replace('.', '_', $key);
            foreach (['workflow.waiting.'.$suffix, 'workflow.waiting.not.'.$suffix] as $message) {
                self::assertTrue($catalogue->defines($message), \sprintf('"%s" has no English string.', $message));
            }
        }
    }

    public function test_each_condition_names_the_module_whose_data_it_reads(): void
    {
        $conditions = static::getContainer()->get(Conditions::class);
        $translator = static::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        $catalogue = $translator->getCatalogue('en');
        $sources = ['card' => 'workflow.source.board', 'pr' => 'workflow.source.forge', 'run' => 'workflow.source.bridge'];

        foreach (self::catalogueKeys($conditions) as $key) {
            $source = $conditions->get($key)::source();
            self::assertSame($sources[explode('.', $key)[0]], $source, $key);
            self::assertTrue($catalogue->defines($source), \sprintf('"%s" has no English string.', $source));
        }
    }

    public function test_a_document_status_reads_as_its_label_in_the_waiting_sentence(): void
    {
        $translator = static::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        $params = ['tag' => 'design', 'status' => 'in-review'];

        self::assertSame('Waiting: the card has no design document with the status "In review".', new CardDocument()->waitingFor($params)->trans($translator));
        self::assertSame('Waiting: the card has a design document with the status "In review".', new CardDocument()->waitingFor($params, negated: true)->trans($translator));
    }

    /** @return list<string> the keys of the shipped conditions, without the ones the test container adds */
    private static function catalogueKeys(Conditions $conditions): array
    {
        return array_values(array_filter($conditions->keys(), static fn (string $key): bool => !str_starts_with($key, 'test.')));
    }
}
