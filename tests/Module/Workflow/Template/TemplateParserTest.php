<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Template;

use App\Module\Workflow\Action\Actions;
use App\Module\Workflow\Action\Ask;
use App\Module\Workflow\Action\Detach;
use App\Module\Workflow\Action\EvaluateChildren;
use App\Module\Workflow\Action\ForgeWrite;
use App\Module\Workflow\Action\LinkDocument;
use App\Module\Workflow\Action\MoveCard;
use App\Module\Workflow\Action\PauseCard;
use App\Module\Workflow\Action\ReleasePause;
use App\Module\Workflow\Action\RequestWork;
use App\Module\Workflow\Condition\CardChildrenFinished;
use App\Module\Workflow\Condition\CardDocument;
use App\Module\Workflow\Condition\CardDocumentApproved;
use App\Module\Workflow\Condition\CardHasOpenBlocker;
use App\Module\Workflow\Condition\CardHasType;
use App\Module\Workflow\Condition\CardInSlot;
use App\Module\Workflow\Condition\Conditions;
use App\Module\Workflow\Condition\PullRequestApprovalCoversHead;
use App\Module\Workflow\Condition\PullRequestOpen;
use App\Module\Workflow\Condition\RunWorkActive;
use App\Module\Workflow\Contract\Action;
use App\Module\Workflow\Contract\LabelTone;
use App\Module\Workflow\Expression\AnyOf;
use App\Module\Workflow\Expression\ConditionLeaf;
use App\Module\Workflow\Expression\MissingConditionLeaf;
use App\Module\Workflow\Expression\Not;
use App\Module\Workflow\Template\AppRequest;
use App\Module\Workflow\Template\AskOption;
use App\Module\Workflow\Template\InvalidTemplate;
use App\Module\Workflow\Template\ManualMoveActor;
use App\Module\Workflow\Template\RuleOrigin;
use App\Module\Workflow\Template\TemplateParser;
use App\Tests\Module\Workflow\Action\PluggedAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TemplateParserTest extends TestCase
{
    private TemplateParser $parser;

    private Actions $actions;

    #[\Override]
    protected function setUp(): void
    {
        $this->actions = new Actions(array_map(
            static fn (string $class): Action => new \ReflectionClass($class)->newInstanceWithoutConstructor(),
            [MoveCard::class, RequestWork::class, ForgeWrite::class, PauseCard::class, ReleasePause::class, EvaluateChildren::class, Ask::class, LinkDocument::class, Detach::class],
        ));
        $this->parser = new TemplateParser(new Conditions([
            new CardChildrenFinished(),
            new CardDocument(),
            new CardDocumentApproved(),
            new CardHasOpenBlocker(),
            new CardHasType(),
            new CardInSlot(),
            new PullRequestApprovalCoversHead(),
            new PullRequestOpen(),
            new RunWorkActive(),
        ]), $this->actions);
    }

    /** @return array<string, mixed> */
    private static function valid(): array
    {
        return [
            'key' => 'test',
            'version' => 1,
            'defaultType' => 'feature',
            'types' => [
                ['key' => 'feature', 'label' => 'board.card.type.feature', 'tone' => 'lime'],
                ['key' => 'bug', 'label' => 'board.card.type.bug', 'tone' => 'amber'],
                ['key' => 'epic', 'label' => 'board.card.type.epic', 'tone' => 'blue', 'capabilities' => ['children', 'lane']],
            ],
            'slots' => [
                ['key' => 'build', 'label' => 'workflow.slot.build'],
                ['key' => 'review', 'label' => 'workflow.slot.review'],
            ],
            'manualMoves' => [
                ['from' => '@backlog', 'to' => 'build'],
                ['from' => '*', 'to' => '*'],
                ['from' => '@backlog', 'to' => 'review', 'by' => 'parent-run'],
            ],
            'backoffMinutes' => [10, 60],
            'workTimeoutMinutes' => 120,
            'rules' => [
                [
                    'id' => 'start',
                    'slot' => 'build',
                    'when' => ['all' => [
                        ['card.type' => ['type' => 'feature']],
                        ['not' => ['card.has_open_blocker' => []]],
                    ]],
                    'then' => ['request' => ['kind' => 'implement', 'capability' => 'interactive', 'limit' => 3]],
                ],
                [
                    'id' => 'to-review',
                    'slot' => 'build',
                    'when' => ['any' => [
                        ['pr.open' => []],
                        ['card.in_slot' => ['slot' => 'review']],
                    ]],
                    'then' => ['move' => ['to' => 'review']],
                ],
                [
                    'id' => 'merge',
                    'slot' => 'review',
                    'when' => ['pr.approval_covers_head' => ['min' => 1]],
                    'then' => ['forge-write' => ['write' => 'merge', 'fallback' => 'merge']],
                ],
                [
                    'id' => 'wait',
                    'when' => ['run.work_active' => []],
                    'then' => ['pause' => ['reason' => 'busy', 'until' => ['not' => ['run.work_active' => []]]]],
                ],
                [
                    'id' => 'done',
                    'slot' => '@terminal',
                    'when' => ['card.document_approved' => ['tag' => 'design']],
                    'then' => ['release' => ['reason' => 'busy']],
                ],
            ],
        ];
    }

    public function test_it_parses_a_valid_template(): void
    {
        $template = $this->parser->parse(self::valid());

        self::assertSame('test', $template->key);
        self::assertSame(1, $template->version);
        self::assertSame(['build', 'review'], array_map(static fn ($slot) => $slot->key, $template->slots));
        self::assertSame('workflow.slot.review', $template->slot('review')?->label);
        self::assertNull($template->slot('nowhere'));
        self::assertSame([10, 60], $template->backoffMinutes);
        self::assertSame(120, $template->workTimeoutMinutes);
        self::assertSame('@backlog', $template->manualMoves[0]->from);
        self::assertSame('build', $template->manualMoves[0]->to);
        self::assertNull($template->manualMoves[0]->by);
        self::assertSame(ManualMoveActor::ParentRun, $template->manualMoves[2]->by);

        $ids = static fn (array $rules): array => array_map(static fn ($rule) => $rule->id, $rules);
        self::assertSame(['start', 'to-review', 'wait'], $ids($template->rulesFor('build')));
        self::assertSame(['wait', 'done'], $ids($template->rulesFor('@terminal')));
        self::assertSame(['wait'], $ids($template->rulesFor(null)));

        $start = $template->rulesFor('build')[0];
        self::assertSame('request', $start->then->key);
        self::assertSame(['kind' => 'implement', 'capability' => 'interactive', 'limit' => 3], $start->then->params);
        self::assertNull($start->then->until);
        self::assertNull($start->then->refill);

        $wait = $template->rulesFor(null)[0];
        self::assertSame('pause', $wait->then->key);
        self::assertSame(['reason' => 'busy'], $wait->then->params);
        self::assertInstanceOf(Not::class, $wait->then->until);
    }

    public function test_it_reads_the_card_types_and_the_default_type(): void
    {
        $template = $this->parser->parse(self::valid());

        self::assertSame(['feature', 'bug', 'epic'], array_map(static fn ($type) => $type->key, $template->types));
        self::assertSame('feature', $template->defaultType);
        $epic = $template->type('epic') ?? throw new \LogicException('The template declares an epic.');
        self::assertSame('board.card.type.epic', $epic->label);
        self::assertSame(LabelTone::Blue, $epic->tone);
        self::assertTrue($epic->children);
        self::assertTrue($epic->lane);
        $bug = $template->type('bug') ?? throw new \LogicException('The template declares a bug.');
        self::assertSame(LabelTone::Amber, $bug->tone);
        self::assertFalse($bug->children);
        self::assertFalse($bug->lane);
        self::assertNull($template->type('chore'));
    }

    public function test_a_type_with_children_may_have_a_rule_that_reads_them(): void
    {
        $template = self::valid();
        $template['rules'][0]['when'] = ['all' => [['card.type' => ['type' => 'epic']], ['card.children_finished' => []]]];
        $template['rules'][4]['when'] = ['card.type' => ['type' => 'epic']];
        $template['rules'][4]['then'] = ['evaluate' => ['cards' => 'children']];

        self::assertCount(5, $this->parser->parse($template)->rules);
    }

    public function test_a_type_without_children_under_a_not_or_in_another_branch_does_not_read_them(): void
    {
        $template = self::valid();
        $template['rules'][0]['when'] = ['all' => [
            ['not' => ['card.type' => ['type' => 'bug']]],
            ['any' => [['card.type' => ['type' => 'bug']], ['card.children_finished' => []]]],
        ]];
        $template['rules'][4]['when'] = ['all' => [['not' => ['card.type' => ['type' => 'bug']]]]];
        $template['rules'][4]['then'] = ['evaluate' => ['cards' => 'children']];

        self::assertCount(5, $this->parser->parse($template)->rules);
    }

    public function test_many_any_lists_beside_a_child_read_parse_without_expanding_every_combination(): void
    {
        $template = self::valid();
        $template['rules'][0]['when'] = ['all' => [
            ['card.type' => ['type' => 'epic']],
            ['card.children_finished' => []],
            ...array_fill(0, 30, ['any' => [['pr.open' => []], ['card.type' => ['type' => 'epic']]]]),
        ]];

        self::assertCount(5, $this->parser->parse($template)->rules);
    }

    public function test_a_pause_until_that_reads_children_only_for_epics_allows_a_rule_that_also_matches_bugs(): void
    {
        $template = self::valid();
        $template['rules'][3]['when'] = ['any' => [['card.type' => ['type' => 'bug']], ['card.type' => ['type' => 'epic']]]];
        $template['rules'][3]['then']['pause']['until'] = ['all' => [['card.type' => ['type' => 'epic']], ['card.children_finished' => []]]];

        self::assertCount(5, $this->parser->parse($template)->rules);
    }

    public function test_a_branch_that_names_a_second_type_never_holds_and_reads_nothing(): void
    {
        $template = self::valid();
        $template['rules'][0]['when'] = ['all' => [
            ['card.type' => ['type' => 'bug']],
            ['any' => [['pr.open' => []], ['all' => [['card.type' => ['type' => 'epic']], ['card.children_finished' => []]]]]],
        ]];

        self::assertCount(5, $this->parser->parse($template)->rules);
    }

    public function test_a_template_reads_the_retry_policy_for_a_refused_request(): void
    {
        self::assertNull($this->parser->parse(self::valid())->onWorkFailed);

        $policy = $this->parser->parse(self::valid() + ['onWorkFailed' => ['retryOn' => ['failed', 'timeout'], 'retries' => 1, 'backoffMinutes' => [2, 3]]])->onWorkFailed;

        self::assertNotNull($policy);
        self::assertSame(1, $policy->retries);
        self::assertSame([2, 3], $policy->backoffMinutes);
        self::assertTrue($policy->retries('timeout'));
        self::assertFalse($policy->retries('needs-person'));
        self::assertNull($policy->repairKind);
    }

    public function test_a_retry_policy_reads_the_kind_of_its_repair_request(): void
    {
        $policy = $this->parser->parse(self::valid() + ['onWorkFailed' => ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [2], 'repair' => ['kind' => 'repair']]])->onWorkFailed;

        self::assertSame('repair', $policy?->repairKind);
    }

    public function test_an_evaluate_action_names_the_children(): void
    {
        $template = self::valid();
        $template['rules'][4]['then'] = ['evaluate' => ['cards' => 'children']];

        $then = $this->parser->parse($template)->rulesFor('@terminal')[1]->then;
        self::assertSame('evaluate', $then->key);
        self::assertSame(['cards' => 'children'], $then->params);
    }

    public function test_a_state_write_and_the_epic_opening_need_no_fallback(): void
    {
        foreach (['draft', 'ready', 'close', 'open-epic'] as $write) {
            $template = self::valid();
            $template['rules'][2]['then'] = ['forge-write' => ['write' => $write]];

            self::assertSame(['write' => $write], $this->parser->parse($template)->rulesFor('review')[0]->then->params);
        }
    }

    public function test_the_parameters_of_an_action_come_from_its_declarations(): void
    {
        $parser = new TemplateParser(new Conditions([new CardInSlot()]), new Actions([new PluggedAction()]));
        $template = self::valid();
        $template['rules'] = [['id' => 'plugged', 'when' => ['card.in_slot' => ['slot' => 'build']], 'then' => ['plugged' => ['from' => 'review', 'times' => 2, 'mode' => 'fast']]]];

        $parsed = $parser->parse($template);
        $call = $parsed->rules[0]->then;

        self::assertSame('plugged', $call->key);
        self::assertSame(['from' => 'review', 'times' => 2, 'mode' => 'fast'], $call->params);
        self::assertSame('review', $call->from);
        self::assertTrue($call->traits->endsPass);
        self::assertSame([], $parsed->rulesFor('build'), 'A rule that acts from another slot is not a rule of this slot.');
        self::assertCount(1, $parsed->rulesFor('review'));
    }

    /** @param array<string, mixed> $then */
    #[DataProvider('pluggedActionErrors')]
    public function test_the_declared_parameters_of_an_action_are_checked(array $then, string $error): void
    {
        $parser = new TemplateParser(new Conditions([new CardInSlot()]), new Actions([new PluggedAction()]));
        $template = self::valid();
        $template['rules'] = [['id' => 'plugged', 'when' => ['card.in_slot' => ['slot' => 'build']], 'then' => ['plugged' => $then]]];

        try {
            $parser->parse($template);
            self::fail('The template must be refused.');
        } catch (InvalidTemplate $e) {
            self::assertSame([$error], $e->errors);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function pluggedActionErrors(): iterable
    {
        yield 'a missing required parameter' => [['from' => 'review'], 'rules[0] (plugged) then.plugged: missing parameter "times"'];
        yield 'a slot that is not declared' => [['from' => 'shipping', 'times' => 2], 'rules[0] (plugged) then.plugged.from: unknown slot "shipping"'];
        yield 'an int under its minimum' => [['from' => 'review', 'times' => 1], 'rules[0] (plugged) then.plugged: parameter "times" must be an integer of at least 2'];
        yield 'a value outside the choices' => [['from' => 'review', 'times' => 2, 'mode' => 'slow'], 'rules[0] (plugged) then.plugged: parameter "mode" must be one of fast, safe'];
        yield 'a parameter the action does not declare' => [['from' => 'review', 'times' => 2, 'speed' => 1], 'rules[0] (plugged) then.plugged: unknown parameter "speed"'];
        yield 'a rule on the parameters as a whole' => [['from' => 'review', 'times' => 2, 'mode' => 'safe'], 'rules[0] (plugged) then.plugged: safe mode needs the slot build'];
    }

    public function test_the_prompt_is_a_parameter_of_an_app_rule_only(): void
    {
        $rules = [['id' => 'groom', 'slot' => '@backlog', 'when' => ['card.in_slot' => ['slot' => '@backlog']], 'then' => ['request' => ['kind' => 'groom', 'prompt' => 'groom-card']]]];

        self::assertSame('groom-card', $this->parser->parseAppRules(['rules' => $rules])[0]->then->params['prompt']);
        $template = self::valid();
        $template['rules'] = $rules;
        $this->expectException(InvalidTemplate::class);
        $this->expectExceptionMessage('unknown parameter "prompt"');

        $this->parser->parse($template);
    }

    public function test_a_request_can_expire_with_no_pause(): void
    {
        $template = self::valid();
        $template['rules'][0]['then'] = ['request' => ['kind' => 'teardown', 'onTimeout' => 'expire']];

        self::assertSame(['kind' => 'teardown', 'onTimeout' => 'expire'], $this->parser->parse($template)->rulesFor('build')[0]->then->params);
    }

    public function test_a_request_can_name_a_document_by_tag(): void
    {
        $template = self::valid();
        $template['rules'][0]['then'] = ['request' => ['kind' => 'tech-design-revise', 'document' => ['tag' => 'design']]];

        self::assertSame(['kind' => 'tech-design-revise', 'document.tag' => 'design'], $this->parser->parse($template)->rulesFor('build')[0]->then->params);
    }

    public function test_a_request_can_name_a_document_by_tag_and_status(): void
    {
        $template = self::valid();
        $template['rules'][0]['then'] = ['request' => ['kind' => 'tech-design-revise', 'document' => ['tag' => 'design', 'status' => 'changes-requested']]];

        self::assertSame(
            ['kind' => 'tech-design-revise', 'document.tag' => 'design', 'document.status' => 'changes-requested'],
            $this->parser->parse($template)->rulesFor('build')[0]->then->params,
        );
    }

    public function test_a_request_carries_its_checks_apart_from_its_params(): void
    {
        $template = self::valid();
        $template['rules'][0]['then']['request']['checks'] = ['A worktree per card', 'A preview URL per branch'];

        foreach ([$this->parser->parse(...), $this->parser->parseStored(...)] as $parse) {
            $then = $parse($template)->rulesFor('build')[0]->then;
            self::assertSame(['A worktree per card', 'A preview URL per branch'], $then->checks);
            self::assertSame(['kind' => 'implement', 'capability' => 'interactive', 'limit' => 3], $then->params);
        }
    }

    public function test_a_request_with_no_checks_has_an_empty_list(): void
    {
        self::assertSame([], $this->parser->parse(self::valid())->rulesFor('build')[0]->then->checks);
    }

    public function test_app_rules_accept_a_request_with_checks(): void
    {
        $rules = $this->parser->parseAppRules(['rules' => [
            ['id' => 'app-done', 'slot' => '@terminal', 'when' => ['all' => []], 'then' => ['request' => ['kind' => 'teardown', 'checks' => ['A teardown command']]]],
        ]]);

        self::assertSame(['A teardown command'], $rules[0]->then->checks);
    }

    public function test_app_requests_are_optional_and_parse_to_value_objects(): void
    {
        self::assertSame([], $this->parser->parseAppRequests(['rules' => []]));

        $requests = $this->parser->parseAppRequests(['requests' => [
            ['id' => 'insights.analysis', 'kind' => 'analysis', 'prompt' => 'analysis', 'checks' => ['A skill']],
        ]]);

        self::assertEquals([new AppRequest('insights.analysis', 'analysis', 'analysis', ['A skill'])], $requests);
    }

    /** @return iterable<string, array{array<mixed>, list<string>}> */
    public static function appRequestRefusals(): iterable
    {
        $request = static fn (array $change): array => ['requests' => [[...['id' => 'r', 'kind' => 'analysis', 'prompt' => 'analysis', 'checks' => ['A skill']], ...$change]]];

        yield 'not a list' => [['requests' => ['a' => 1]], ['requests: must be a list']];
        yield 'not a map' => [['requests' => ['x']], ['requests[0]: must be a map']];
        yield 'no id' => [$request(['id' => '']), ['requests[0] id: must be a non-empty string']];
        yield 'no kind' => [$request(['kind' => '']), ['requests[0] (r): parameter "kind" must be a non-empty string']];
        yield 'a prompt with a path' => [$request(['prompt' => '../secret']), ['requests[0] (r): parameter "prompt" must match [a-z][a-z0-9-], at most 40 characters']];
        yield 'empty checks' => [$request(['checks' => []]), ['requests[0] (r): parameter "checks" must be a non-empty list of non-empty strings']];
        yield 'an unknown key' => [$request(['slot' => 'x']), ['requests[0] (r): unknown key "slot"']];
        yield 'a duplicate id' => [['requests' => [
            ['id' => 'r', 'kind' => 'a', 'prompt' => 'a', 'checks' => ['c']],
            ['id' => 'r', 'kind' => 'b', 'prompt' => 'b', 'checks' => ['c']],
        ]], ['requests[1] (r): duplicate request id "r"']];
    }

    /**
     * @param array<mixed> $source
     * @param list<string> $errors
     */
    #[DataProvider('appRequestRefusals')]
    public function test_app_requests_refuse_an_invalid_source(array $source, array $errors): void
    {
        try {
            $this->parser->parseAppRequests($source);
            self::fail('The parser must refuse the app requests.');
        } catch (InvalidTemplate $e) {
            self::assertSame($errors, $e->errors);
        }
    }

    public function test_a_document_condition_takes_a_tag_and_an_optional_status(): void
    {
        $template = self::valid();
        $template['rules'][2]['when'] = ['all' => [
            ['card.document' => ['tag' => 'design']],
            ['card.document' => ['tag' => 'design', 'status' => 'in-review']],
        ]];

        $leaves = $this->parser->parse($template)->rules[2]->when->leaves();

        self::assertSame([['tag' => 'design'], ['tag' => 'design', 'status' => 'in-review']], array_map(static fn (ConditionLeaf $leaf): array => $leaf->params, $leaves));
    }

    public function test_a_slot_reads_the_tag_of_a_document_condition(): void
    {
        $template = self::valid();
        $template['rules'][0]['when'] = ['not' => ['card.document' => ['tag' => 'plan']]];

        self::assertSame(['plan'], $this->parser->parse($template)->documentTagsFor('build'));
    }

    public function test_a_valid_template_round_trips_through_json(): void
    {
        $decoded = json_decode(json_encode(self::valid(), \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        self::assertEquals($this->parser->parse(self::valid()), $this->parser->parse($decoded));
    }

    public function test_card_in_slot_accepts_the_backlog_and_terminal_flags(): void
    {
        foreach (['@backlog', '@terminal'] as $flag) {
            $source = self::valid();
            $source['rules'][1]['when']['any'][1] = ['card.in_slot' => ['slot' => $flag]];

            $leaf = $this->parser->parse($source)->rules[1]->when;
            self::assertInstanceOf(AnyOf::class, $leaf);
            self::assertEquals(new ConditionLeaf(new CardInSlot(), ['slot' => $flag]), $leaf->children[1]);
        }
    }

    /** @return iterable<string, array{\Closure(array<string, mixed>): array<string, mixed>, string}> */
    public static function refusals(): iterable
    {
        yield 'missing top-level key' => [static function (array $t): array {
            unset($t['workTimeoutMinutes']);

            return $t;
        }, 'workTimeoutMinutes: is missing'];
        yield 'wrongly typed version' => [static fn (array $t): array => ['version' => '1'] + $t, 'version: must be an integer'];
        yield 'wrongly typed key' => [static fn (array $t): array => ['key' => ''] + $t, 'key: must be a non-empty string'];
        yield 'slots not a list' => [static fn (array $t): array => ['slots' => 'build', 'manualMoves' => [], 'rules' => []] + $t, 'slots: must be a list'];
        yield 'rules not a list' => [static fn (array $t): array => ['rules' => ['a' => 1]] + $t, 'rules: must be a list'];
        yield 'manual moves not a list' => [static fn (array $t): array => ['manualMoves' => 3] + $t, 'manualMoves: must be a list'];
        yield 'wrongly typed backoff' => [static fn (array $t): array => ['backoffMinutes' => [10, '60']] + $t, 'backoffMinutes: must be a list of positive integers'];
        yield 'retry policy with no list' => [static fn (array $t): array => ['onWorkFailed' => ['retries' => 1, 'backoffMinutes' => [2]]] + $t, 'onWorkFailed: must be a map with a list "retryOn"'];
        yield 'retry policy with a bad code' => [static fn (array $t): array => ['onWorkFailed' => ['retryOn' => ['Not A Code'], 'retries' => 1, 'backoffMinutes' => [2]]] + $t, 'onWorkFailed.retryOn: each entry must be a refusal code'];
        yield 'repair that is not a map' => [static fn (array $t): array => ['onWorkFailed' => ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [2], 'repair' => 'repair']] + $t, 'onWorkFailed.repair: must be a map with a string "kind"'];
        yield 'repair with no kind' => [static fn (array $t): array => ['onWorkFailed' => ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [2], 'repair' => []]] + $t, 'onWorkFailed.repair: must be a map with a string "kind"'];
        yield 'repair with a bad kind' => [static fn (array $t): array => ['onWorkFailed' => ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [2], 'repair' => ['kind' => 'Not A Kind']]] + $t, 'onWorkFailed.repair.kind: must be a work request kind'];
        yield 'repair kind that a rule asks for' => [static fn (array $t): array => ['onWorkFailed' => ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [2], 'repair' => ['kind' => 'implement']]] + $t, 'onWorkFailed.repair.kind: the rule "start" already asks for the kind "implement"'];
        yield 'repair kind that a forge write falls back to' => [static fn (array $t): array => ['onWorkFailed' => ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [2], 'repair' => ['kind' => 'merge']]] + $t, 'onWorkFailed.repair.kind: the rule "merge" already asks for the kind "merge"'];
        yield 'retry policy with a negative count' => [static fn (array $t): array => ['onWorkFailed' => ['retryOn' => ['failed'], 'retries' => -1, 'backoffMinutes' => [2]]] + $t, 'onWorkFailed.retries: must be a non-negative integer'];
        yield 'retry policy with no delays' => [static fn (array $t): array => ['onWorkFailed' => ['retryOn' => ['failed'], 'retries' => 1]] + $t, 'onWorkFailed.backoffMinutes: must be a list of positive integers'];
        yield 'retry policy with a zero delay' => [static fn (array $t): array => ['onWorkFailed' => ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [0]]] + $t, 'onWorkFailed.backoffMinutes: must be a list of positive integers'];
        yield 'retry policy with a text delay' => [static fn (array $t): array => ['onWorkFailed' => ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => 'x']] + $t, 'onWorkFailed.backoffMinutes: must be a list of positive integers'];
        yield 'wrongly typed work timeout' => [static fn (array $t): array => ['workTimeoutMinutes' => 0] + $t, 'workTimeoutMinutes: must be a positive integer'];

        yield 'missing types' => [static function (array $t): array {
            unset($t['types']);

            return $t;
        }, 'types: is missing'];
        yield 'empty types' => [static fn (array $t): array => ['types' => []] + $t, 'types: must be a non-empty list'];
        yield 'missing default type' => [static function (array $t): array {
            unset($t['defaultType']);

            return $t;
        }, 'defaultType: is missing'];
        yield 'default type that is not a string' => [static fn (array $t): array => ['defaultType' => 3] + $t, 'defaultType: must be a non-empty string'];
        yield 'default type that is not declared' => [static fn (array $t): array => ['defaultType' => 'chore'] + $t, 'defaultType: unknown type "chore"'];
        yield 'default type that may have children' => [static fn (array $t): array => ['defaultType' => 'epic'] + $t, 'defaultType: type "epic" may have children'];
        yield 'type with no label' => [static function (array $t): array {
            unset($t['types'][1]['label']);

            return $t;
        }, 'types[1]: must be a map with a string "key" and a string "label"'];
        yield 'type with a bad tone' => [static function (array $t): array {
            $t['types'][1]['tone'] = 'beige';

            return $t;
        }, 'types[1] (bug): "tone" must be one of neutral, lime, purple, green, amber, red, teal, sky, blue, indigo, pink, orange'];
        yield 'type with no tone' => [static function (array $t): array {
            unset($t['types'][1]['tone']);

            return $t;
        }, 'types[1] (bug): "tone" must be one of neutral, lime, purple, green, amber, red, teal, sky, blue, indigo, pink, orange'];
        yield 'type with an unknown capability' => [static function (array $t): array {
            $t['types'][1]['capabilities'] = ['children', 'swimming'];

            return $t;
        }, 'types[1] (bug): "capabilities" must be a list of children, lane'];
        yield 'type with an unknown key' => [static function (array $t): array {
            $t['types'][1]['colour'] = 'amber';

            return $t;
        }, 'types[1] (bug): unknown key "colour"'];
        yield 'duplicate type key' => [static function (array $t): array {
            $t['types'][] = ['key' => 'bug', 'label' => 'board.card.type.bug', 'tone' => 'red'];

            return $t;
        }, 'types[3] (bug): duplicate type key "bug"'];
        yield 'type key longer than the card column' => [static function (array $t): array {
            $t['types'][1]['key'] = 'a-type-key-of-21-char';

            return $t;
        }, 'types[1] (a-type-key-of-21-char): "key" must have at most 20 characters'];
        yield 'card type condition naming an undeclared type' => [static function (array $t): array {
            $t['rules'][0]['when']['all'][0] = ['card.type' => ['type' => 'chore']];

            return $t;
        }, 'rules[0] (start) when.all[0]: card.type: unknown type "chore"'];
        yield 'pause until naming an undeclared type' => [static function (array $t): array {
            $t['rules'][3]['then']['pause']['until'] = ['card.type' => ['type' => 'chore']];

            return $t;
        }, 'rules[3] (wait) then.pause.until: card.type: unknown type "chore"'];
        yield 'request refill naming an undeclared type' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['refill'] = ['card.type' => ['type' => 'chore']];

            return $t;
        }, 'rules[0] (start) then.request.refill: card.type: unknown type "chore"'];
        yield 'rule reading the children of a type without children' => [static function (array $t): array {
            $t['rules'][0]['when'] = ['all' => [['card.type' => ['type' => 'bug']], ['card.children_finished' => []]]];

            return $t;
        }, 'rules[0] (start): the type "bug" may not have children, but the rule reads them'];
        yield 'nested all reading the children of a type without children' => [static function (array $t): array {
            $t['rules'][1]['when']['any'][] = ['all' => [['card.children_finished' => []], ['card.type' => ['type' => 'feature']]]];

            return $t;
        }, 'rules[1] (to-review): the type "feature" may not have children, but the rule reads them'];
        yield 'all inside all reading the children of a type without children' => [static function (array $t): array {
            $t['rules'][0]['when'] = ['all' => [['card.type' => ['type' => 'bug']], ['all' => [['card.children_finished' => []]]]]];

            return $t;
        }, 'rules[0] (start): the type "bug" may not have children, but the rule reads them'];
        yield 'any branch reading the children of the type around it' => [static function (array $t): array {
            $t['rules'][0]['when'] = ['all' => [['card.type' => ['type' => 'bug']], ['any' => [['card.children_finished' => []], ['pr.open' => []]]]]];

            return $t;
        }, 'rules[0] (start): the type "bug" may not have children, but the rule reads them'];
        yield 'any branch naming a type that the list around it reads the children of' => [static function (array $t): array {
            $t['rules'][0]['when'] = ['all' => [['card.children_finished' => []], ['any' => [['card.type' => ['type' => 'bug']], ['pr.open' => []]]]]];

            return $t;
        }, 'rules[0] (start): the type "bug" may not have children, but the rule reads them'];
        yield 'two sibling any lists, one naming the type and one reading the children' => [static function (array $t): array {
            $t['rules'][0]['when'] = ['all' => [
                ['any' => [['card.type' => ['type' => 'bug']], ['pr.open' => []]]],
                ['any' => [['card.children_finished' => []], ['pr.open' => []]]],
            ]];

            return $t;
        }, 'rules[0] (start): the type "bug" may not have children, but the rule reads them'];
        yield 'negated child read beside a type without children' => [static function (array $t): array {
            $t['rules'][0]['when'] = ['all' => [['card.type' => ['type' => 'bug']], ['not' => ['card.children_finished' => []]]]];

            return $t;
        }, 'rules[0] (start): the type "bug" may not have children, but the rule reads them'];
        yield 'pause until reading the children of a type without children' => [static function (array $t): array {
            $t['rules'][3]['when'] = ['card.type' => ['type' => 'bug']];
            $t['rules'][3]['then']['pause']['until'] = ['card.children_finished' => []];

            return $t;
        }, 'rules[3] (wait): the type "bug" may not have children, but the rule reads them'];
        yield 'when reading the children of a type without children beside an until for another type' => [static function (array $t): array {
            $t['rules'][3]['when'] = ['all' => [['card.type' => ['type' => 'bug']], ['card.children_finished' => []]]];
            $t['rules'][3]['then']['pause']['until'] = ['card.type' => ['type' => 'epic']];

            return $t;
        }, 'rules[3] (wait): the type "bug" may not have children, but the rule reads them'];
        yield 'evaluate of the children of a type without children' => [static function (array $t): array {
            $t['rules'][4]['when'] = ['card.type' => ['type' => 'bug']];
            $t['rules'][4]['then'] = ['evaluate' => ['cards' => 'children']];

            return $t;
        }, 'rules[4] (done): the type "bug" may not have children, but the rule reads them'];

        yield 'duplicate slot key' => [static function (array $t): array {
            $t['slots'][] = ['key' => 'build', 'label' => 'workflow.slot.build'];

            return $t;
        }, 'slots[2]: duplicate slot key "build"'];
        yield 'slot key with an at sign' => [static function (array $t): array {
            $t['slots'][] = ['key' => '@done', 'label' => 'workflow.slot.done'];

            return $t;
        }, 'slots[2]: slot key "@done" must not start with "@"'];
        yield 'wildcard slot key' => [static function (array $t): array {
            $t['slots'][] = ['key' => '*', 'label' => 'workflow.slot.any'];

            return $t;
        }, 'slots[2]: slot key "*" is reserved'];
        yield 'action with two keys' => [static function (array $t): array {
            $t['rules'][1]['then'] = ['move' => ['to' => 'review'], 'release' => ['reason' => 'busy']];

            return $t;
        }, 'rules[1] (to-review) then: an action must have exactly one key'];
        yield 'slot with no label' => [static function (array $t): array {
            $t['slots'][] = ['key' => 'ship'];

            return $t;
        }, 'slots[2]: must be a map with a string "key" and a string "label"'];

        yield 'duplicate rule id' => [static function (array $t): array {
            $t['rules'][4]['id'] = 'start';

            return $t;
        }, 'rules[4] (start): duplicate rule id "start"'];

        yield 'unknown rule key' => [static function (array $t): array {
            $t['rules'][0]['solt'] = 'build';

            return $t;
        }, 'rules[0] (start): unknown key "solt"'];
        yield 'rule with no when' => [static function (array $t): array {
            unset($t['rules'][0]['when']);

            return $t;
        }, 'rules[0] (start) when: is missing'];

        yield 'unknown rule slot' => [static function (array $t): array {
            $t['rules'][0]['slot'] = 'shipping';

            return $t;
        }, 'rules[0] (start) slot: unknown slot "shipping"'];
        yield 'unknown move target' => [static function (array $t): array {
            $t['rules'][1]['then']['move']['to'] = 'shipping';

            return $t;
        }, 'rules[1] (to-review) then.move.to: unknown slot "shipping"'];
        yield 'unknown move source' => [static function (array $t): array {
            $t['rules'][1]['then']['move']['from'] = 'shipping';

            return $t;
        }, 'rules[1] (to-review) then.move.from: unknown slot "shipping"'];
        yield 'unknown manual move end' => [static function (array $t): array {
            $t['manualMoves'][0]['from'] = 'shipping';

            return $t;
        }, 'manualMoves[0].from: unknown slot "shipping"'];
        yield 'unknown manual move actor' => [static function (array $t): array {
            $t['manualMoves'][2]['by'] = 'anyone';

            return $t;
        }, 'manualMoves[2].by: must be one of parent-run'];
        yield 'wildcard rule slot' => [static function (array $t): array {
            $t['rules'][0]['slot'] = '*';

            return $t;
        }, 'rules[0] (start) slot: unknown slot "*"'];

        yield 'node with two keys' => [static function (array $t): array {
            $t['rules'][2]['when'] = ['pr.open' => [], 'card.has_open_blocker' => []];

            return $t;
        }, 'rules[2] (merge) when: a node must have exactly one key'];
        yield 'node with no key' => [static function (array $t): array {
            $t['rules'][0]['when']['all'][0] = [];

            return $t;
        }, 'rules[0] (start) when.all[0]: a node must have exactly one key'];
        yield 'empty any' => [static function (array $t): array {
            $t['rules'][1]['when'] = ['any' => []];

            return $t;
        }, 'rules[1] (to-review) when.any: must be a non-empty list'];

        yield 'unknown condition' => [static function (array $t): array {
            $t['rules'][2]['when'] = ['pr.foo' => []];

            return $t;
        }, 'rules[2] (merge) when: unknown condition "pr.foo"'];

        yield 'missing parameter' => [static function (array $t): array {
            $t['rules'][2]['when'] = ['pr.approval_covers_head' => []];

            return $t;
        }, 'rules[2] (merge) when: pr.approval_covers_head: missing parameter "min"'];
        yield 'unknown parameter' => [static function (array $t): array {
            $t['rules'][0]['when']['all'][1]['not'] = ['card.has_open_blocker' => ['strict' => true]];

            return $t;
        }, 'rules[0] (start) when.all[1].not: card.has_open_blocker: unknown parameter "strict"'];
        yield 'wrongly typed parameter' => [static function (array $t): array {
            $t['rules'][2]['when'] = ['pr.approval_covers_head' => ['min' => '1']];

            return $t;
        }, 'rules[2] (merge) when: pr.approval_covers_head: parameter "min" must be a positive integer'];
        yield 'zero integer parameter' => [static function (array $t): array {
            $t['rules'][2]['when'] = ['pr.approval_covers_head' => ['min' => 0]];

            return $t;
        }, 'rules[2] (merge) when: pr.approval_covers_head: parameter "min" must be a positive integer'];
        yield 'negative integer parameter' => [static function (array $t): array {
            $t['rules'][2]['when'] = ['pr.approval_covers_head' => ['min' => -5]];

            return $t;
        }, 'rules[2] (merge) when: pr.approval_covers_head: parameter "min" must be a positive integer'];
        yield 'slot parameter naming no slot' => [static function (array $t): array {
            $t['rules'][1]['when']['any'][1] = ['card.in_slot' => ['slot' => 'shipping']];

            return $t;
        }, 'rules[1] (to-review) when.any[1]: card.in_slot: unknown slot "shipping"'];

        yield 'unknown action type' => [static function (array $t): array {
            $t['rules'][1]['then'] = ['jump' => ['to' => 'review']];

            return $t;
        }, 'rules[1] (to-review) then: unknown action "jump"'];
        yield 'missing action parameter' => [static function (array $t): array {
            $t['rules'][0]['then'] = ['request' => ['capability' => 'interactive']];

            return $t;
        }, 'rules[0] (start) then.request: missing parameter "kind"'];
        yield 'wrongly typed action parameter' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['limit'] = 'three';

            return $t;
        }, 'rules[0] (start) then.request: parameter "limit" must be a positive integer'];
        yield 'refill without a limit' => [static function (array $t): array {
            unset($t['rules'][0]['then']['request']['limit']);
            $t['rules'][0]['then']['request']['refill'] = ['pr.checks_passed' => []];

            return $t;
        }, 'rules[0] (start) then.request: parameter "refill" needs a "limit"'];
        yield 'zero request limit' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['limit'] = 0;

            return $t;
        }, 'rules[0] (start) then.request: parameter "limit" must be a positive integer'];
        yield 'unknown forge write' => [static function (array $t): array {
            $t['rules'][2]['then']['forge-write']['write'] = 'squash';

            return $t;
        }, 'rules[2] (merge) then.forge-write: parameter "write" must be one of merge, update-branch, change-base, comment, draft, ready, close, open-epic'];
        yield 'merge with no fallback' => [static function (array $t): array {
            unset($t['rules'][2]['then']['forge-write']['fallback']);

            return $t;
        }, 'rules[2] (merge) then.forge-write: missing parameter "fallback"'];
        yield 'unknown work timeout behaviour' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['onTimeout'] = 'drop';

            return $t;
        }, 'rules[0] (start) then.request: parameter "onTimeout" must be one of pause, expire'];
        yield 'a document that is not a map' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['document'] = 'design';

            return $t;
        }, 'rules[0] (start) then.request: parameter "document" must be a map with the key tag, and optionally status'];
        yield 'a document with no tag' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['document'] = [];

            return $t;
        }, 'rules[0] (start) then.request: parameter "document" must be a map with the key tag, and optionally status'];
        yield 'a document with an unknown key' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['document'] = ['tag' => 'design', 'name' => 'Design'];

            return $t;
        }, 'rules[0] (start) then.request: parameter "document" must be a map with the key tag, and optionally status'];
        yield 'a document status that is not a status' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['document'] = ['tag' => 'design', 'status' => 'rejected'];

            return $t;
        }, 'rules[0] (start) then.request.document: parameter "status" must be one of in-review, approved, changes-requested, draft'];
        yield 'a document tag that is not a string' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['document'] = ['tag' => 5];

            return $t;
        }, 'rules[0] (start) then.request.document: parameter "tag" must be a non-empty string'];
        yield 'a document on another action' => [static function (array $t): array {
            $t['rules'][2]['then']['forge-write']['document'] = ['tag' => 'design'];

            return $t;
        }, 'rules[2] (merge) then.forge-write: unknown parameter "document"'];
        yield 'an empty checks list' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['checks'] = [];

            return $t;
        }, 'rules[0] (start) then.request: parameter "checks" must be a non-empty list of non-empty strings'];
        yield 'checks as a string' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['checks'] = 'A worktree';

            return $t;
        }, 'rules[0] (start) then.request: parameter "checks" must be a non-empty list of non-empty strings'];
        yield 'checks as a map' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['checks'] = ['worktree' => 'A worktree'];

            return $t;
        }, 'rules[0] (start) then.request: parameter "checks" must be a non-empty list of non-empty strings'];
        yield 'a checks entry that is empty' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['checks'] = ['A worktree', ''];

            return $t;
        }, 'rules[0] (start) then.request: parameter "checks" must be a non-empty list of non-empty strings'];
        yield 'a checks entry that is not a string' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['checks'] = ['A worktree', 3];

            return $t;
        }, 'rules[0] (start) then.request: parameter "checks" must be a non-empty list of non-empty strings'];
        yield 'checks on another action' => [static function (array $t): array {
            $t['rules'][1]['then']['move']['checks'] = ['A worktree'];

            return $t;
        }, 'rules[1] (to-review) then.move: unknown parameter "checks"'];
        yield 'until outside a pause' => [static function (array $t): array {
            $t['rules'][4]['then']['release']['until'] = ['pr.open' => []];

            return $t;
        }, 'rules[4] (done) then.release: unknown parameter "until"'];

        yield 'document status outside the statuses' => [static function (array $t): array {
            $t['rules'][2]['when'] = ['card.document' => ['tag' => 'design', 'status' => 'rejected']];

            return $t;
        }, 'rules[2] (merge) when: card.document: parameter "status" must be one of: in-review, approved, changes-requested, draft'];
        yield 'evaluate of other cards' => [static function (array $t): array {
            $t['rules'][4]['then'] = ['evaluate' => ['cards' => 'siblings']];

            return $t;
        }, 'rules[4] (done) then.evaluate: parameter "cards" must be one of children'];
        yield 'evaluate with no cards' => [static function (array $t): array {
            $t['rules'][4]['then'] = ['evaluate' => []];

            return $t;
        }, 'rules[4] (done) then.evaluate: missing parameter "cards"'];
        yield 'pause with no until' => [static function (array $t): array {
            unset($t['rules'][3]['then']['pause']['until']);

            return $t;
        }, 'rules[3] (wait) then.pause: a pause must carry an "until" expression'];

        $ask = static fn (callable $mutate): \Closure => static function (array $t) use ($mutate): array {
            $t['rules'][] = self::askRule();
            $mutate($t['rules'][5]['then']['ask']);

            return $t;
        };
        yield 'ask nesting an ask' => [$ask(static function (array &$ask): void {
            $ask['options'][0]['then'] = [['ask' => ['question' => 'q', 'options' => [['label' => 'l', 'then' => [['detach' => []]]]]]]];
        }), 'rules[5] (unplanned) then.ask.options[0].then[0]: the action "ask" is not allowed inside an ask option'];
        yield 'ask nesting a request' => [$ask(static function (array &$ask): void {
            $ask['options'][1]['then'] = [['request' => ['kind' => 'implement']]];
        }), 'rules[5] (unplanned) then.ask.options[1].then[0]: the action "request" is not allowed inside an ask option'];
        yield 'ask nesting an unknown action' => [$ask(static function (array &$ask): void {
            $ask['options'][1]['then'] = [['jump' => []]];
        }), 'rules[5] (unplanned) then.ask.options[1].then[0]: unknown action "jump"'];
        yield 'ask nesting a move to an unknown slot' => [$ask(static function (array &$ask): void {
            $ask['options'][1]['then'] = [['move' => ['to' => 'nowhere']]];
        }), 'rules[5] (unplanned) then.ask.options[1].then[0].move.to: unknown slot "nowhere"'];
        yield 'ask with no question' => [$ask(static function (array &$ask): void {
            unset($ask['question']);
        }), 'rules[5] (unplanned) then.ask: missing parameter "question"'];
        yield 'ask with no options' => [$ask(static function (array &$ask): void {
            unset($ask['options']);
        }), 'rules[5] (unplanned) then.ask: missing parameter "options"'];
        yield 'ask with an empty options list' => [$ask(static function (array &$ask): void {
            $ask['options'] = [];
        }), 'rules[5] (unplanned) then.ask: parameter "options" must be a non-empty list of maps with a "label" and a "then" list'];
        yield 'ask option with no label' => [$ask(static function (array &$ask): void {
            unset($ask['options'][0]['label']);
        }), 'rules[5] (unplanned) then.ask.options[0]: must be a map with a non-empty string "label" and a non-empty "then" list'];
        yield 'ask option with an empty then' => [$ask(static function (array &$ask): void {
            $ask['options'][0]['then'] = [];
        }), 'rules[5] (unplanned) then.ask.options[0]: must be a map with a non-empty string "label" and a non-empty "then" list'];
        yield 'ask option with a then that is a map' => [$ask(static function (array &$ask): void {
            $ask['options'][0]['then'] = ['detach' => []];
        }), 'rules[5] (unplanned) then.ask.options[0]: must be a map with a non-empty string "label" and a non-empty "then" list'];
        yield 'ask option with an unknown key' => [$ask(static function (array &$ask): void {
            $ask['options'][0]['when'] = [];
        }), 'rules[5] (unplanned) then.ask.options[0]: unknown key "when"'];
        yield 'ask with an unknown parameter' => [$ask(static function (array &$ask): void {
            $ask['title'] = 'x';
        }), 'rules[5] (unplanned) then.ask: unknown parameter "title"'];
        yield 'link-document from the card' => [static function (array $t): array {
            $t['rules'][] = ['id' => 'link', 'when' => ['pr.open' => []], 'then' => ['link-document' => ['from' => 'card', 'tag' => 'tech-design']]];

            return $t;
        }, 'rules[5] (link) then.link-document: parameter "from" must be parent'];
        yield 'link-document with no tag' => [static function (array $t): array {
            $t['rules'][] = ['id' => 'link', 'when' => ['pr.open' => []], 'then' => ['link-document' => ['from' => 'parent']]];

            return $t;
        }, 'rules[5] (link) then.link-document: missing parameter "tag"'];
        yield 'detach with a parameter' => [static function (array $t): array {
            $t['rules'][] = ['id' => 'detach', 'when' => ['pr.open' => []], 'then' => ['detach' => ['from' => 'parent']]];

            return $t;
        }, 'rules[5] (detach) then.detach: unknown parameter "from"'];
    }

    /** @return array<string, mixed> */
    private static function askRule(): array
    {
        return [
            'id' => 'unplanned',
            'slot' => '@backlog',
            'when' => ['pr.open' => []],
            'then' => ['ask' => [
                'question' => 'workflow.ask.unplanned_child',
                'options' => [
                    ['label' => 'workflow.ask.unplanned_child.link', 'then' => [['link-document' => ['from' => 'parent', 'tag' => 'tech-design']]]],
                    ['label' => 'workflow.ask.unplanned_child.design', 'then' => [['move' => ['to' => 'build']]]],
                    ['label' => 'workflow.ask.unplanned_child.detach', 'then' => [['detach' => []], ['move' => ['to' => '@backlog']]]],
                ],
            ]],
        ];
    }

    public function test_an_ask_reads_its_question_and_its_options_with_their_nested_actions(): void
    {
        $template = self::valid();
        $template['rules'][] = self::askRule();

        $then = $this->parser->parse($template)->rules[5]->then;

        self::assertSame('ask', $then->key);
        self::assertSame(['question' => 'workflow.ask.unplanned_child'], $then->params);
        self::assertEquals([
            new AskOption('workflow.ask.unplanned_child.link', [$this->actions->call('link-document', ['from' => 'parent', 'tag' => 'tech-design'])]),
            new AskOption('workflow.ask.unplanned_child.design', [$this->actions->call('move', ['to' => 'build'])]),
            new AskOption('workflow.ask.unplanned_child.detach', [$this->actions->call('detach', []), $this->actions->call('move', ['to' => '@backlog'])]),
        ], $then->options);
    }

    public function test_an_ask_template_round_trips_through_json(): void
    {
        $template = self::valid();
        $template['rules'][] = self::askRule();
        $decoded = json_decode(json_encode($template, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        self::assertEquals($this->parser->parse($template), $this->parser->parseStored($decoded));
    }

    public function test_an_action_other_than_an_ask_has_no_options(): void
    {
        self::assertSame([], $this->parser->parse(self::valid())->rules[0]->then->options);
    }

    /** @param \Closure(array<string, mixed>): array<string, mixed> $mutate */
    #[DataProvider('refusals')]
    public function test_it_refuses_an_invalid_template(\Closure $mutate, string $message): void
    {
        try {
            $this->parser->parse($mutate(self::valid()));
            self::fail('The parser must refuse the template.');
        } catch (InvalidTemplate $e) {
            self::assertSame([$message], $e->errors);
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    /** @param \Closure(array<string, mixed>): array<string, mixed> $mutate */
    #[DataProvider('refusals')]
    public function test_a_stored_copy_refuses_every_error_but_an_unknown_condition(\Closure $mutate, string $message): void
    {
        if (str_contains($message, 'unknown condition')) {
            self::assertCount(\count(self::valid()['rules']), $this->parser->parseStored($mutate(self::valid()))->rules);

            return;
        }
        try {
            $this->parser->parseStored($mutate(self::valid()));
            self::fail('The parser must refuse the template.');
        } catch (InvalidTemplate $e) {
            self::assertSame([$message], $e->errors);
        }
    }

    public function test_a_stored_copy_keeps_an_unknown_condition_as_a_missing_condition_leaf(): void
    {
        $template = self::valid();
        $template['rules'][2]['when'] = ['not' => ['pr.foo' => ['min' => 1]]];
        $template['rules'][1]['when']['any'][0] = ['pr.bar' => 'not a map'];

        $rules = $this->parser->parseStored($template)->rules;

        $not = $rules[2]->when;
        self::assertInstanceOf(Not::class, $not);
        self::assertEquals(new MissingConditionLeaf('pr.foo', ['min' => 1]), $not->inner);
        $any = $rules[1]->when;
        self::assertInstanceOf(AnyOf::class, $any);
        self::assertEquals(new MissingConditionLeaf('pr.bar', 'not a map'), $any->children[0]);
    }

    public function test_it_reports_every_error_at_once(): void
    {
        $template = self::valid();
        $template['version'] = 'one';
        $template['rules'][2]['when'] = ['pr.foo' => []];

        try {
            $this->parser->parse($template);
            self::fail('The parser must refuse the template.');
        } catch (InvalidTemplate $e) {
            self::assertSame([
                'version: must be an integer',
                'rules[2] (merge) when: unknown condition "pr.foo"',
            ], $e->errors);
        }
    }

    public function test_app_rules_accept_a_request_with_a_prompt_in_the_backlog(): void
    {
        $rules = $this->parser->parseAppRules(['rules' => [
            ['id' => 'app-groom', 'slot' => '@backlog', 'when' => ['card.has_open_blocker' => []], 'then' => ['request' => ['kind' => 'groom', 'prompt' => 'groom-card']]],
            ['id' => 'app-done', 'slot' => '@terminal', 'when' => ['all' => []], 'then' => ['request' => ['kind' => 'teardown']]],
        ]]);

        self::assertSame(['app-groom', 'app-done'], array_map(static fn ($rule) => $rule->id, $rules));
        self::assertSame([RuleOrigin::App, RuleOrigin::App], array_map(static fn ($rule) => $rule->origin, $rules));
        self::assertSame('@backlog', $rules[0]->slot);
        self::assertSame(['kind' => 'groom', TemplateParser::PROMPT => 'groom-card'], $rules[0]->then->params);
    }

    public function test_template_rules_have_the_template_origin(): void
    {
        foreach ($this->parser->parse(self::valid())->rules as $rule) {
            self::assertSame(RuleOrigin::Template, $rule->origin);
        }
    }

    /** @return iterable<string, array{array<mixed>, list<string>}> */
    public static function appRuleRefusals(): iterable
    {
        $rule = static fn (array $then, ?string $slot = null): array => ['rules' => [array_filter(['id' => 'app-rule', 'slot' => $slot, 'when' => ['all' => []], 'then' => $then], static fn ($v) => null !== $v)]];

        yield 'a slot key' => [$rule(['request' => ['kind' => 'groom']], 'next'), ['rules[0] (app-rule) slot: unknown slot "next"']];
        yield 'a prompt name with a capital' => [$rule(['request' => ['kind' => 'groom', 'prompt' => 'Groom']]), ['rules[0] (app-rule) then.request: parameter "prompt" must match [a-z][a-z0-9-], at most 40 characters']];
        yield 'a prompt name with a path' => [$rule(['request' => ['kind' => 'groom', 'prompt' => '../secret']]), ['rules[0] (app-rule) then.request: parameter "prompt" must match [a-z][a-z0-9-], at most 40 characters']];
        yield 'a prompt name too long' => [$rule(['request' => ['kind' => 'groom', 'prompt' => str_repeat('a', 41)]]), ['rules[0] (app-rule) then.request: parameter "prompt" must match [a-z][a-z0-9-], at most 40 characters']];
        yield 'a prompt on another action' => [$rule(['release' => ['reason' => 'x', 'prompt' => 'groom']]), ['rules[0] (app-rule) then.release: unknown parameter "prompt"']];
        yield 'a move to a slot' => [$rule(['move' => ['to' => 'next']]), ['rules[0] (app-rule) then.move.to: unknown slot "next"']];
        yield 'no rules key' => [[], ['rules: is missing']];
        yield 'rules not a list' => [['rules' => ['a' => 1]], ['rules: must be a list']];
        yield 'another top-level key' => [['rules' => [], 'slots' => []], ['slots: unknown key, app rules hold only "rules" and "requests"']];
    }

    /**
     * @param array<mixed> $source
     * @param list<string> $errors
     */
    #[DataProvider('appRuleRefusals')]
    public function test_app_rules_refuse_an_invalid_source(array $source, array $errors): void
    {
        try {
            $this->parser->parseAppRules($source);
            self::fail('The parser must refuse the app rules.');
        } catch (InvalidTemplate $e) {
            self::assertSame($errors, $e->errors);
        }
    }

    public function test_a_template_refuses_a_prompt(): void
    {
        $template = self::valid();
        $template['rules'][0]['then']['request']['prompt'] = 'groom';

        foreach ([$this->parser->parse(...), $this->parser->parseStored(...)] as $parse) {
            try {
                $parse($template);
                self::fail('A template rule must not name a prompt.');
            } catch (InvalidTemplate $e) {
                self::assertSame(['rules[0] (start) then.request: unknown parameter "prompt"'], $e->errors);
            }
        }
    }
}
