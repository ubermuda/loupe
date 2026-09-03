<?php

declare(strict_types=1);

namespace App\Tests\Audit;

use App\Audit\FeatureFlagAuditRetentionPolicy;
use App\Tests\Support\FeatureFlags;
use PHPUnit\Framework\TestCase;

/**
 * Seeding never reaches an instance that is already installed, so the coded
 * fallback is what most instances actually run on.
 */
final class FeatureFlagAuditRetentionPolicyTest extends TestCase
{
    public function test_an_instance_with_no_flag_row_keeps_the_parameter_window(): void
    {
        self::assertSame(180, new FeatureFlagAuditRetentionPolicy(FeatureFlags::service(), 180)->retentionDays());
    }

    public function test_the_flag_replaces_the_parameter_once_an_operator_sets_one(): void
    {
        $policy = new FeatureFlagAuditRetentionPolicy(
            FeatureFlags::service([FeatureFlagAuditRetentionPolicy::FLAG => 30]),
            180,
        );

        self::assertSame(30, $policy->retentionDays());
    }

    public function test_a_flag_of_the_wrong_type_falls_back_rather_than_emptying_the_trail(): void
    {
        $policy = new FeatureFlagAuditRetentionPolicy(
            FeatureFlags::service([FeatureFlagAuditRetentionPolicy::FLAG => true]),
            180,
        );

        self::assertSame(180, $policy->retentionDays());
    }

    /** Zero would delete every record on the next sweep, and a negative number makes DateInterval throw. */
    public function test_a_window_below_one_day_is_raised_to_one(): void
    {
        $zero = new FeatureFlagAuditRetentionPolicy(FeatureFlags::service([FeatureFlagAuditRetentionPolicy::FLAG => 0]), 180);
        $negative = new FeatureFlagAuditRetentionPolicy(FeatureFlags::service([FeatureFlagAuditRetentionPolicy::FLAG => -5]), 180);

        self::assertSame(1, $zero->retentionDays());
        self::assertSame(1, $negative->retentionDays());
    }
}
