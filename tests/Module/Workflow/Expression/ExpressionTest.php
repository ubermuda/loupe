<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Expression;

use App\Module\Board\Workflow\BlockerFacts;
use App\Module\Board\Workflow\CardTypeFacts;
use App\Module\Board\Workflow\ParentFacts;
use App\Module\Workflow\Condition\CardHasOpenBlocker;
use App\Module\Workflow\Condition\CardHasType;
use App\Module\Workflow\Condition\CardIsChild;
use App\Module\Workflow\Condition\PullRequestChecksFailed;
use App\Module\Workflow\Contract\ChecksState;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Unreadable;
use App\Module\Workflow\Contract\UnreadableKind;
use App\Module\Workflow\Expression\AllOf;
use App\Module\Workflow\Expression\AnyOf;
use App\Module\Workflow\Expression\ConditionLeaf;
use App\Module\Workflow\Expression\MissingConditionLeaf;
use App\Module\Workflow\Expression\Not;
use App\Tests\Module\Workflow\Fact\FactsMother;
use App\Tests\Module\Workflow\Fact\ProvidedFacts;
use App\Tests\Module\Workflow\Fact\ProvidedFactsReady;
use App\Tests\Module\Workflow\Fact\UnprovidedFactsReady;
use PHPUnit\Framework\TestCase;

final class ExpressionTest extends TestCase
{
    public function test_a_leaf_evaluates_its_condition_with_its_parameters(): void
    {
        $epic = new ConditionLeaf(new CardHasType(), ['type' => 'epic']);

        self::assertTrue($epic->evaluate(FactsMother::facts(card: FactsMother::card(type: 'epic'))));
        self::assertFalse($epic->evaluate(FactsMother::facts()));
    }

    public function test_an_empty_all_of_is_true(): void
    {
        $all = new AllOf([]);

        self::assertTrue($all->evaluate(FactsMother::facts()));
        self::assertNull($all->firstFalseLeaf(FactsMother::facts()));
    }

    public function test_not_over_an_empty_all_of_is_false_with_no_leaf(): void
    {
        $not = new Not(new AllOf([]));

        self::assertFalse($not->evaluate(FactsMother::facts()));
        self::assertNull($not->firstFalseLeaf(FactsMother::facts()));
    }

    public function test_all_of_gives_its_first_false_leaf(): void
    {
        $child = new ConditionLeaf(new CardIsChild(), []);
        $blocker = new ConditionLeaf(new CardHasOpenBlocker(), []);
        $all = new AllOf([$child, $blocker]);

        $facts = FactsMother::facts(card: FactsMother::card(isChild: true));
        self::assertFalse($all->evaluate($facts));
        self::assertSame($blocker, $all->firstFalseLeaf($facts)?->leaf);

        $facts = FactsMother::facts(card: FactsMother::card(hasOpenBlocker: true, isChild: true));
        self::assertTrue($all->evaluate($facts));
        self::assertNull($all->firstFalseLeaf($facts));
    }

    public function test_any_of_is_true_when_one_child_is_true_and_else_gives_the_first_leaf(): void
    {
        $child = new ConditionLeaf(new CardIsChild(), []);
        $blocker = new ConditionLeaf(new CardHasOpenBlocker(), []);
        $any = new AnyOf([$child, $blocker]);

        $facts = FactsMother::facts(card: FactsMother::card(hasOpenBlocker: true));
        self::assertTrue($any->evaluate($facts));
        self::assertNull($any->firstFalseLeaf($facts));

        $facts = FactsMother::facts();
        self::assertFalse($any->evaluate($facts));
        self::assertSame($child, $any->firstFalseLeaf($facts)?->leaf);
    }

    public function test_not_gives_the_leaf_inside_when_that_leaf_is_true(): void
    {
        $blocker = new ConditionLeaf(new CardHasOpenBlocker(), []);
        $not = new Not($blocker);

        $blocked = FactsMother::facts(card: FactsMother::card(hasOpenBlocker: true));
        self::assertFalse($not->evaluate($blocked));
        self::assertSame($blocker, $not->firstFalseLeaf($blocked)?->leaf);

        self::assertTrue($not->evaluate(FactsMother::facts()));
        self::assertNull($not->firstFalseLeaf(FactsMother::facts()));
    }

