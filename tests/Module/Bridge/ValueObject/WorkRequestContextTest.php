<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\ValueObject;

use App\Module\Bridge\ValueObject\WorkRequestContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkRequestContextTest extends TestCase
{
    public function test_a_context_round_trips_through_its_array(): void
    {
        $context = new WorkRequestContext(42, 'https://github.com/acme/widgets/pull/42', 'abc1234', 'checks-failed', '01a10beb-ba65-736b-8626-a6e3fa59dfc5');

        self::assertSame([
            'pullRequestNumber' => 42,
            'pullRequestUrl' => 'https://github.com/acme/widgets/pull/42',
            'headSha' => 'abc1234',
            'reason' => 'checks-failed',
            'documentId' => '01a10beb-ba65-736b-8626-a6e3fa59dfc5',
        ], $context->toArray());
        self::assertEquals($context, WorkRequestContext::fromArray($context->toArray()));
    }

    public function test_an_empty_context_holds_a_null_for_each_key(): void
    {
        self::assertSame(
            ['pullRequestNumber' => null, 'pullRequestUrl' => null, 'headSha' => null, 'reason' => null, 'documentId' => null],
            new WorkRequestContext()->toArray(),
        );
    }

    public function test_a_null_column_is_an_empty_context(): void
    {
        self::assertEquals(new WorkRequestContext(), WorkRequestContext::fromArray(null));
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function malformedArrays(): iterable
    {
        $valid = ['pullRequestNumber' => 1, 'pullRequestUrl' => null, 'headSha' => null, 'reason' => null, 'documentId' => null];

        yield 'a missing key' => [['pullRequestNumber' => 1, 'pullRequestUrl' => null, 'headSha' => null, 'reason' => null]];
        yield 'an unknown key' => [$valid + ['tag' => null]];
        yield 'a document id that is a number' => [['documentId' => 7] + $valid];
        yield 'a number that is a string' => [['pullRequestNumber' => '1'] + $valid];
        yield 'a url that is a number' => [['pullRequestUrl' => 5] + $valid];
        yield 'a sha that is a list' => [['headSha' => ['abc1234']] + $valid];
        yield 'a reason that is a bool' => [['reason' => true] + $valid];
    }

    /** @param array<mixed> $data */
    #[DataProvider('malformedArrays')]
    public function test_a_malformed_array_is_refused(array $data): void
    {
        $this->expectException(\UnexpectedValueException::class);

        WorkRequestContext::fromArray($data);
    }

    /** @return iterable<string, array{?int, ?string, ?string, ?string, ?string}> */
    public static function invalidValues(): iterable
    {
        yield 'a zero number' => [0, null, null, null, null];
        yield 'a number past a 32-bit integer' => [2147483648, null, null, null, null];
        yield 'a url that is not https' => [null, 'http://github.com/acme/widgets/pull/1', null, null, null];
        yield 'a url with a space' => [null, 'https://github.com/acme/widgets/pull/1 now', null, null, null];
        yield 'a url with no host' => [null, 'https:///pull/1', null, null, null];
        yield 'a url past 2000 characters' => [null, 'https://github.com/'.str_repeat('a', 2000), null, null, null];
        yield 'a short sha' => [null, null, 'abc12', null, null];
        yield 'an upper-case sha' => [null, null, 'ABC1234', null, null];
        yield 'a reason with a space' => [null, null, null, 'checks failed', null];
        yield 'a document id that is not a uuid' => [null, null, null, null, 'design'];
        yield 'an upper-case document id' => [null, null, null, null, '01A10BEB-BA65-736B-8626-A6E3FA59DFC5'];
    }

    #[DataProvider('invalidValues')]
    public function test_an_invalid_value_is_refused(?int $number, ?string $url, ?string $sha, ?string $reason, ?string $documentId): void
    {
        $this->expectException(\UnexpectedValueException::class);

        new WorkRequestContext($number, $url, $sha, $reason, $documentId);
    }

    public function test_the_accepts_checks_tell_a_valid_value_from_an_invalid_one(): void
    {
        self::assertTrue(WorkRequestContext::acceptsUrl('https://github.com/acme/widgets/pull/1'));
        self::assertFalse(WorkRequestContext::acceptsUrl('javascript:alert(1)'));
        self::assertTrue(WorkRequestContext::acceptsHeadSha('0123456789abcdef'));
        self::assertFalse(WorkRequestContext::acceptsHeadSha('not-a-sha'));
    }
}
