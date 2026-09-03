<?php

declare(strict_types=1);

namespace App\Tests\Module\Audit;

use App\Module\Audit\ParameterAuditRetentionPolicy;
use PHPUnit\Framework\TestCase;

/** What an application that supplies no policy of its own gets from the package. */
final class ParameterAuditRetentionPolicyTest extends TestCase
{
    public function test_it_answers_with_the_configured_parameter(): void
    {
        self::assertSame(180, new ParameterAuditRetentionPolicy(180)->retentionDays());
    }

    public function test_a_window_below_one_day_is_raised_to_one(): void
    {
        self::assertSame(1, new ParameterAuditRetentionPolicy(0)->retentionDays());
        self::assertSame(1, new ParameterAuditRetentionPolicy(-5)->retentionDays());
    }
}
