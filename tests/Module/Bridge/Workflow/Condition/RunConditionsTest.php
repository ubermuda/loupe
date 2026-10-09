<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Workflow\Condition;

use App\Module\Bridge\Workflow\Condition\ParentWorkActive;
use App\Module\Bridge\Workflow\Condition\RunLastRefusal;
use App\Module\Bridge\Workflow\Condition\RunWorkActive;
use App\Module\Bridge\Workflow\Condition\RunWorkerActive;
use App\Module\Bridge\Workflow\ParentWorkFacts;
use App\Module\Bridge\Workflow\RefusalFacts;
use App\Module\Bridge\Workflow\WorkerRunFacts;
use App\Module\Bridge\Workflow\WorkRequestFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Facts;
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

        yield 'worker active, any kind' => [new RunWorkerActive(), [], self::withWorkers(activeWorkerKinds: ['breakdown']), true];
        yield 'worker active, any kind, none open' => [new RunWorkerActive(), [], self::withWorkers(), false];
        yield 'worker active, that kind' => [new RunWorkerActive(), ['kind' => 'breakdown'], self::withWorkers(activeWorkerKinds: ['breakdown', 'fix']), true];
        yield 'worker active, other kind' => [new RunWorkerActive(), ['kind' => 'breakdown'], self::withWorkers(activeWorkerKinds: ['fix']), false];
        yield 'worker active, only the parent runs' => [new RunWorkerActive(), ['kind' => 'breakdown'], self::withWorkers(parentActiveKinds: ['breakdown']), false];
        yield 'worker active, only a work request is live' => [new RunWorkerActive(), ['kind' => 'breakdown'], self::withRun(activeWorkKinds: ['breakdown']), false];

        yield 'parent work active, any kind' => [new ParentWorkActive(), [], self::withWorkers(parentActiveKinds: ['breakdown']), true];
        yield 'parent work active, any kind, none open' => [new ParentWorkActive(), [], self::withWorkers(), false];
        yield 'parent work active, that kind' => [new ParentWorkActive(), ['kind' => 'breakdown'], self::withWorkers(parentActiveKinds: ['implement', 'breakdown']), true];
        yield 'parent work active, other kind' => [new ParentWorkActive(), ['kind' => 'breakdown'], self::withWorkers(parentActiveKinds: ['implement']), false];
        yield 'parent work active, only the card runs' => [new ParentWorkActive(), ['kind' => 'breakdown'], self::withWorkers(activeWorkerKinds: ['breakdown']), false];

        yield 'last refusal, same code' => [new RunLastRefusal(), ['code' => 'no-worker'], self::withRun(lastRefusalCode: 'no-worker'), true];
        yield 'last refusal, other code' => [new RunLastRefusal(), ['code' => 'no-worker'], self::withRun(lastRefusalCode: 'dirty-tree'), false];
        yield 'last refusal, none' => [new RunLastRefusal(), ['code' => 'no-worker'], self::withRun(lastRefusalCode: null), false];
    }

    /**
     * @param array<string, mixed>          $params
     * @param array<string, string>         $expectedParameters
     * @param list<EngineFact|class-string> $expectedReads
     */
    #[DataProvider('waiting')]
    public function test_it_says_what_it_waits_for_and_what_it_reads(Condition $condition, array $params, string $expectedKey, array $expectedParameters, array $expectedReads): void
    {
        $message = $condition->waitingFor($params);

        self::assertSame('workflow.waiting.'.$expectedKey, $message->getMessage());
        self::assertSame($expectedParameters, $message->getParameters());

        $negated = $condition->waitingFor($params, negated: true);

        self::assertSame('workflow.waiting.not.'.$expectedKey, $negated->getMessage());
        self::assertSame($expectedParameters, $negated->getParameters());
        self::assertSame($expectedReads, $condition->reads($params));
    }

    /** @return iterable<string, array{Condition, array<string, mixed>, string, array<string, string>, list<EngineFact|class-string>}> */
    public static function waiting(): iterable
    {
        yield 'card.run.work_active' => [new RunWorkActive(), ['kind' => 'fix'], 'run_work_active', [], [WorkRequestFacts::class]];
        yield 'card.run.worker_active' => [new RunWorkerActive(), ['kind' => 'breakdown'], 'run_worker_active', [], [WorkerRunFacts::class]];
        yield 'card.parent.run.active' => [new ParentWorkActive(), ['kind' => 'breakdown'], 'parent_work_active', [], [ParentWorkFacts::class]];
        yield 'card.run.last_refusal' => [new RunLastRefusal(), ['code' => 'no-worker'], 'run_last_refusal', ['%code%' => 'no-worker'], [RefusalFacts::class]];
    }

    /** @param list<string> $activeWorkKinds */
    private static function withRun(array $activeWorkKinds = [], ?string $lastRefusalCode = null): Facts
    {
        return FactsMother::facts(run: FactsMother::run(activeWorkKinds: $activeWorkKinds, lastRefusalCode: $lastRefusalCode));
    }

    /**
     * @param list<string> $activeWorkerKinds
     * @param list<string> $parentActiveKinds
     */
    private static function withWorkers(array $activeWorkerKinds = [], array $parentActiveKinds = []): Facts
    {
        return FactsMother::facts(run: FactsMother::run(activeWorkerKinds: $activeWorkerKinds, parentActiveKinds: $parentActiveKinds));
    }
}
