<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Metric;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MetricKeyTest extends TestCase
{
    public function test_a_standalone_key_parses_with_no_bucket_name(): void
    {
        foreach (Metric::standalone() as $metric) {
            $key = MetricKey::tryParse($metric->value);

            self::assertNotNull($key);
            self::assertSame($metric, $key->metric);
            self::assertNull($key->bucketName);
            self::assertSame($metric->value, $key->key());
        }
    }

    public function test_a_bucket_key_parses_with_its_bucket_name(): void
    {
        $key = MetricKey::tryParse('bucket-time:git_push-2');

        self::assertNotNull($key);
        self::assertSame(Metric::BucketTime, $key->metric);
        self::assertSame('git_push-2', $key->bucketName);
        self::assertSame('bucket-time:git_push-2', $key->key());
    }

    public function test_a_bucket_name_of_64_characters_parses(): void
    {
        $name = str_repeat('a', 64);

        self::assertSame($name, MetricKey::tryParse('bucket-time:'.$name)?->bucketName);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidKeys(): iterable
    {
        yield 'unknown' => ['speed'];
        yield 'empty' => [''];
        yield 'bucket time with no name' => ['bucket-time'];
        yield 'bucket time with an empty name' => ['bucket-time:'];
        yield 'upper case name' => ['bucket-time:Tests'];
        yield 'name with a space' => ['bucket-time:a b'];
        yield 'name with a new line' => ["bucket-time:tests\n"];
        yield 'name of 65 characters' => ['bucket-time:'.str_repeat('a', 65)];
        yield 'standalone metric with a name' => ['cost:tests'];
    }

    #[DataProvider('invalidKeys')]
    public function test_an_invalid_key_gives_null(string $value): void
    {
        self::assertNull(MetricKey::tryParse($value));
    }

    public function test_the_constructor_refuses_a_key_that_the_parser_refuses(): void
    {
        foreach ([[Metric::BucketTime, null], [Metric::BucketTime, 'Tests'], [Metric::Cost, 'tests']] as [$metric, $name]) {
            try {
                new MetricKey($metric, $name);
                self::fail('Expected a refusal.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
