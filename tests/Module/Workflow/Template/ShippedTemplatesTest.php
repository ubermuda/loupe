<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Template;

use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Workflow\AgentReviewFacts;
use App\Module\AgentReview\Workflow\ReviewedHead;
use App\Module\Workflow\Action\Actions;
use App\Module\Workflow\Contract\ChecksState;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\PullRequestState;
use App\Module\Workflow\Engine\RuleSubject;
use App\Module\Workflow\Template\ActionCall;
use App\Module\Workflow\Template\ManualMoveActor;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Module\Workflow\Template\Template;
use App\Module\Workflow\Template\TemplateParser;
use App\Module\Workflow\Template\UnknownTemplate;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Component\Uid\Uuid;

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

    public function test_both_templates_declare_the_card_types_and_only_an_epic_has_children_and_a_lane(): void
    {
        foreach ($this->shipped()->keys() as $key) {
            $template = $this->template($key);
            self::assertSame(['feature', 'bug', 'security', 'tooling', 'docs', 'idea', 'epic'], array_map(static fn ($type) => $type->key, $template->types));
            self::assertSame('feature', $template->defaultType);
            foreach ($template->types as $type) {
                self::assertSame('epic' === $type->key, $type->children);
                self::assertSame('epic' === $type->key, $type->lane);
                self::assertSame('board.card.type.'.$type->key, $type->label);
            }
        }
    }

    public function test_simple_has_no_slot_and_allows_every_manual_move(): void
    {
        $simple = $this->template('simple');

        self::assertSame([], $simple->slots);
        self::assertCount(1, $simple->manualMoves);
        self::assertSame('*', $simple->manualMoves[0]->from);
        self::assertSame('*', $simple->manualMoves[0]->to);
        self::assertSame(['merged'], array_map(static fn ($rule) => $rule->id, $simple->rulesFor(null)));
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
        $facts = FactsMother::facts(card: FactsMother::card(slot: 'tech-design', documents: [self::approved('tech-design')]));

        self::assertContainsEquals($this->call('move', ['to' => 'implementation']), $this->actions($facts));
    }

    public function test_an_approved_tech_design_with_an_open_blocker_does_not_move(): void
    {
        $facts = FactsMother::facts(card: FactsMother::card(slot: 'tech-design', hasOpenBlocker: true, documents: [self::approved('tech-design')]));

        $actions = $this->actions($facts);
        self::assertNotEmpty($this->lifecycle()->rulesFor('tech-design'));
        self::assertSame([], array_values(array_filter($actions, static fn (ActionCall $action): bool => 'move' === $action->key)));
    }

    public function test_an_approved_product_design_asks_for_a_tech_design_and_does_not_move(): void
    {
        $facts = FactsMother::facts(card: FactsMother::card(slot: 'tech-design', documents: [self::approved('product-design')]));

        self::assertSame(['tech-design-write'], $this->firingRuleIds($facts));
        $requests = array_values(array_filter($this->actions($facts), static fn (ActionCall $action): bool => 'request' === $action->key));
        self::assertSame([['kind' => 'tech-design']], array_map(static fn (ActionCall $action): array => $action->params, $requests));
    }

    public function test_an_approved_tech_design_next_to_an_approved_product_design_moves_to_implementation(): void
    {
        $facts = FactsMother::facts(card: FactsMother::card(slot: 'tech-design', documents: [self::approved('product-design'), self::approved('tech-design')]));

        self::assertSame(['tech-design-approved'], $this->firingRuleIds($facts));
        self::assertContainsEquals($this->call('move', ['to' => 'implementation']), $this->actions($facts));
    }

    public function test_a_reviewable_pull_request_moves_to_in_review(): void
    {
        $facts = FactsMother::facts(
            card: FactsMother::card(slot: 'implementation'),
            pullRequest: FactsMother::pullRequest(checks: ChecksState::Passed),
        );

        self::assertContainsEquals($this->call('move', ['to' => 'in-review']), $this->actions($facts));
    }

    /** @return iterable<string, array{string}> */
    public static function reviewSlots(): iterable
    {
        yield 'implementation' => ['implementation'];
        yield 'in review' => ['in-review'];
    }

    #[DataProvider('reviewSlots')]
    public function test_a_head_with_no_agent_review_asks_for_one(string $slot): void
    {
        $facts = self::withAgentReview($slot, [null]);
        $suffix = 'in-review' === $slot ? '-in-review' : '';

        self::assertContains('agent-review'.$suffix, $this->firingRuleIds($facts));
        self::assertNotContains('fix-agent-review'.$suffix, $this->firingRuleIds($facts));
    }

    #[DataProvider('reviewSlots')]
    public function test_a_failed_agent_review_asks_for_a_fix_with_its_own_reason_and_limit(string $slot): void
    {
        $facts = self::withAgentReview($slot, [AgentReviewConclusion::Failure]);
        $suffix = 'in-review' === $slot ? '-in-review' : '';
        $rule = array_find($this->lifecycle()->rulesFor($slot), static fn ($rule): bool => 'fix-agent-review'.$suffix === $rule->id);

        self::assertNotNull($rule);
        self::assertTrue($rule->when->evaluate($facts));
        self::assertSame(['kind' => 'fix', 'reason' => 'agent-review', 'limit' => 10], \array_slice($rule->then->params, 0, 3));
        self::assertNotContains('agent-review'.$suffix, $this->firingRuleIds($facts));
    }

    /** @return iterable<string, array{string, AgentReviewConclusion|null}> */
    public static function boundAgentReviewRules(): iterable
    {
        yield 'review' => ['agent-review', null];
        yield 'fix' => ['fix-agent-review', AgentReviewConclusion::Failure];
    }

    #[DataProvider('boundAgentReviewRules')]
    public function test_an_agent_review_rule_acts_on_the_pull_request_whose_head_needs_it(string $ruleId, ?AgentReviewConclusion $conclusion): void
    {
        $passed = FactsMother::pullRequest(checks: ChecksState::Passed, id: Uuid::v7());
        $needing = FactsMother::pullRequest(checks: ChecksState::Passed, id: Uuid::v7());
        $facts = FactsMother::facts(
            card: FactsMother::card(slot: 'implementation'),
            pullRequest: $passed,
            pullRequests: [$passed, $needing],
            provided: [AgentReviewFacts::class => new AgentReviewFacts([
                new ReviewedHead((string) $passed->id, str_repeat('a', 40), AgentReviewConclusion::Success),
                new ReviewedHead((string) $needing->id, str_repeat('b', 40), $conclusion),
            ], enabled: true, epic: false, unposted: false)],
        );
        $rule = array_find($this->lifecycle()->rulesFor('implementation'), static fn ($rule): bool => $ruleId === $rule->id);
        self::assertNotNull($rule);

        $bound = new RuleSubject()->bind($rule, $facts);

        self::assertTrue($bound->truth);
        self::assertEquals($needing->id, $bound->subject);
    }

    public function test_a_draft_that_passed_the_agent_review_is_marked_ready(): void
    {
        $passed = self::withAgentReview('implementation', [AgentReviewConclusion::Success], draft: true);
        $pending = self::withAgentReview('implementation', [null], draft: true);
        $epic = self::withAgentReview('implementation', [AgentReviewConclusion::Success], draft: true, epic: true);
        $ready = $this->call('forge-write', ['write' => 'review-ready']);

        self::assertContainsEquals($ready, $this->actions($passed));
        self::assertNotContainsEquals($ready, $this->actions($pending));
        self::assertNotContainsEquals($ready, $this->actions($epic));
    }

    public function test_a_card_waits_in_implementation_and_in_review_until_the_agent_review_passed(): void
    {
        $toReview = $this->call('move', ['to' => 'in-review']);
        $merge = $this->call('forge-write', ['write' => 'merge', 'fallback' => 'merge']);

        self::assertNotContainsEquals($toReview, $this->actions(self::withAgentReview('implementation', [null])));
        self::assertNotContainsEquals($toReview, $this->actions(self::withAgentReview('implementation', [AgentReviewConclusion::Failure])));
        self::assertContainsEquals($toReview, $this->actions(self::withAgentReview('implementation', [AgentReviewConclusion::Success])));
        self::assertNotContainsEquals($merge, $this->actions(self::withAgentReview('in-review', [null], approvals: 1)));
        self::assertContainsEquals($merge, $this->actions(self::withAgentReview('in-review', [AgentReviewConclusion::Success], approvals: 1)));
    }

    public function test_an_epic_child_waits_for_the_agent_review_before_it_merges_into_the_epic_branch(): void
    {
        $merge = $this->call('forge-write', ['write' => 'merge', 'fallback' => 'merge']);

        self::assertNotContainsEquals($merge, $this->actions(self::withAgentReview('in-review', [null], epicBranch: true)));
        self::assertContainsEquals($merge, $this->actions(self::withAgentReview('in-review', [AgentReviewConclusion::Success], epicBranch: true)));
    }

    /** @param list<AgentReviewConclusion|null> $conclusions the newest review conclusion of each open pull request head */
    private static function withAgentReview(string $slot, array $conclusions, bool $draft = false, bool $epic = false, int $approvals = 0, bool $epicBranch = false): Facts
    {
        $id = Uuid::v7();
        $heads = array_map(static fn (?AgentReviewConclusion $conclusion): ReviewedHead => new ReviewedHead((string) $id, str_repeat('a', 40), $conclusion), $conclusions);

        return FactsMother::facts(
            card: FactsMother::card(slot: $slot, type: $epic ? 'epic' : 'feature'),
            pullRequest: FactsMother::pullRequest(draft: $draft, checks: ChecksState::Passed, approvalsCoveringHead: $approvals, baseIsMergeTarget: !$epicBranch, baseIsEpicBranch: $epicBranch, id: $id),
            provided: [AgentReviewFacts::class => new AgentReviewFacts($heads, enabled: true, epic: $epic, unposted: false)],
        );
    }

    public function test_a_draft_in_review_moves_back_to_implementation(): void
    {
        $facts = FactsMother::facts(
            card: FactsMother::card(slot: 'in-review'),
            pullRequest: FactsMother::pullRequest(draft: true, checks: ChecksState::Passed),
        );

        self::assertContainsEquals($this->call('move', ['to' => 'implementation']), $this->actions($facts));
    }

    public function test_a_ready_pull_request_asks_for_the_merge_write(): void
    {
        $ready = FactsMother::facts(
            card: FactsMother::card(slot: 'in-review'),
            pullRequest: FactsMother::pullRequest(checks: ChecksState::Passed, approvalsCoveringHead: 1),
        );
        $merge = $this->call('forge-write', ['write' => 'merge', 'fallback' => 'merge']);

        self::assertContainsEquals($merge, $this->actions($ready));

        $unapproved = FactsMother::facts(
            card: FactsMother::card(slot: 'in-review'),
            pullRequest: FactsMother::pullRequest(checks: ChecksState::Passed),
        );
        self::assertNotContainsEquals($merge, $this->actions($unapproved));
    }

    public function test_an_epic_with_finished_children_moves_to_review_with_a_draft_pull_request(): void
    {
        $toReview = $this->call('move', ['to' => 'in-review']);
        $epic = FactsMother::card(slot: 'implementation', type: 'epic', childCount: 2);

        self::assertContainsEquals($toReview, $this->actions(FactsMother::facts(card: $epic, pullRequest: FactsMother::pullRequest())));
        self::assertContainsEquals($toReview, $this->actions(FactsMother::facts(card: $epic, pullRequest: FactsMother::pullRequest(draft: true))));
    }

    public function test_an_epic_in_review_with_a_draft_pull_request_stays_in_review(): void
    {
        $facts = FactsMother::facts(
            card: FactsMother::card(slot: 'in-review', type: 'epic', childCount: 2),
            pullRequest: FactsMother::pullRequest(draft: true),
        );

        self::assertNotContainsEquals($this->call('move', ['to' => 'implementation']), $this->actions($facts));
    }

    #[DataProvider('epicStateWrites')]
    public function test_an_epic_that_arrives_in_a_slot_writes_the_state_of_its_pull_requests(string $slot, string $write): void
    {
        $writeAction = $this->call('forge-write', ['write' => $write]);
        $epic = FactsMother::facts(card: FactsMother::card(slot: $slot, type: 'epic', childCount: 2, openChildCount: 1), pullRequest: FactsMother::pullRequest());
        $feature = FactsMother::facts(card: FactsMother::card(slot: $slot), pullRequest: FactsMother::pullRequest());

        self::assertContainsEquals($writeAction, $this->actions($epic));
        self::assertContainsEquals($writeAction, $this->actions(FactsMother::facts(card: FactsMother::card(slot: $slot, type: 'epic'))));
        self::assertNotContainsEquals($writeAction, $this->actions($feature));
    }

    /** @return iterable<string, array{string, string}> */
    public static function epicStateWrites(): iterable
    {
        yield 'implementation' => ['implementation', 'draft'];
        yield 'review' => ['in-review', 'ready'];
        yield 'backlog' => ['@backlog', 'close'];
    }

    public function test_an_epic_in_the_backlog_with_an_open_pull_request_stays_there(): void
    {
        $toImplementation = $this->call('move', ['to' => 'implementation', 'from' => '@backlog']);

        $epic = FactsMother::facts(card: FactsMother::card(slot: '@backlog', type: 'epic'), pullRequest: FactsMother::pullRequest());
        self::assertNotContainsEquals($toImplementation, $this->actions($epic));

        $feature = FactsMother::facts(card: FactsMother::card(slot: '@backlog'), pullRequest: FactsMother::pullRequest());
        self::assertContainsEquals($toImplementation, $this->actions($feature));
    }

    #[DataProvider('shippedKeys')]
    public function test_a_terminal_card_requests_its_teardown(string $key): void
    {
        $rules = array_values(array_filter($this->template($key)->rules, static fn ($rule): bool => 'teardown' === $rule->id));

        self::assertCount(1, $rules);
        self::assertSame('@terminal', $rules[0]->slot);
        self::assertTrue($rules[0]->when->evaluate(FactsMother::facts(card: FactsMother::card(slot: '@terminal'))));
        self::assertSame('request', $rules[0]->then->key);
        self::assertSame(['kind' => 'teardown', 'onTimeout' => 'expire'], $rules[0]->then->params);
    }

    #[DataProvider('shippedKeys')]
    public function test_every_request_names_what_its_work_needs_from_the_project(string $key): void
    {
        $requests = array_values(array_filter($this->template($key)->rules, static fn ($rule): bool => 'request' === $rule->then->key));

        self::assertNotEmpty($requests);
        foreach ($requests as $rule) {
            self::assertNotSame([], $rule->then->checks, $rule->id);
        }
    }

    public function test_the_ready_pull_request_of_an_epic_asks_for_the_merge_write(): void
    {
        $facts = FactsMother::facts(
            card: FactsMother::card(slot: 'in-review', type: 'epic', childCount: 2),
            pullRequest: FactsMother::pullRequest(checks: ChecksState::Passed, approvalsCoveringHead: 1),
        );

        self::assertContainsEquals($this->call('forge-write', ['write' => 'merge', 'fallback' => 'merge']), $this->actions($facts));
    }

    #[DataProvider('shippedKeys')]
    public function test_a_global_move_does_not_match_in_its_target_column(string $key): void
    {
        $template = $this->template($key);
        $merged = FactsMother::pullRequest(state: PullRequestState::Merged, closedAt: new \DateTimeImmutable('2026-10-01 11:00:00'));
        $closed = FactsMother::pullRequest(state: PullRequestState::Closed, closedAt: new \DateTimeImmutable('2026-10-01 11:00:00'));

        foreach ([['@terminal', $merged], ['@backlog', $closed]] as [$slot, $pullRequest]) {
            $facts = FactsMother::facts(card: FactsMother::card(slot: $slot), pullRequest: $pullRequest, pullRequests: [$pullRequest]);
            foreach ($template->rulesFor(null) as $rule) {
                self::assertFalse($rule->when->evaluate($facts) && $slot === ($rule->then->params['to'] ?? null), $key.' '.$rule->id.' in '.$slot);
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function shippedKeys(): iterable
    {
        yield 'lifecycle' => ['lifecycle'];
        yield 'simple' => ['simple'];
    }

    public function test_an_epic_with_a_reopened_child_does_not_merge(): void
    {
        $facts = FactsMother::facts(
            card: FactsMother::card(slot: 'in-review', type: 'epic', childCount: 2, openChildCount: 1),
            pullRequest: FactsMother::pullRequest(checks: ChecksState::Passed, approvalsCoveringHead: 1),
        );

        self::assertNotContainsEquals($this->call('forge-write', ['write' => 'merge', 'fallback' => 'merge']), $this->actions($facts));
    }

    public function test_a_child_whose_pull_requests_closed_unmerged_stays_in_its_column(): void
    {
        $toImplementation = $this->call('move', ['to' => 'implementation']);
        $toBacklog = $this->call('move', ['to' => '@backlog']);
        $closed = [FactsMother::pullRequest(state: PullRequestState::Closed, closedAt: new \DateTimeImmutable('2026-10-01 11:45:00'))];

        $unblocked = FactsMother::facts(card: FactsMother::card(slot: 'next', isChild: true, documents: [self::approved('tech-design')], parentSlot: 'implementation'));
        self::assertContainsEquals($toImplementation, $this->actions($unblocked));

        $inImplementation = FactsMother::facts(card: FactsMother::card(slot: 'implementation', isChild: true), pullRequests: $closed);
        self::assertNotContainsEquals($toBacklog, $this->actions($inImplementation));

        $inNext = FactsMother::facts(card: FactsMother::card(slot: 'next', isChild: true, documents: [self::approved('tech-design')], parentSlot: 'implementation'), pullRequests: $closed);
        self::assertNotContainsEquals($toImplementation, $this->actions($inNext));
    }

    public function test_a_child_in_next_starts_only_with_an_approved_tech_design(): void
    {
        $toImplementation = $this->call('move', ['to' => 'implementation']);
        $id = '01a10beb-ba65-736b-8626-a6e3fa59dfc5';

        self::assertContainsEquals($toImplementation, $this->actions(self::nextChild([self::approved('tech-design')])));
        self::assertNotContainsEquals($toImplementation, $this->actions(self::nextChild([])));
        self::assertNotContainsEquals($toImplementation, $this->actions(self::nextChild([new DocumentFacts(['tech-design'], 'in-review', $id)])));
        self::assertNotContainsEquals($toImplementation, $this->actions(self::nextChild([new DocumentFacts(['tech-design'], 'changes-requested', $id)])));
        self::assertNotContainsEquals($toImplementation, $this->actions(self::nextChild([new DocumentFacts(['product-design'], 'approved', $id)])));
    }

    public function test_a_child_in_next_waits_until_its_epic_sits_in_implementation(): void
    {
        $toImplementation = $this->call('move', ['to' => 'implementation']);
        $documents = [self::approved('tech-design')];

        foreach ([['implementation', true], ['next', false], ['@backlog', false], ['tech-design', false], [null, false]] as [$parentSlot, $starts]) {
            $facts = FactsMother::facts(card: FactsMother::card(slot: 'next', isChild: true, documents: $documents, parentSlot: $parentSlot));
            $starts ? self::assertContainsEquals($toImplementation, $this->actions($facts), \sprintf('Parent slot "%s".', $parentSlot)) : self::assertNotContainsEquals($toImplementation, $this->actions($facts), \sprintf('Parent slot "%s".', $parentSlot ?? 'none'));
        }
    }

    public function test_a_child_in_the_backlog_is_parked_and_a_child_outside_it_is_not_moved(): void
    {
        $toNext = $this->call('move', ['to' => 'next', 'from' => '@backlog']);
        $documents = [self::approved('tech-design')];
        $backlog = FactsMother::facts(card: FactsMother::card(slot: '@backlog', isChild: true, documents: $documents, parentSlot: 'implementation'));

        self::assertSame(['child-to-next'], $this->firingRuleIds($backlog));
        self::assertContainsEquals($toNext, $this->actions($backlog));
        self::assertNotContainsEquals($toNext, $this->actions(FactsMother::facts(card: FactsMother::card(slot: '@backlog'))));
        $rules = $this->lifecycle()->rules;
        self::assertSame('child-to-next', $rules[\count($rules) - 1]->id);
    }

    public function test_an_epic_entering_implementation_evaluates_its_children_and_only_there(): void
    {
        $evaluate = $this->call('evaluate', ['cards' => 'children']);
        $epic = static fn (string $slot): Facts => FactsMother::facts(card: FactsMother::card(slot: $slot, type: 'epic', childCount: 2, openChildCount: 2), run: FactsMother::run(activeWorkerKinds: ['breakdown']));

        self::assertContains('epic-entered-implementation', $this->firingRuleIds($epic('implementation')));
        self::assertNotContains('epic-entered-implementation', $this->firingRuleIds($epic('next')));
        self::assertNotContains('epic-entered-implementation', $this->firingRuleIds(FactsMother::facts(card: FactsMother::card(slot: 'implementation'))));
        self::assertContainsEquals($evaluate, $this->actions($epic('implementation')));
    }

    public function test_an_unplanned_child_gets_its_ask_in_next_and_not_in_the_backlog(): void
    {
        $parentDesign = [self::approved('tech-design')];
        $inNext = FactsMother::facts(card: FactsMother::card(slot: 'next', isChild: true, parentDocuments: $parentDesign));
        $inBacklog = FactsMother::facts(card: FactsMother::card(slot: '@backlog', isChild: true, parentDocuments: $parentDesign));

        self::assertContains('unplanned-child', $this->firingRuleIds($inNext));
        self::assertNotContains('unplanned-child', $this->firingRuleIds($inBacklog));
    }

    public function test_the_lifecycle_template_lets_no_run_of_the_parent_move_a_child(): void
    {
        $moves = $this->template('lifecycle')->manualMoves;

        self::assertCount(7, $moves);
        self::assertSame([], array_filter($moves, static fn ($move): bool => ManualMoveActor::ParentRun === $move->by));
    }

    public function test_a_child_in_next_waits_while_any_run_of_its_epic_is_open(): void
    {
        $toImplementation = $this->call('move', ['to' => 'implementation']);
        $child = FactsMother::card(slot: 'next', isChild: true, documents: [self::approved('tech-design')], parentSlot: 'implementation');

        self::assertNotContainsEquals($toImplementation, $this->actions(FactsMother::facts(card: $child, run: FactsMother::run(parentActiveKinds: ['breakdown']))));
        self::assertNotContainsEquals($toImplementation, $this->actions(FactsMother::facts(card: $child, run: FactsMother::run(parentActiveKinds: ['implement']))));
        self::assertContainsEquals($toImplementation, $this->actions(FactsMother::facts(card: $child)));
    }

    public function test_an_epic_in_any_column_evaluates_its_children_once_no_worker_run_of_it_is_open(): void
    {
        $evaluate = $this->call('evaluate', ['cards' => 'children']);
        $epic = FactsMother::card(slot: 'next', type: 'epic', childCount: 2, openChildCount: 2);

        self::assertNotContainsEquals($evaluate, $this->actions(FactsMother::facts(card: $epic, run: FactsMother::run(activeWorkerKinds: ['breakdown']))));
        self::assertNotContainsEquals($evaluate, $this->actions(FactsMother::facts(card: $epic, run: FactsMother::run(activeWorkerKinds: ['fix']))));
        self::assertContainsEquals($evaluate, $this->actions(FactsMother::facts(card: $epic)));
        self::assertContainsEquals($evaluate, $this->actions(FactsMother::facts(card: FactsMother::card(slot: 'tech-design', type: 'epic', childCount: 2, openChildCount: 2))));
        self::assertNotContainsEquals($evaluate, $this->actions(FactsMother::facts(card: FactsMother::card(slot: 'implementation'))));
    }

    public function test_a_merged_epic_waits_for_its_open_run_and_a_merged_card_does_not(): void
    {
        $toTerminal = $this->call('move', ['to' => '@terminal']);
        $merged = FactsMother::pullRequest(state: PullRequestState::Merged, closedAt: new \DateTimeImmutable('2026-10-01 11:00:00'));
        $epic = FactsMother::card(slot: 'in-review', type: 'epic', childCount: 2);
        $feature = FactsMother::card(slot: 'in-review');
        $open = FactsMother::run(activeWorkerKinds: ['fix']);

        self::assertNotContainsEquals($toTerminal, $this->actions(FactsMother::facts(card: $epic, pullRequest: $merged, pullRequests: [$merged], run: $open)));
        self::assertContainsEquals($toTerminal, $this->actions(FactsMother::facts(card: $epic, pullRequest: $merged, pullRequests: [$merged])));
        self::assertContainsEquals($toTerminal, $this->actions(FactsMother::facts(card: $feature, pullRequest: $merged, pullRequests: [$merged], run: $open)));
    }

    /** @param list<string> $pair */
    #[DataProvider('overlaps')]
    public function test_only_one_rule_of_an_overlapping_pair_fires(Facts $facts, array $pair, ?string $fires): void
    {
        self::assertSame(null === $fires ? [] : [$fires], array_values(array_intersect($this->firingRuleIds($facts), $pair)));
    }

    /** @return iterable<string, array{Facts, list<string>, ?string}> */
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
            ['implement'],
            null,
        ];

        yield 'a product design with changes requested asks only for a revision' => [
            FactsMother::facts(card: FactsMother::card(slot: 'product-design', documents: [new DocumentFacts(tags: ['product-design'], status: 'changes-requested', id: '01a10beb-ba65-736b-8626-a6e3fa59dfc5')])),
            ['product-design-session', 'product-design-revise'],
            'product-design-revise',
        ];

        yield 'a tech design with changes requested asks only for a revision' => [
            FactsMother::facts(card: FactsMother::card(slot: 'tech-design', documents: [new DocumentFacts(tags: ['tech-design'], status: 'changes-requested', id: '01a10beb-ba65-736b-8626-a6e3fa59dfc5')])),
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

        yield 'an approved pull request into the epic branch waits while a run of its epic is open' => [
            FactsMother::facts(card: FactsMother::card(slot: 'in-review', isChild: true), pullRequest: $approvedIntoEpic, pullRequests: [$approvedIntoEpic], run: FactsMother::run(parentActiveKinds: ['fix'])),
            ['merge-ready', 'merge-ready-epic-child'],
            null,
        ];

        $approvedIntoDefault = FactsMother::pullRequest(checks: ChecksState::Passed, approvalsCoveringHead: 1);
        yield 'an approved pull request into the default branch merges through merge-ready' => [
            FactsMother::facts(card: FactsMother::card(slot: 'in-review', isChild: true), pullRequest: $approvedIntoDefault, pullRequests: [$approvedIntoDefault]),
            ['merge-ready', 'merge-ready-epic-child'],
            'merge-ready',
        ];

        $finishedEpic = FactsMother::card(slot: 'implementation', type: 'epic', childCount: 2);
        yield 'an epic with closed unmerged pull requests stays, and does not go to done' => [
            FactsMother::facts(card: $finishedEpic, pullRequest: $closed, pullRequests: [$closed]),
            ['epic-to-done', 'merged'],
            null,
        ];

        yield 'an epic with no pull request moves to done' => [
            FactsMother::facts(card: $finishedEpic),
            ['epic-to-done', 'merged'],
            'epic-to-done',
        ];

        $productDocuments = [new DocumentFacts(tags: ['product-design'], status: 'approved', id: '01a10beb-ba65-736b-8626-a6e3fa59dfc5'), new DocumentFacts(tags: ['product-design'], status: 'changes-requested', id: '01a10beb-ba65-736b-8626-a6e3fa59dfc5')];
        yield 'a product design with one document approved and one to revise stays to revise' => [
            FactsMother::facts(card: FactsMother::card(slot: 'product-design', documents: $productDocuments)),
            ['product-design-approved', 'product-design-revise'],
            'product-design-revise',
        ];

        $designDocuments = [new DocumentFacts(tags: ['tech-design'], status: 'approved', id: '01a10beb-ba65-736b-8626-a6e3fa59dfc5'), new DocumentFacts(tags: ['tech-design'], status: 'changes-requested', id: '01a10beb-ba65-736b-8626-a6e3fa59dfc5')];
        yield 'a tech design with one document approved and one to revise stays to revise' => [
            FactsMother::facts(card: FactsMother::card(slot: 'tech-design', documents: $designDocuments)),
            ['tech-design-approved', 'tech-design-revise'],
            'tech-design-revise',
        ];

        $justClosed = FactsMother::pullRequest(state: PullRequestState::Closed, closedAt: new \DateTimeImmutable('2026-10-01 11:55:00'));
        yield 'a child whose pull request just closed stays in next' => [
            FactsMother::facts(card: FactsMother::card(slot: 'next', isChild: true, parentSlot: 'implementation'), pullRequest: $justClosed, pullRequests: [$justClosed]),
            ['child-unblocked', 'pull-request-reopened'],
            null,
        ];

        $mergedStacked = FactsMother::pullRequest(state: PullRequestState::Merged, stacked: true, parentMerged: true, closedAt: new \DateTimeImmutable('2026-10-01 11:00:00'));
        yield 'a merged stacked pull request asks for no base change' => [
            FactsMother::facts(card: FactsMother::card(slot: 'in-review'), pullRequest: $mergedStacked, pullRequests: [$mergedStacked]),
            ['rebase-stacked', 'merged'],
            'merged',
        ];
    }

    public function test_an_epic_child_pull_request_merges_into_the_epic_branch_with_no_approval(): void
    {
        $merge = $this->call('forge-write', ['write' => 'merge', 'fallback' => 'merge']);
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
        $update = $this->call('forge-write', ['write' => 'update-branch', 'fallback' => 'sync']);
        $child = FactsMother::card(slot: 'in-review', isChild: true);

        $intoEpic = FactsMother::pullRequest(checks: ChecksState::Passed, behind: true, baseIsEpicBranch: true);
        self::assertContainsEquals($update, $this->actions(FactsMother::facts(card: $child, pullRequest: $intoEpic, pullRequests: [$intoEpic])));

        $intoDefault = FactsMother::pullRequest(checks: ChecksState::Passed, behind: true);
        self::assertNotContainsEquals($update, $this->actions(FactsMother::facts(card: $child, pullRequest: $intoDefault, pullRequests: [$intoDefault])));

        $merged = FactsMother::pullRequest(state: PullRequestState::Merged, checks: ChecksState::Passed, behind: true, approvalsCoveringHead: 1, baseIsEpicBranch: true);
        self::assertNotContainsEquals($update, $this->actions(FactsMother::facts(card: $child, pullRequest: $merged, pullRequests: [$merged])));
    }

    public function test_a_stage_slot_reads_the_tag_of_its_document(): void
    {
        $lifecycle = $this->lifecycle();

        self::assertSame(['product-design'], $lifecycle->documentTagsFor('product-design'));
        self::assertSame(['tech-design'], $lifecycle->documentTagsFor('tech-design'));
        self::assertSame([], $lifecycle->documentTagsFor('implementation'));
        self::assertSame([], $lifecycle->documentTagsFor(null));
        self::assertSame([], $this->template('simple')->documentTagsFor(null));
    }

    public function test_a_revision_request_names_the_document_it_revises_by_the_tag_its_rule_reads(): void
    {
        $params = [];
        foreach ($this->lifecycle()->rules as $rule) {
            if (\in_array($rule->id, ['product-design-revise', 'tech-design-revise'], true)) {
                $params[$rule->id] = [$rule->then->params[TemplateParser::DOCUMENT_TAG] ?? null, $rule->then->params[TemplateParser::DOCUMENT_STATUS] ?? null];
            }
        }

        self::assertSame(['product-design-revise' => ['product-design', 'changes-requested'], 'tech-design-revise' => ['tech-design', 'changes-requested']], $params);
    }

    /** @param array<string, int|string> $params */
    private function call(string $key, array $params): ActionCall
    {
        $actions = self::getContainer()->get(Actions::class);
        self::assertInstanceOf(Actions::class, $actions);

        return $actions->call($key, $params);
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
        foreach ($this->lifecycle()->rulesFor($facts->slot) as $rule) {
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
        foreach ($this->lifecycle()->rulesFor($facts->slot) as $rule) {
            if ($rule->when->evaluate($facts)) {
                $actions[] = $rule->then;
            }
        }

        return $actions;
    }

    /** @param list<DocumentFacts> $documents */
    private static function nextChild(array $documents): Facts
    {
        return FactsMother::facts(card: FactsMother::card(slot: 'next', isChild: true, documents: $documents, parentSlot: 'implementation'));
    }

    private static function approved(string $tag): DocumentFacts
    {
        return new DocumentFacts(tags: [$tag], status: 'approved', id: '01a10beb-ba65-736b-8626-a6e3fa59dfc5');
    }
}
