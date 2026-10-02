<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Template;

use App\Module\Workflow\Fact\ChecksState;
use App\Module\Workflow\Fact\DocumentFacts;
use App\Module\Workflow\Fact\Facts;
use App\Module\Workflow\Fact\PullRequestState;
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

    public function test_a_child_whose_pull_requests_closed_unmerged_stays_in_backlog(): void
    {
        $toImplementation = new ActionCall(ActionType::Move, ['to' => 'implementation']);
        $toBacklog = new ActionCall(ActionType::Move, ['to' => '@backlog']);
        $closed = [FactsMother::pullRequest(state: PullRequestState::Closed, closedAt: new \DateTimeImmutable('2026-10-01 11:45:00'))];

        $unblocked = FactsMother::facts(card: FactsMother::card(slot: '@backlog', isChild: true));
        self::assertContainsEquals($toImplementation, $this->actions($unblocked));

        $inImplementation = FactsMother::facts(card: FactsMother::card(slot: 'implementation', isChild: true), pullRequests: $closed);
        self::assertContainsEquals($toBacklog, $this->actions($inImplementation));

        $inBacklog = FactsMother::facts(card: FactsMother::card(slot: '@backlog', isChild: true), pullRequests: $closed);
        self::assertNotContainsEquals($toImplementation, $this->actions($inBacklog));
    }

    /** @param list<string> $pair */
    #[DataProvider('overlaps')]
    public function test_only_one_rule_of_an_overlapping_pair_fires(Facts $facts, array $pair, string $fires): void
    {
        self::assertSame([$fires], array_values(array_intersect($this->firingRuleIds($facts), $pair)));
    }

    /** @return iterable<string, array{Facts, list<string>, string}> */
    public static function overlaps(): iterable
    {
        $merged = FactsMother::pullRequest(state: PullRequestState::Merged, closedAt: new \DateTimeImmutable('2026-10-01 11:00:00'));
        yield 'a merged pull request does not ask for an implementation' => [
            FactsMother::facts(card: FactsMother::card(slot: 'implementation'), pullRequest: $merged, pullRequests: [$merged]),
            ['implement', 'merged'],
            'merged',
        ];

        $closed = FactsMother::pullRequest(state: PullRequestState::Closed, closedAt: new \DateTimeImmutable('2026-10-01 11:45:00'));
        yield 'a closed pull request does not ask for an implementation' => [
            FactsMother::facts(card: FactsMother::card(slot: 'implementation'), pullRequest: $closed, pullRequests: [$closed]),
            ['implement', 'closed-unmerged'],
            'closed-unmerged',
        ];

        yield 'a product design with changes requested asks only for a revision' => [
            FactsMother::facts(card: FactsMother::card(slot: 'product-design', documents: [new DocumentFacts(tags: ['product'], status: 'changes-requested')])),
            ['product-design-session', 'product-design-revise'],
            'product-design-revise',
        ];

        yield 'a tech design with changes requested asks only for a revision' => [
            FactsMother::facts(card: FactsMother::card(slot: 'tech-design', documents: [new DocumentFacts(tags: ['design'], status: 'changes-requested')])),
            ['tech-design-write', 'tech-design-revise'],
            'tech-design-revise',
        ];

        $conflicting = FactsMother::pullRequest(checks: ChecksState::Passed, conflicting: true);
        yield 'a conflicting pull request asks for a fix and does not move' => [
            FactsMother::facts(card: FactsMother::card(slot: 'implementation'), pullRequest: $conflicting, pullRequests: [$conflicting]),
            ['reviewable', 'fix-in-implementation'],
            'fix-in-implementation',
        ];

        $changesRequested = FactsMother::pullRequest(checks: ChecksState::Passed, changesRequested: true);
        yield 'a pull request with changes requested asks for a fix and does not move' => [
            FactsMother::facts(card: FactsMother::card(slot: 'implementation'), pullRequest: $changesRequested, pullRequests: [$changesRequested]),
            ['reviewable', 'fix-in-implementation'],
            'fix-in-implementation',
        ];

        $behind = FactsMother::pullRequest(checks: ChecksState::Passed, behind: true, approvalsCoveringHead: 1);
        yield 'an approved pull request that is behind updates and does not merge' => [
            FactsMother::facts(card: FactsMother::card(slot: 'in-review'), pullRequest: $behind, pullRequests: [$behind]),
            ['merge-ready', 'update-behind'],
            'update-behind',
        ];

        $approvedIntoEpic = FactsMother::pullRequest(checks: ChecksState::Passed, approvalsCoveringHead: 1, baseIsEpicBranch: true);
        yield 'an approved pull request into the epic branch merges through the epic child rule' => [
            FactsMother::facts(card: FactsMother::card(slot: 'in-review', isChild: true), pullRequest: $approvedIntoEpic, pullRequests: [$approvedIntoEpic]),
            ['merge-ready', 'merge-ready-epic-child'],
            'merge-ready-epic-child',
        ];

        $approvedIntoDefault = FactsMother::pullRequest(checks: ChecksState::Passed, approvalsCoveringHead: 1);
        yield 'an approved pull request into the default branch merges through merge-ready' => [
            FactsMother::facts(card: FactsMother::card(slot: 'in-review', isChild: true), pullRequest: $approvedIntoDefault, pullRequests: [$approvedIntoDefault]),
            ['merge-ready', 'merge-ready-epic-child'],
            'merge-ready',
        ];
    }

    public function test_an_epic_child_pull_request_merges_into_the_epic_branch_with_no_approval(): void
    {
        $merge = new ActionCall(ActionType::ForgeWrite, ['write' => 'merge', 'fallback' => 'merge']);
        $child = FactsMother::card(slot: 'in-review', isChild: true);

        $intoEpic = FactsMother::pullRequest(checks: ChecksState::Passed, baseIsEpicBranch: true);
        self::assertContainsEquals($merge, $this->actions(FactsMother::facts(card: $child, pullRequest: $intoEpic, pullRequests: [$intoEpic])));

        $intoDefault = FactsMother::pullRequest(checks: ChecksState::Passed);
        self::assertNotContainsEquals($merge, $this->actions(FactsMother::facts(card: $child, pullRequest: $intoDefault, pullRequests: [$intoDefault])));

        $pending = FactsMother::pullRequest(checks: ChecksState::Pending, baseIsEpicBranch: true);
        self::assertNotContainsEquals($merge, $this->actions(FactsMother::facts(card: $child, pullRequest: $pending, pullRequests: [$pending])));
    }

    public function test_a_behind_epic_child_pull_request_updates_its_branch_with_no_approval(): void
    {
        $update = new ActionCall(ActionType::ForgeWrite, ['write' => 'update-branch', 'fallback' => 'sync']);
        $child = FactsMother::card(slot: 'in-review', isChild: true);

        $intoEpic = FactsMother::pullRequest(checks: ChecksState::Passed, behind: true, baseIsEpicBranch: true);
        self::assertContainsEquals($update, $this->actions(FactsMother::facts(card: $child, pullRequest: $intoEpic, pullRequests: [$intoEpic])));

        $intoDefault = FactsMother::pullRequest(checks: ChecksState::Passed, behind: true);
        self::assertNotContainsEquals($update, $this->actions(FactsMother::facts(card: $child, pullRequest: $intoDefault, pullRequests: [$intoDefault])));

        $merged = FactsMother::pullRequest(state: PullRequestState::Merged, checks: ChecksState::Passed, approvalsCoveringHead: 1, behind: true, baseIsEpicBranch: true);
        self::assertNotContainsEquals($update, $this->actions(FactsMother::facts(card: $child, pullRequest: $merged, pullRequests: [$merged])));
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

    /** @return list<string> the id of every Lifecycle rule that matches the facts */
    private function firingRuleIds(Facts $facts): array
    {
        $ids = [];
        foreach ($this->lifecycle()->rulesFor($facts->card->slot) as $rule) {
            if ($rule->when->evaluate($facts)) {
                $ids[] = $rule->id;
            }
        }

        return $ids;
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
