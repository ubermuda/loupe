<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller\Api;

use App\Module\Bridge\Controller\Api\ResolveExperimentPinRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResolveExperimentPinRequestTest extends TestCase
{
    public function test_valid_weights_map_each_variant_to_its_weight(): void
    {
        $request = new ResolveExperimentPinRequest('sonnet', ['opus', 'sonnet'], [1, 1_000_000]);

        self::assertSame(
            [['name' => 'opus', 'weight' => 1], ['name' => 'sonnet', 'weight' => 1_000_000]],
            $request->weights(),
        );
    }

    /** PHP turns a numeric string key into an int, so a map would lose these names. */
    public function test_numeric_variant_names_keep_their_names(): void
    {
        $request = new ResolveExperimentPinRequest('1', ['0', '1'], [1, 2]);

        self::assertSame([['name' => '0', 'weight' => 1], ['name' => '1', 'weight' => 2]], $request->weights());
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidWeights(): iterable
    {
        yield 'absent' => [null];
        yield 'not a list' => [['opus' => 1, 'sonnet' => 2]];
        yield 'a string' => ['1,2'];
        yield 'too few' => [[1]];
        yield 'too many' => [[1, 2, 3]];
        yield 'empty' => [[]];
        yield 'zero' => [[0, 2]];
        yield 'negative' => [[-1, 2]];
        yield 'too large' => [[1, 1_000_001]];
        yield 'a float' => [[1.5, 2]];
        yield 'a numeric string' => [['1', 2]];
        yield 'a boolean' => [[true, 2]];
        yield 'null in the list' => [[null, 2]];
    }

    #[DataProvider('invalidWeights')]
    public function test_invalid_weights_give_null(mixed $weights): void
    {
        $request = new ResolveExperimentPinRequest('sonnet', ['opus', 'sonnet'], $weights);

        self::assertNotNull($request->choice());
        self::assertNull($request->weights());
    }

    public function test_valid_weights_with_an_invalid_choice_give_null(): void
    {
        $request = new ResolveExperimentPinRequest('haiku', ['opus', 'sonnet'], [1, 2]);

        self::assertNull($request->choice());
        self::assertNull($request->weights());
    }

    public function test_valid_metrics_keep_their_order(): void
    {
        $request = new ResolveExperimentPinRequest('sonnet', ['opus', 'sonnet'], [1, 1], ['merge-rate', 'cost', 'unknown:key']);

        self::assertSame(['merge-rate', 'cost', 'unknown:key'], $request->metrics());
    }

    public function test_sixteen_metrics_are_kept(): void
    {
        $metrics = array_map(static fn (int $i): string => 'm'.$i, range(1, ResolveExperimentPinRequest::MAX_METRICS));

        self::assertSame($metrics, new ResolveExperimentPinRequest(metrics: $metrics)->metrics());
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidMetrics(): iterable
    {
        yield 'absent' => [null];
        yield 'empty' => [[]];
        yield 'a string' => ['cost'];
        yield 'not a list' => [['a' => 'cost']];
        yield 'too many' => [array_map(static fn (int $i): string => 'm'.$i, range(1, ResolveExperimentPinRequest::MAX_METRICS + 1))];
        yield 'a number' => [['cost', 1]];
        yield 'null in the list' => [['cost', null]];
        yield 'a capital' => [['Cost']];
        yield 'a digit first' => [['1cost']];
        yield 'too long' => [['c'.str_repeat('o', 64)]];
        yield 'a repeat' => [['cost', 'cost']];
    }

    #[DataProvider('invalidMetrics')]
    public function test_invalid_metrics_give_null(mixed $metrics): void
    {
        $request = new ResolveExperimentPinRequest('sonnet', ['opus', 'sonnet'], [1, 1], $metrics);

        self::assertNotNull($request->weights());
        self::assertNull($request->metrics());
    }
}
