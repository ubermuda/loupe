<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Condition;

use App\Module\Board\Workflow\Condition\CardDocument;
use App\Module\Workflow\Condition\Conditions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ConditionCatalogueTest extends KernelTestCase
{
    private const array SOURCES = [
        'card.blocker.open' => 'workflow.source.board',
        'card.children.exist' => 'workflow.source.board',
        'card.children.finished' => 'workflow.source.board',
        'card.children.merged_into_epic_branch' => 'workflow.source.board',
        'card.discovery.requested' => 'workflow.source.readiness',
        'card.document.approved' => 'workflow.source.board',
        'card.document.changes_requested' => 'workflow.source.board',
        'card.document.linked' => 'workflow.source.board',
        'card.in_slot' => 'workflow.source.board',
        'card.parent.document.approved' => 'workflow.source.board',
        'card.parent.exists' => 'workflow.source.board',
        'card.parent.in_slot' => 'workflow.source.board',
        'card.parent.run.active' => 'workflow.source.bridge',
        'card.pr.all_finished_one_merged' => 'workflow.source.forge',
        'card.pr.linked' => 'workflow.source.forge',
        'card.run.last_refusal' => 'workflow.source.bridge',
        'card.run.work_active' => 'workflow.source.bridge',
        'card.run.worker_active' => 'workflow.source.bridge',
        'card.type' => 'workflow.source.board',
        'pr.approval_covers_head' => 'workflow.source.forge',
        'pr.base_is_epic_branch' => 'workflow.source.forge',
        'pr.base_is_merge_target' => 'workflow.source.forge',
        'pr.behind' => 'workflow.source.forge',
        'pr.changes_requested' => 'workflow.source.forge',
        'pr.checks_failed' => 'workflow.source.forge',
        'pr.checks_passed' => 'workflow.source.forge',
        'pr.conflicting' => 'workflow.source.forge',
        'pr.draft' => 'workflow.source.forge',
        'pr.open' => 'workflow.source.forge',
        'pr.parent_merged' => 'workflow.source.forge',
        'pr.stacked' => 'workflow.source.forge',
        'site_review.check_stale' => 'workflow.source.board',
        'site_review.verdict_unsent' => 'workflow.source.board',
    ];

    /** The keys whose waiting sentence id is not the key with underscores. */
    private const array WAITING_SUFFIXES = [
        'card.blocker.open' => 'card_has_open_blocker',
        'card.children.exist' => 'card_has_children',
        'card.children.finished' => 'card_children_finished',
        'card.children.merged_into_epic_branch' => 'card_child_merged_into_epic_branch',
        'card.discovery.requested' => 'card_discovery_requested',
        'card.document.approved' => 'card_document_approved',
        'card.document.changes_requested' => 'card_document_changes_requested',
        'card.document.linked' => 'card_document',
        'card.parent.document.approved' => 'parent_document_approved',
        'card.parent.exists' => 'card_is_child',
        'card.parent.in_slot' => 'parent_in_slot',
        'card.parent.run.active' => 'parent_work_active',
        'card.pr.all_finished_one_merged' => 'pr_all_finished_one_merged',
        'card.pr.linked' => 'pr_linked',
        'card.run.last_refusal' => 'run_last_refusal',
        'card.run.work_active' => 'run_work_active',
        'card.run.worker_active' => 'run_worker_active',
    ];

    public function test_the_container_registers_exactly_the_catalogue(): void
    {
        $keys = self::catalogueKeys(static::getContainer()->get(Conditions::class));
        sort($keys);

        self::assertSame(array_keys(self::SOURCES), $keys);
    }

    public function test_each_condition_has_a_plain_and_a_negated_english_waiting_sentence(): void
    {
        $conditions = static::getContainer()->get(Conditions::class);
        $translator = static::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        $catalogue = $translator->getCatalogue('en');

        foreach (self::catalogueKeys($conditions) as $key) {
            $suffix = self::WAITING_SUFFIXES[$key] ?? str_replace('.', '_', $key);
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

        foreach (self::catalogueKeys($conditions) as $key) {
            $source = $conditions->get($key)::source();
            self::assertSame(self::SOURCES[$key], $source, $key);
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
