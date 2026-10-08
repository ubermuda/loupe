<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Condition;

use App\Module\Workflow\Condition\CardChildMergedIntoEpicBranch;
use App\Module\Workflow\Condition\CardChildrenFinished;
use App\Module\Workflow\Condition\CardDocument;
use App\Module\Workflow\Condition\CardDocumentApproved;
use App\Module\Workflow\Condition\CardDocumentChangesRequested;
use App\Module\Workflow\Condition\CardHasChildren;
use App\Module\Workflow\Condition\CardHasOpenBlocker;
use App\Module\Workflow\Condition\CardHasType;
use App\Module\Workflow\Condition\CardInSlot;
use App\Module\Workflow\Condition\CardIsChild;
use App\Module\Workflow\Condition\ParentDocumentApproved;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\FactKey;
use App\Module\Workflow\Contract\Facts;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\TranslatableMessage;

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

        yield 'a child merged into the epic branch' => [new CardChildMergedIntoEpicBranch(), [], self::card(childCount: 1, childMergedIntoEpicBranch: true), true];
        yield 'no child merged into the epic branch' => [new CardChildMergedIntoEpicBranch(), [], self::card(childCount: 1), false];

        $approvedDesign = new DocumentFacts(tags: ['design'], status: 'approved', id: '01a10beb-ba65-736b-8626-a6e3fa59dfc5');
        $changesOnDesign = new DocumentFacts(tags: ['plan', 'design'], status: 'changes-requested', id: '01a10beb-ba65-736b-8626-a6e3fa59dfc5');
        $approvedProduct = new DocumentFacts(tags: ['product'], status: 'approved', id: '01a10beb-ba65-736b-8626-a6e3fa59dfc5');
        $designInReview = new DocumentFacts(tags: ['design'], status: 'in-review', id: '01a10beb-ba65-736b-8626-a6e3fa59dfc5');

        yield 'document, tagged document in review' => [new CardDocument(), ['tag' => 'design'], self::card(documents: [$designInReview]), true];
        yield 'document, second tag of a document' => [new CardDocument(), ['tag' => 'plan'], self::card(documents: [$changesOnDesign]), true];
        yield 'document, other tag' => [new CardDocument(), ['tag' => 'design'], self::card(documents: [$approvedProduct]), false];
        yield 'document, no document' => [new CardDocument(), ['tag' => 'design'], self::card(documents: []), false];
        yield 'document, tagged document with the status' => [new CardDocument(), ['tag' => 'design', 'status' => 'approved'], self::card(documents: [$designInReview, $approvedDesign]), true];
        yield 'document, tagged document with another status' => [new CardDocument(), ['tag' => 'design', 'status' => 'approved'], self::card(documents: [$designInReview, $approvedProduct]), false];

        yield 'approved, tagged document approved' => [new CardDocumentApproved(), ['tag' => 'design'], self::card(documents: [$designInReview, $approvedDesign]), true];
        yield 'approved, other tag approved' => [new CardDocumentApproved(), ['tag' => 'design'], self::card(documents: [$approvedProduct, $designInReview]), false];
        yield 'approved, no document' => [new CardDocumentApproved(), ['tag' => 'design'], self::card(documents: []), false];

        yield 'changes requested, tagged document' => [new CardDocumentChangesRequested(), ['tag' => 'design'], self::card(documents: [$changesOnDesign]), true];
        yield 'changes requested, tagged document approved' => [new CardDocumentChangesRequested(), ['tag' => 'design'], self::card(documents: [$approvedDesign]), false];
        yield 'changes requested, other tag' => [new CardDocumentChangesRequested(), ['tag' => 'product'], self::card(documents: [$changesOnDesign]), false];

        yield 'parent approved, tagged document of the parent approved' => [new ParentDocumentApproved(), ['tag' => 'design'], self::card(parentDocuments: [$designInReview, $approvedDesign]), true];
        yield 'parent approved, tagged document of the parent in review' => [new ParentDocumentApproved(), ['tag' => 'design'], self::card(parentDocuments: [$designInReview]), false];
        yield 'parent approved, other tag approved' => [new ParentDocumentApproved(), ['tag' => 'design'], self::card(parentDocuments: [$approvedProduct]), false];
        yield 'parent approved, no parent document' => [new ParentDocumentApproved(), ['tag' => 'design'], self::card(), false];
        yield 'parent approved, only the card has the approved document' => [new ParentDocumentApproved(), ['tag' => 'design'], self::card(documents: [$approvedDesign]), false];
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
        yield 'card.child_merged_into_epic_branch' => [new CardChildMergedIntoEpicBranch(), [], 'workflow.waiting.card_child_merged_into_epic_branch', []];
        yield 'card.document' => [new CardDocument(), ['tag' => 'design'], 'workflow.waiting.card_document', ['%tag%' => 'design']];
        yield 'card.document_approved' => [new CardDocumentApproved(), ['tag' => 'design'], 'workflow.waiting.card_document_approved', ['%tag%' => 'design']];
        yield 'card.document_changes_requested' => [new CardDocumentChangesRequested(), ['tag' => 'design'], 'workflow.waiting.card_document_changes_requested', ['%tag%' => 'design']];
        yield 'parent.document_approved' => [new ParentDocumentApproved(), ['tag' => 'design'], 'workflow.waiting.parent_document_approved', ['%tag%' => 'design']];
    }

    public function test_a_document_condition_with_a_status_names_the_status_it_waits_for(): void
    {
        $condition = new CardDocument();
        $params = ['tag' => 'design', 'status' => 'changes-requested'];
        $expected = ['%tag%' => 'design', '%status%' => new TranslatableMessage('document.status.changes_requested')];

        self::assertSame('workflow.waiting.card_document_status', $condition->waitingFor($params)->getMessage());
        self::assertEquals($expected, $condition->waitingFor($params)->getParameters());
        self::assertSame('workflow.waiting.not.card_document_status', $condition->waitingFor($params, negated: true)->getMessage());
        self::assertEquals($expected, $condition->waitingFor($params, negated: true)->getParameters());
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
        yield 'card.child_merged_into_epic_branch' => [new CardChildMergedIntoEpicBranch(), [], [FactKey::Children]];
        yield 'card.document' => [new CardDocument(), ['tag' => 'design'], [FactKey::Documents]];
        yield 'card.document with a status' => [new CardDocument(), ['tag' => 'design', 'status' => 'approved'], [FactKey::Documents]];
        yield 'card.document_approved' => [new CardDocumentApproved(), ['tag' => 'design'], [FactKey::Documents]];
        yield 'card.document_changes_requested' => [new CardDocumentChangesRequested(), ['tag' => 'design'], [FactKey::Documents]];
        yield 'parent.document_approved' => [new ParentDocumentApproved(), ['tag' => 'design'], [FactKey::ParentDocuments]];
    }

    /**
     * @param list<DocumentFacts> $documents
     * @param list<DocumentFacts> $parentDocuments
     */
    private static function card(
        ?string $slot = null,
        string $type = 'feature',
        bool $hasOpenBlocker = false,
        bool $isChild = false,
        int $childCount = 0,
        int $openChildCount = 0,
        array $documents = [],
        bool $childMergedIntoEpicBranch = false,
        array $parentDocuments = [],
    ): Facts {
        return FactsMother::facts(card: FactsMother::card(
            slot: $slot,
            type: $type,
            hasOpenBlocker: $hasOpenBlocker,
            isChild: $isChild,
            childCount: $childCount,
            openChildCount: $openChildCount,
            documents: $documents,
            childMergedIntoEpicBranch: $childMergedIntoEpicBranch,
            parentDocuments: $parentDocuments,
        ));
    }
}
