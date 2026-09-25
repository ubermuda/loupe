<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\View;

use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\View\CardRunWarning;
use PHPUnit\Framework\TestCase;

final class CardRunWarningTest extends TestCase
{
    public function test_it_applies_in_the_column_that_started_the_run_only(): void
    {
        $warning = new CardRunWarning('run', WorkerRunState::GaveUp, 'summary', 'implementation');

        self::assertTrue($warning->appliesTo('implementation'));
        self::assertFalse($warning->appliesTo('review'));
    }

    /** A run from an older bridge names no column, so the warning holds in any column. */
    public function test_a_run_with_no_column_applies_in_every_column(): void
    {
        $warning = new CardRunWarning('run', WorkerRunState::Blocked, 'summary', null);

        self::assertTrue($warning->appliesTo('implementation'));
        self::assertTrue($warning->appliesTo('review'));
    }
}