    public function test_a_plain_leaf_blocks_with_the_plain_sentence(): void
    {
        $blocker = new ConditionLeaf(new CardHasOpenBlocker(), []);

        $blocking = $blocker->firstFalseLeaf(FactsMother::facts());

        self::assertNotNull($blocking);
        self::assertSame($blocker, $blocking->leaf);
        self::assertFalse($blocking->negated);
        self::assertSame('workflow.waiting.card_has_open_blocker', $blocking->waitingFor()->getMessage());
    }

    public function test_a_not_leaf_blocks_with_the_negated_sentence(): void
    {
        $failed = new ConditionLeaf(new PullRequestChecksFailed(), []);
        $not = new Not($failed);

        $blocking = $not->firstFalseLeaf(FactsMother::facts(pullRequest: FactsMother::pullRequest(checks: ChecksState::Failed)));

        self::assertNotNull($blocking);
        self::assertSame($failed, $blocking->leaf);
        self::assertTrue($blocking->negated);
        self::assertSame('workflow.waiting.not.pr_checks_failed', $blocking->waitingFor()->getMessage());
    }

    public function test_a_double_not_restores_the_plain_polarity(): void
    {
        $blocker = new ConditionLeaf(new CardHasOpenBlocker(), []);
        $notNot = new Not(new Not($blocker));

        $blocking = $notNot->firstFalseLeaf(FactsMother::facts());

        self::assertNotNull($blocking);
        self::assertSame($blocker, $blocking->leaf);
        self::assertFalse($blocking->negated);
        self::assertSame('workflow.waiting.card_has_open_blocker', $blocking->waitingFor()->getMessage());
    }

    public function test_not_over_all_of_blocks_with_the_negated_sentence(): void
    {
        $child = new ConditionLeaf(new CardIsChild(), []);
        $blocker = new ConditionLeaf(new CardHasOpenBlocker(), []);
        $not = new Not(new AllOf([$child, $blocker]));

        $blocking = $not->firstFalseLeaf(FactsMother::facts(card: FactsMother::card(hasOpenBlocker: true, isChild: true)));

        self::assertNotNull($blocking);
        self::assertSame($child, $blocking->leaf);
        self::assertTrue($blocking->negated);
        self::assertSame('workflow.waiting.not.card_is_child', $blocking->waitingFor()->getMessage());
    }

    public function test_not_over_any_of_blocks_with_the_negated_sentence(): void
    {
        $child = new ConditionLeaf(new CardIsChild(), []);
        $blocker = new ConditionLeaf(new CardHasOpenBlocker(), []);
        $not = new Not(new AnyOf([$child, $blocker]));

        $blocking = $not->firstFalseLeaf(FactsMother::facts(card: FactsMother::card(hasOpenBlocker: true)));

        self::assertNotNull($blocking);
        self::assertSame($blocker, $blocking->leaf);
        self::assertTrue($blocking->negated);
        self::assertSame('workflow.waiting.not.card_has_open_blocker', $blocking->waitingFor()->getMessage());
    }

    public function test_a_not_inside_all_of_keeps_the_negation(): void
    {
        $child = new ConditionLeaf(new CardIsChild(), []);
        $blocker = new ConditionLeaf(new CardHasOpenBlocker(), []);
        $all = new AllOf([$child, new Not($blocker)]);

        $blocking = $all->firstFalseLeaf(FactsMother::facts(card: FactsMother::card(hasOpenBlocker: true, isChild: true)));

        self::assertNotNull($blocking);
        self::assertSame($blocker, $blocking->leaf);
        self::assertTrue($blocking->negated);
    }

    public function test_a_leaf_reads_what_its_condition_reads(): void
    {
        self::assertSame([CardTypeFacts::class], new ConditionLeaf(new CardHasType(), ['type' => 'epic'])->reads());
    }

