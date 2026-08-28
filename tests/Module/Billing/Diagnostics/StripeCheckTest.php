<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Diagnostics;

use App\Module\Billing\Diagnostics\StripeCheck;
use App\Tests\Support\FeatureFlags;
use PHPUnit\Framework\TestCase;
use Ubermuda\HealthCheckBundle\DiagnosticState;

final class StripeCheckTest extends TestCase
{
    public function test_stripe_is_not_checked_when_billing_is_off(): void
    {
        $check = new StripeCheck(FeatureFlags::service(), null, null);

        self::assertNull($check());
    }

    public function test_billing_without_stripe_credentials_is_a_failure(): void
    {
        $check = new StripeCheck(FeatureFlags::service(['billing.enabled' => true]), null, null);

        $diagnostic = $check();

        self::assertNotNull($diagnostic);
        self::assertSame(DiagnosticState::Failed, $diagnostic->state);
        self::assertSame(
            ['%variables%' => 'STRIPE_SECRET_KEY, STRIPE_WEBHOOK_SECRET'],
            $diagnostic->detailParameters,
        );
    }

    public function test_billing_with_both_stripe_keys_passes(): void
    {
        $check = new StripeCheck(
            FeatureFlags::service(['billing.enabled' => true]),
            'sk_test_dummy',
            'whsec_test',
        );

        $diagnostic = $check();

        self::assertNotNull($diagnostic);
        self::assertSame(DiagnosticState::Ok, $diagnostic->state);
    }

    /** The label the report renders is derived from the key, not stored on the check. */
    public function test_the_check_reports_in_the_application_catalogue(): void
    {
        $diagnostic = new StripeCheck(FeatureFlags::service(['billing.enabled' => true]), null, null)();

        self::assertNotNull($diagnostic);
        self::assertSame('stripe', $diagnostic->key);
        self::assertSame('messages', $diagnostic->domain);
    }
}
