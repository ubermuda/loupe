<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Condition;

use App\Module\Workflow\Condition\CardChildrenFinished;
use App\Module\Workflow\Condition\CardDocumentApproved;
use App\Module\Workflow\Condition\CardDocumentChangesRequested;
use App\Module\Workflow\Condition\CardHasChildren;
use App\Module\Workflow\Condition\CardHasOpenBlocker;
use App\Module\Workflow\Condition\CardHasType;
use App\Module\Workflow\Condition\CardInSlot;
use App\Module\Workflow\Condition\CardIsChild;
use App\Module\Workflow\Condition\Condition;
use App\Module\Workflow\Fact\DocumentFacts;
use App\Module\Workflow\Fact\FactKey;
use App\Module\Workflow\Fact\Facts;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CardConditionsTest extends TestCase
{
    /** @param array<string, mixed> $params */
    #[DataProvider('cases')]
    public function test_it_evaluates_the_facts(Condition $condition, array $params, Facts $facts, bool $expected): void
    {
        self::assertSame($expected, $condition->evaluate($facts, $params));
    }

    /** @return iterable<string, array{Condition, array<string, mixed>, Facts, bool}> */
    public static function cases(): iterable
    {
        yield 'in slot, same slot' => [new CardInSlot(), ['slot' => 'tech-design'], self::card(slot: 'tech-design'), true];
        yield 'in slot, other slot' => [new CardInSlot(), ['slot' => 'tech-design'], self::card(slot: 'implementation'), false];
        yield 'in slot, no slot' => [new CardInSlot(), ['slot' => 'tech-design'], self::card(slot: null), false];

        yield 'type, same type' => [new CardHasType(), ['type' => 'epic'], self::card(type: 'epic'), true];
        yield 'type, other type' => [new CardHasType(), ['type' => 'epic'], self::card(type: 'feature'), false];

        yield 'open blocker' => [new CardHasOpenBlocker(), [], self::card(hasOpenBlocker: true), true];
        yield 'no open blocker' => [new CardHasOpenBlocker(), [], self::card(hasOpenBlocker: false), false];

        yield 'child' => [new CardIsChild(), [], self::card(isChild: true), true];
        yield 'not a child' => [new CardIsChild(), [], self::card(isChild: false), false];

        yield 'has children' => [new CardHasChildren(), [], self::card(childCount: 1), true];
        yield 'has no child' => [new CardHasChildren(), [], self::card(childCount: 0), false];

        yield 'children finished' => [new CardChildrenFinished(), [], self::card(childCount: 2, openChildCount: 0), true];
        yield 'a child open' => [new CardChildrenFinished(), [], self::card(childCount: 2, openChildCount: 1), false];
        yield 'no child counts as finished' => [new CardChildrenFinished(), [], self::card(childCount: 0, openChildCount: 0), true];

        $approvedDesign = new DocumentFacts(tags: ['design'], status: 'approved');
        $changesOnDesign = new DocumentFacts(tags: ['plan', 'design'], status: 'changes-requested');
        $approvedProduct = new DocumentFacts(tags: ['product'], status: 'approved');
        $designInReview = new DocumentFacts(tags: ['design'], status: 'in-review');

        yield 'approved, tagged document approved' => [new CardDocumentApproved(), ['tag' => 'design'], self::card(documents: [$designInReview, $approvedDesign]), true];
        yield 'approved, other tag approved' => [new CardDocumentApproved(), ['tag' => 'design'], self::card(documents: [$approvedProduct, $designInReview]), false];
        yield 'approved, no document' => [new CardDocumentApproved(), ['tag' => 'design'], self::card(documents: []), false];

        yield 'changes requested, tagged document' => [new CardDocumentChangesRequested(), ['tag' => 'design'], self::card(documents: [$changesOnDesign]), true];
        yield 'changes requested, tagged document approved' => [new CardDocumentChangesRequested(), ['tag' => 'design'], self::card(documents: [$approvedDesign]), false];
        yield 'changes requested, other tag' => [new CardDocumentChangesRequested(), ['tag' => 'product'], self::card(documents: [$changesOnDesign]), false];
    }

    /**
     * @param array<string, mixed>  $params
     * @param array<string, string> $expectedParameters
     */
    #[DataProvider('waiting')]
    public function test_it_says_what_it_waits_for(Condition $condition, array $params, string $expectedKey, array $expectedParameters): void
    {
        $message = $condition->waitingFor($params);

        self::assertSame($expectedKey, $message->getMessage());
        self::assertSame($expectedParameters, $message->getParameters());
        self::assertSame('workflow.waiting.'.str_replace('.', '_', $condition::key()), $message->getMessage());

        $negated = $condition->waitingFor($params, negated: true);

        self::assertSame(str_replace('workflow.waiting.', 'workflow.waiting.not.', $expectedKey), $negated->getMessage());
        self::assertSame($expectedParameters, $negated->getParameters());
    }

    /** @return iterable<string, array{Condition, array<string, mixed>, string, array<string, string>}> */
    public static function waiting(): iterable
    {
        yield 'card.in_slot' => [new CardInSlot(), ['slot' => 'tech-design'], 'workflow.waiting.card_in_slot', ['%slot%' => 'tech-design']];
        yield 'card.type' => [new CardHasType(), ['type' => 'epic'], 'workflow.waiting.card_type', ['%type%' => 'epic']];
        yield 'card.has_open_blocker' => [new CardHasOpenBlocker(), [], 'workflow.waiting.card_has_open_blocker', []];
        yield 'card.is_child' => [new CardIsChild(), [], 'workflow.waiting.card_is_child', []];
        yield 'card.has_children' => [new CardHasChildren(), [], 'workflow.waiting.card_has_children', []];
        yield 'card.children_finished' => [new CardChildrenFinished(), [], 'workflow.waiting.card_children_finished', []];
        yield 'card.document_approved' => [new CardDocumentApproved(), ['tag' => 'design'], 'workflow.waiting.card_document_approved', ['%tag%' => 'design']];
        yield 'card.document_changes_requested' => [new CardDocumentChangesRequested(), ['tag' => 'design'], 'workflow.waiting.card_document_changes_requested', ['%tag%' => 'design']];
    }

    /**
     * @param array<string, mixed> $params
     * @param list<FactKey>        $expected
     */
    #[DataProvider('reads')]
    public function test_it_names_the_facts_it_reads(Condition $condition, array $params, array $expected): void
    {
        self::assertSame($expected, $condition->reads($params));
    }

    /** @return iterable<string, array{Condition, array<string, mixed>, list<FactKey>}> */
    public static function reads(): iterable
    {
        yield 'card.in_slot' => [new CardInSlot(), ['slot' => 'next'], [FactKey::Slot]];
        yield 'card.type' => [new CardHasType(), ['type' => 'epic'], [FactKey::CardType]];
        yield 'card.has_open_blocker' => [new CardHasOpenBlocker(), [], [FactKey::Blockers]];
        yield 'card.is_child' => [new CardIsChild(), [], [FactKey::Parent]];
        yield 'card.has_children' => [new CardHasChildren(), [], [FactKey::Children]];
        yield 'card.children_finished' => [new CardChildrenFinished(), [], [FactKey::Children]];
        yield 'card.document_approved' => [new CardDocumentApproved(), ['tag' => 'design'], [FactKey::Documents]];
        yield 'card.document_changes_requested' => [new CardDocumentChangesRequested(), ['tag' => 'design'], [FactKey::Documents]];
    }

    /** @param list<DocumentFacts> $documents */
    private static function card(
        ?string $slot = null,
        string $type = 'feature',
        bool $hasOpenBlocker = false,
        bool $isChild = false,
        int $childCount = 0,
        int $openChildCount = 0,
        array $documents = [],
    ): Facts {
        return FactsMother::facts(card: FactsMother::card(
            slot: $slot,
            type: $type,
            hasOpenBlocker: $hasOpenBlocker,
            isChild: $isChild,
            childCount: $childCount,
            openChildCount: $openChildCount,
            documents: $documents,
        ));
    }
}