    public function test_a_composite_reads_the_keys_of_its_children_once_in_first_seen_order(): void
    {
        $child = new ConditionLeaf(new CardIsChild(), []);
        $blocker = new ConditionLeaf(new CardHasOpenBlocker(), []);
        $checks = new ConditionLeaf(new PullRequestChecksFailed(), []);

        $expression = new AllOf([$blocker, new AnyOf([$child, new Not($blocker)]), new Not($checks), $child]);

        self::assertSame([BlockerFacts::class, ParentFacts::class, EngineFact::PullRequest], $expression->reads());
        self::assertSame([ParentFacts::class, BlockerFacts::class], new AnyOf([$child, $blocker, $child])->reads());
        self::assertSame([EngineFact::PullRequest], new Not($checks)->reads());
        self::assertSame([], new AllOf([])->reads());
    }

    public function test_a_composite_reads_a_facts_class_once_beside_the_fact_keys(): void
    {
        $provided = new ConditionLeaf(new ProvidedFactsReady(), []);
        $child = new ConditionLeaf(new CardIsChild(), []);

        self::assertSame([ProvidedFacts::class, ParentFacts::class], new AllOf([$provided, new Not($provided), $child, $provided])->reads());
    }

    public function test_a_leaf_over_readable_provided_facts_evaluates_them(): void
    {
        $provided = new ConditionLeaf(new ProvidedFactsReady(), []);
        $facts = FactsMother::facts(provided: [ProvidedFacts::class => new ProvidedFacts(ready: true)]);

        self::assertNull($provided->unreadable($facts));
        self::assertTrue($provided->evaluate($facts));
    }

    public function test_an_unreadable_leaf_makes_every_expression_above_it_unreadable(): void
    {
        $failed = new Unreadable(UnreadableKind::Failed, 'workflow.source.board');
        $facts = FactsMother::facts(card: FactsMother::card(isChild: true), provided: [ProvidedFacts::class => $failed]);
        $provided = new ConditionLeaf(new ProvidedFactsReady(), []);
        $child = new ConditionLeaf(new CardIsChild(), []);

        self::assertSame($failed, $provided->unreadable($facts));
        self::assertSame($failed, new Not($provided)->unreadable($facts));
        self::assertSame($failed, new Not(new Not($provided))->unreadable($facts));
        self::assertSame($failed, new AllOf([$child, $provided])->unreadable($facts));
        self::assertSame($failed, new AnyOf([$child, $provided])->unreadable($facts));
        self::assertNull(new AnyOf([$child, new Not($child)])->unreadable($facts));
        self::assertNull($child->unreadable($facts));
    }

    public function test_a_leaf_over_a_facts_class_that_no_provider_gives_is_a_failed_source(): void
    {
        $unprovided = new ConditionLeaf(new UnprovidedFactsReady(), []);

        $unreadable = new Not($unprovided)->unreadable(FactsMother::facts());

        self::assertNotNull($unreadable);
        self::assertSame([UnreadableKind::Failed, 'workflow.source.board'], [$unreadable->kind, $unreadable->source]);
        self::assertInstanceOf(\LogicException::class, $unreadable->cause);
    }

    public function test_a_missing_condition_is_never_readable_and_has_no_leaf(): void
    {
        $missing = new MissingConditionLeaf('card.gone', ['any' => 'value']);
        $facts = FactsMother::facts();

        self::assertEquals(new Unreadable(UnreadableKind::MissingCondition, 'card.gone'), $missing->unreadable($facts));
        self::assertEquals(new Unreadable(UnreadableKind::MissingCondition, 'card.gone'), new Not($missing)->unreadable($facts));
        self::assertFalse($missing->evaluate($facts));
        self::assertNull($missing->firstFalseLeaf($facts));
        self::assertSame([], $missing->reads());
        self::assertSame([], new AllOf([$missing])->leaves());
    }

    public function test_an_expression_lists_the_keys_of_its_missing_conditions_in_template_order(): void
    {
        $child = new ConditionLeaf(new CardIsChild(), []);
        $gone = new MissingConditionLeaf('card.gone', []);
        $lost = new MissingConditionLeaf('pr.lost', []);

        self::assertSame(['card.gone', 'pr.lost', 'card.gone'], new AllOf([$gone, new AnyOf([$child, new Not($lost)]), $gone])->missingKeys());
        self::assertSame([], $child->missingKeys());
        self::assertSame([], new AllOf([])->missingKeys());
    }
}
