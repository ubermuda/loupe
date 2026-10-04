<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\View;

use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\View\WorkerRunListQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class WorkerRunListQueryTest extends TestCase
{
    public function test_the_open_word_selects_the_open_runs_and_no_single_state(): void
    {
        $query = WorkerRunListQuery::fromQuery(Request::create('/?outcome=open')->query);

        self::assertTrue($query->open);
        self::assertNull($query->state);
        self::assertTrue($query->isNarrowed());
        self::assertSame(['page' => 1, 'outcome' => 'open'], $query->routeParams());
        self::assertSame(['page' => 2, 'outcome' => 'open'], $query->withPage(2)->routeParams());
    }

    public function test_a_state_word_selects_that_state_alone(): void
    {
        $query = WorkerRunListQuery::fromQuery(Request::create('/?outcome=queued')->query);

        self::assertFalse($query->open);
        self::assertSame(WorkerRunState::Queued, $query->state);
        self::assertSame(['page' => 1, 'outcome' => 'queued'], $query->routeParams());
    }

    public function test_no_outcome_leaves_the_list_whole(): void
    {
        $query = WorkerRunListQuery::fromQuery(Request::create('/')->query);

        self::assertFalse($query->open);
        self::assertFalse($query->isNarrowed());
        self::assertSame(['page' => 1], $query->routeParams());
    }

    /** @return iterable<string, array{WorkerRunListQuery}> */
    public static function agentFilters(): iterable
    {
        yield 'states' => [new WorkerRunListQuery(states: [WorkerRunState::Failed])];
        yield 'card number' => [new WorkerRunListQuery(cardNumber: 7)];
        yield 'work kind' => [new WorkerRunListQuery(workKind: 'plan')];
        yield 'ended after' => [new WorkerRunListQuery(endedAfter: new \DateTimeImmutable('2026-09-01'))];
        yield 'ended before' => [new WorkerRunListQuery(endedBefore: new \DateTimeImmutable('2026-09-01'))];
    }

    /** The web page never sets these filters, so they stay out of its URL. */
    #[DataProvider('agentFilters')]
    public function test_an_agent_filter_narrows_the_list_and_leaves_the_url_bare(WorkerRunListQuery $query): void
    {
        self::assertTrue($query->isNarrowed());
        self::assertSame(['page' => 1], $query->routeParams());
    }
}
