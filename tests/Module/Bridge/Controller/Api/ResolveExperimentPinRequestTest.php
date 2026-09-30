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
}
