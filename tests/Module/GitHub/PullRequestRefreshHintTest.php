<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub;

use App\Module\GitHub\PullRequestRefreshHint;
use PHPUnit\Framework\TestCase;

final class PullRequestRefreshHintTest extends TestCase
{
    public function test_a_plain_and_a_review_hint_for_one_number_keep_the_review(): void
    {
        self::assertEquals(
            [PullRequestRefreshHint::number(7, review: true), PullRequestRefreshHint::head('abc')],
            PullRequestRefreshHint::unique([
                PullRequestRefreshHint::number(7),
                PullRequestRefreshHint::head('abc'),
                PullRequestRefreshHint::number(7, review: true),
                PullRequestRefreshHint::number(7),
                PullRequestRefreshHint::head('abc'),
            ]),
        );
    }

    public function test_distinct_hints_all_stay(): void
    {
        $hints = [PullRequestRefreshHint::number(7), PullRequestRefreshHint::number(8, review: true), PullRequestRefreshHint::base('main')];

        self::assertEquals($hints, PullRequestRefreshHint::unique($hints));
    }
}
