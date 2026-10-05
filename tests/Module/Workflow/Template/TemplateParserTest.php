<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Template;

use App\Module\Workflow\Condition\CardDocumentApproved;
use App\Module\Workflow\Condition\CardHasOpenBlocker;
use App\Module\Workflow\Condition\CardHasType;
use App\Module\Workflow\Condition\CardInSlot;
use App\Module\Workflow\Condition\Conditions;
use App\Module\Workflow\Condition\PullRequestApprovalCoversHead;
use App\Module\Workflow\Condition\PullRequestOpen;
use App\Module\Workflow\Condition\RunWorkActive;
use App\Module\Workflow\Expression\AnyOf;
use App\Module\Workflow\Expression\ConditionLeaf;
use App\Module\Workflow\Expression\MissingConditionLeaf;
use App\Module\Workflow\Expression\Not;
use App\Module\Workflow\Template\ActionType;
use App\Module\Workflow\Template\InvalidTemplate;
use App\Module\Workflow\Template\TemplateParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TemplateParserTest extends TestCase
{
    private TemplateParser $parser;

    #[\Override]
    protected function setUp(): void
    {
        $this->parser = new TemplateParser(new Conditions([
            new CardDocumentApproved(),
            new CardHasOpenBlocker(),
            new CardHasType(),
            new CardInSlot(),
            new PullRequestApprovalCoversHead(),
            new PullRequestOpen(),
            new RunWorkActive(),
        ]));
    }

    /** @return array<string, mixed> */
    private static function valid(): array
    {
        return [
            'key' => 'test',
            'version' => 1,
            'slots' => [
                ['key' => 'build', 'label' => 'workflow.slot.build'],
                ['key' => 'review', 'label' => 'workflow.slot.review'],
            ],
            'manualMoves' => [
                ['from' => '@backlog', 'to' => 'build'],
                ['from' => '*', 'to' => '*'],
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

        $ids = static fn (array $rules): array => array_map(static fn ($rule) => $rule->id, $rules);
        self::assertSame(['start', 'to-review', 'wait'], $ids($template->rulesFor('build')));
        self::assertSame(['wait', 'done'], $ids($template->rulesFor('@terminal')));
        self::assertSame(['wait'], $ids($template->rulesFor(null)));

        $start = $template->rulesFor('build')[0];
        self::assertSame(ActionType::Request, $start->then->type);
        self::assertSame(['kind' => 'implement', 'capability' => 'interactive', 'limit' => 3], $start->then->params);
        self::assertNull($start->then->until);

        $wait = $template->rulesFor(null)[0];
        self::assertSame(ActionType::Pause, $wait->then->type);
        self::assertSame(['reason' => 'busy'], $wait->then->params);
        self::assertInstanceOf(Not::class, $wait->then->until);
    }

    public function test_a_state_write_needs_no_fallback(): void
    {
        foreach (['draft', 'ready', 'close'] as $write) {
            $template = self::valid();
            $template['rules'][2]['then'] = ['forge-write' => ['write' => $write]];

            self::assertSame(['write' => $write], $this->parser->parse($template)->rulesFor('review')[0]->then->params);
        }
    }

    public function test_a_request_can_expire_with_no_pause(): void
    {
        $template = self::valid();
        $template['rules'][0]['then'] = ['request' => ['kind' => 'teardown', 'onTimeout' => 'expire']];

        self::assertSame(['kind' => 'teardown', 'onTimeout' => 'expire'], $this->parser->parse($template)->rulesFor('build')[0]->then->params);
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
        yield 'wrongly typed work timeout' => [static fn (array $t): array => ['workTimeoutMinutes' => 0] + $t, 'workTimeoutMinutes: must be a positive integer'];

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
        yield 'zero request limit' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['limit'] = 0;

            return $t;
        }, 'rules[0] (start) then.request: parameter "limit" must be a positive integer'];
        yield 'unknown forge write' => [static function (array $t): array {
            $t['rules'][2]['then']['forge-write']['write'] = 'squash';

            return $t;
        }, 'rules[2] (merge) then.forge-write: parameter "write" must be one of merge, update-branch, change-base, comment, draft, ready, close'];
        yield 'merge with no fallback' => [static function (array $t): array {
            unset($t['rules'][2]['then']['forge-write']['fallback']);

            return $t;
        }, 'rules[2] (merge) then.forge-write: missing parameter "fallback"'];
        yield 'unknown work timeout behaviour' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['onTimeout'] = 'drop';

            return $t;
        }, 'rules[0] (start) then.request: parameter "onTimeout" must be one of pause, expire'];
        yield 'until outside a pause' => [static function (array $t): array {
            $t['rules'][4]['then']['release']['until'] = ['pr.open' => []];

            return $t;
        }, 'rules[4] (done) then.release: unknown parameter "until"'];

        yield 'pause with no until' => [static function (array $t): array {
            unset($t['rules'][3]['then']['pause']['until']);

            return $t;
        }, 'rules[3] (wait) then.pause: a pause must carry an "until" expression'];
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
}
