<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Template;

use App\Module\Workflow\Fact\ChecksState;
use App\Module\Workflow\Fact\DocumentFacts;
use App\Module\Workflow\Fact\Facts;
use App\Module\Workflow\Template\ActionCall;
use App\Module\Workflow\Template\ActionType;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Module\Workflow\Template\Template;
use App\Module\Workflow\Template\TemplateParser;
use App\Module\Workflow\Template\UnknownTemplate;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;

final class ShippedTemplatesTest extends KernelTestCase
{
    public function test_it_lists_the_shipped_templates(): void
    {
        self::assertSame(['lifecycle', 'simple'], $this->shipped()->keys());
    }

    #[DataProvider('unknownKeys')]
    public function test_it_refuses_a_key_that_is_not_shipped(string $key): void
    {
        try {
            $this->shipped()->source($key);
            self::fail('An unknown key must throw.');
        } catch (UnknownTemplate $e) {
            self::assertSame($key, $e->key);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unknownKeys(): iterable
    {
        yield 'unknown' => ['kanban'];
        yield 'path' => ['../workflows/lifecycle'];
    }

    public function test_both_templates_pass_the_parser(): void
    {
        foreach ($this->shipped()->keys() as $key) {
            self::assertSame($key, $this->template($key)->key);
        }
    }

    public function test_simple_has_no_slot_and_allows_every_manual_move(): void
    {
        $simple = $this->template('simple');

        self::assertSame([], $simple->slots);
        self::assertCount(1, $simple->manualMoves);
        self::assertSame('*', $simple->manualMoves[0]->from);
        self::assertSame('*', $simple->manualMoves[0]->to);
        self::assertSame(['merged', 'closed-unmerged'], array_map(static fn ($rule) => $rule->id, $simple->rulesFor(null)));
    }

    public function test_every_slot_label_has_an_english_string(): void
    {
        $translator = static::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        $catalogue = $translator->getCatalogue('en');

        $labels = [];
        foreach ($this->shipped()->keys() as $key) {
            foreach ($this->template($key)->slots as $slot) {
                $labels[] = $slot->label;
            }
        }

        self::assertNotEmpty($labels);
        foreach ($labels as $label) {
            self::assertTrue($catalogue->defines($label), \sprintf('"%s" has no English string.', $label));
        }
    }

    public function test_an_approved_tech_design_with_no_blocker_moves_to_implementation(): void
    {
        $facts = FactsMother::facts(card: FactsMother::card(slot: 'tech-design', documents: [self::approved('design')]));

        self::assertContainsEquals(new ActionCall(ActionType::Move, ['to' => 'implementation']), $this->actions($facts));
    }

    public function test_an_approved_tech_design_with_an_open_blocker_does_not_move(): void
    {
        $facts = FactsMother::facts(card: FactsMother::card(slot: 'tech-design', hasOpenBlocker: true, documents: [self::approved('design')]));

        $actions = $this->actions($facts);
        self::assertNotEmpty($this->lifecycle()->rulesFor('tech-design'));
        self::assertSame([], array_values(array_filter($actions, static fn (ActionCall $action): bool => ActionType::Move === $action->type)));
    }

    public function test_a_reviewable_pull_request_moves_to_in_review(): void
    {
        $facts = FactsMother::facts(
            card: FactsMother::card(slot: 'implementation'),
            pullRequest: FactsMother::pullRequest(checks: ChecksState::Passed),
        );

        self::assertContainsEquals(new ActionCall(ActionType::Move, ['to' => 'in-review']), $this->actions($facts));
    }

    public function test_a_draft_in_review_moves_back_to_implementation(): void
    {
        $facts = FactsMother::facts(
            card: FactsMother::card(slot: 'in-review'),
            pullRequest: FactsMother::pullRequest(draft: true, checks: ChecksState::Passed),
        );

        self::assertContainsEquals(new ActionCall(ActionType::Move, ['to' => 'implementation']), $this->actions($facts));
    }

    public function test_a_ready_pull_request_asks_for_the_merge_write(): void
    {
        $ready = FactsMother::facts(
            card: FactsMother::card(slot: 'in-review'),
            pullRequest: FactsMother::pullRequest(checks: ChecksState::Passed, approvalsCoveringHead: 1),
        );
        $merge = new ActionCall(ActionType::ForgeWrite, ['write' => 'merge', 'fallback' => 'merge']);

        self::assertContainsEquals($merge, $this->actions($ready));

        $unapproved = FactsMother::facts(
            card: FactsMother::card(slot: 'in-review'),
            pullRequest: FactsMother::pullRequest(checks: ChecksState::Passed),
        );
        self::assertNotContainsEquals($merge, $this->actions($unapproved));
    }

    public function test_an_epic_with_a_draft_pull_request_stays_in_implementation(): void
    {
        $toReview = new ActionCall(ActionType::Move, ['to' => 'in-review']);
        $epic = FactsMother::card(slot: 'implementation', type: 'epic', childCount: 2);

        $ready = FactsMother::facts(card: $epic, pullRequest: FactsMother::pullRequest());
        self::assertContainsEquals($toReview, $this->actions($ready));

        $draft = FactsMother::facts(card: $epic, pullRequest: FactsMother::pullRequest(draft: true));
        self::assertNotContainsEquals($toReview, $this->actions($draft));
    }

    public function test_the_ready_pull_request_of_an_epic_asks_for_the_merge_write(): void
    {
        $facts = FactsMother::facts(
            card: FactsMother::card(slot: 'in-review', type: 'epic', childCount: 2),
            pullRequest: FactsMother::pullRequest(checks: ChecksState::Passed, approvalsCoveringHead: 1),
        );

        self::assertContainsEquals(new ActionCall(ActionType::ForgeWrite, ['write' => 'merge', 'fallback' => 'merge']), $this->actions($facts));
    }

    private function shipped(): ShippedTemplates
    {
        return static::getContainer()->get(ShippedTemplates::class);
    }

    private function template(string $key): Template
    {
        return static::getContainer()->get(TemplateParser::class)->parse($this->shipped()->source($key));
    }

    private function lifecycle(): Template
    {
        return $this->template('lifecycle');
    }

    /** @return list<ActionCall> the actions of every Lifecycle rule that matches the facts */
    private function actions(Facts $facts): array
    {
        $actions = [];
        foreach ($this->lifecycle()->rulesFor($facts->card->slot) as $rule) {
            if ($rule->when->evaluate($facts)) {
                $actions[] = $rule->then;
            }
        }

        return $actions;
    }

    private static function approved(string $tag): DocumentFacts
    {
        return new DocumentFacts(tags: [$tag], status: 'approved');
    }
}
