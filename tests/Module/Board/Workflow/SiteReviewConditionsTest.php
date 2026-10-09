<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Board\Workflow\CheckStale;
use App\Module\Board\Workflow\CheckWanted;
use App\Module\Board\Workflow\SiteReviewFacts;
use App\Module\Board\Workflow\VerdictUnsent;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SiteReviewConditionsTest extends TestCase
{
    public function test_a_verdict_is_unsent_only_while_a_delivery_is_pending(): void
    {
        $condition = new VerdictUnsent();

        self::assertSame([SiteReviewFacts::class], $condition->reads([]));
        self::assertTrue($condition->evaluate(self::facts(new SiteReviewFacts(['delivery'], [])), []));
        self::assertFalse($condition->evaluate(self::facts(new SiteReviewFacts([], [])), []));
    }

    #[DataProvider('checks')]
    public function test_a_check_is_stale_when_it_differs_from_the_wanted_one(bool $stale, ?CheckWanted $check, bool $optedIn): void
    {
        $condition = new CheckStale();
        $facts = new SiteReviewFacts([], null === $check ? [] : ['pull-request' => $check], $optedIn);

        self::assertSame([SiteReviewFacts::class], $condition->reads([]));
        self::assertSame($stale, $condition->evaluate(self::facts($facts), []));
    }

    /** @return iterable<string, array{bool, ?CheckWanted, bool}> */
    public static function checks(): iterable
    {
        yield 'no open pull request' => [false, null, true];
        yield 'never posted' => [true, new CheckWanted('sha-1', 'success', 0, null, null, null, null, 'd1', null), false];
        yield 'posted and current' => [false, new CheckWanted('sha-1', 'success', 0, 'sha-1', 'success', 5, 0, 'd1', 'd1'), true];
        yield 'the head moved' => [true, new CheckWanted('sha-2', 'success', 0, 'sha-1', 'success', 5, 0, 'd1', 'd1'), false];
        yield 'the conclusion changed' => [true, new CheckWanted('sha-1', 'success', 0, 'sha-1', 'failure', 5, 0, 'd1', 'd1'), false];
        yield 'the note count changed' => [true, new CheckWanted('sha-1', 'failure', 3, 'sha-1', 'failure', 5, 2, 'd1', 'd1'), false];
        yield 'the notes changed with the same count' => [true, new CheckWanted('sha-1', 'failure', 2, 'sha-1', 'failure', 5, 2, 'd2', 'd1'), false];
        yield 'a row from before the digest' => [true, new CheckWanted('sha-1', 'success', 0, 'sha-1', 'success', 5, 0, 'd1', null), false];
        yield 'opted in and the row has no run' => [true, new CheckWanted('sha-1', 'success', 0, 'sha-1', 'success', null, 0, 'd1', 'd1'), true];
        yield 'opted out and the row has no run' => [false, new CheckWanted('sha-1', 'success', 0, 'sha-1', 'success', null, 0, 'd1', 'd1'), false];
    }

    public function test_one_stale_pull_request_makes_the_condition_true(): void
    {
        $facts = new SiteReviewFacts([], [
            'a' => new CheckWanted('sha-1', 'success', 0, 'sha-1', 'success', 1, 0, 'd1', 'd1'),
            'b' => new CheckWanted('sha-2', 'success', 0, 'sha-1', 'success', 2, 0, 'd1', 'd1'),
        ]);

        self::assertTrue(new CheckStale()->evaluate(self::facts($facts), []));
    }

    private static function facts(SiteReviewFacts $site): \App\Module\Workflow\Contract\Facts
    {
        return FactsMother::facts(provided: [SiteReviewFacts::class => $site]);
    }
}
