<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Condition;

use App\Module\Workflow\Condition\Condition;
use App\Module\Workflow\Condition\RunLastRefusal;
use App\Module\Workflow\Condition\RunWorkActive;
use App\Module\Workflow\Fact\FactKey;
use App\Module\Workflow\Fact\Facts;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RunConditionsTest extends TestCase
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
        yield 'work active, any kind' => [new RunWorkActive(), [], self::withRun(activeWorkKinds: ['fix']), true];
        yield 'work active, any kind, none active' => [new RunWorkActive(), [], self::withRun(activeWorkKinds: []), false];
        yield 'work active, that kind' => [new RunWorkActive(), ['kind' => 'fix'], self::withRun(activeWorkKinds: ['implement', 'fix']), true];
        yield 'work active, other kind' => [new RunWorkActive(), ['kind' => 'fix'], self::withRun(activeWorkKinds: ['implement']), false];

        yield 'last refusal, same code' => [new RunLastRefusal(), ['code' => 'no-worker'], self::withRun(lastRefusalCode: 'no-worker'), true];
        yield 'last refusal, other code' => [new RunLastRefusal(), ['code' => 'no-worker'], self::withRun(lastRefusalCode: 'dirty-tree'), false];
        yield 'last refusal, none' => [new RunLastRefusal(), ['code' => 'no-worker'], self::withRun(lastRefusalCode: null), false];
    }

    /**
     * @param array<string, mixed>  $params
     * @param array<string, string> $expectedParameters
     * @param list<FactKey>         $expectedReads
     */
    #[DataProvider('waiting')]
    public function test_it_says_what_it_waits_for_and_what_it_reads(Condition $condition, array $params, array $expectedParameters, array $expectedReads): void
    {
        $message = $condition->waitingFor($params);

        self::assertSame('workflow.waiting.'.str_replace('.', '_', $condition::key()), $message->getMessage());
        self::assertSame($expectedParameters, $message->getParameters());

        $negated = $condition->waitingFor($params, negated: true);

        self::assertSame('workflow.waiting.not.'.str_replace('.', '_', $condition::key()), $negated->getMessage());
        self::assertSame($expectedParameters, $negated->getParameters());
        self::assertSame($expectedReads, $condition->reads($params));
    }

    /** @return iterable<string, array{Condition, array<string, mixed>, array<string, string>, list<FactKey>}> */
    public static function waiting(): iterable
    {
        yield 'run.work_active' => [new RunWorkActive(), ['kind' => 'fix'], [], [FactKey::WorkRequests]];
        yield 'run.last_refusal' => [new RunLastRefusal(), ['code' => 'no-worker'], ['%code%' => 'no-worker'], [FactKey::Refusal]];
    }

    /** @param list<string> $activeWorkKinds */
    private static function withRun(array $activeWorkKinds = [], ?string $lastRefusalCode = null): Facts
    {
        return FactsMother::facts(run: FactsMother::run(activeWorkKinds: $activeWorkKinds, lastRefusalCode: $lastRefusalCode));
    }
}
