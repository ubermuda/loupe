<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\View;

use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\View\WorkerRunListQuery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

final class WorkerRunListQueryTest extends TestCase
{
    public function test_the_open_word_selects_the_open_runs_and_no_single_state(): void
    {
        $query = WorkerRunListQuery::fromQuery(new InputBag(['outcome' => 'open']));

        self::assertTrue($query->open);
        self::assertNull($query->state);
        self::assertTrue($query->isNarrowed());
        self::assertSame(['page' => 1, 'outcome' => 'open'], $query->routeParams());
        self::assertSame(['page' => 2, 'outcome' => 'open'], $query->withPage(2)->routeParams());
    }

    public function test_a_state_word_selects_that_state_alone(): void
    {
        $query = WorkerRunListQuery::fromQuery(new InputBag(['outcome' => 'queued']));

        self::assertFalse($query->open);
        self::assertSame(WorkerRunState::Queued, $query->state);
        self::assertSame(['page' => 1, 'outcome' => 'queued'], $query->routeParams());
    }

    public function test_no_outcome_leaves_the_list_whole(): void
    {
        $query = WorkerRunListQuery::fromQuery(new InputBag([]));

        self::assertFalse($query->open);
        self::assertFalse($query->isNarrowed());
        self::assertSame(['page' => 1], $query->routeParams());
    }
}
