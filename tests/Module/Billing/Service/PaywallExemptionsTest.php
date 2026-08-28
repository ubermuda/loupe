<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Service;

use App\Module\Billing\Service\PaywallExemptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaywallExemptionsTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function routes(): iterable
    {
        yield 'listed by name' => ['app_billing_subscribe', true];
        yield 'dev-only escape route' => ['app_dev_billing_state', true];
        yield 'admin prefix' => ['app_admin_users_list', true];
        yield 'feature flags bundle prefix' => ['ubermuda_feature_flags_admin_list', true];
        yield 'an ordinary application route' => ['app_project_list', false];
        yield 'a name that only looks like the admin prefix' => ['app_administration_list', false];
        yield 'a name the exemption list no longer matches' => ['app_billing_subscribe_v2', false];
    }

    #[DataProvider('routes')]
    public function test_exemption_is_deny_by_default(string $route, bool $exempt): void
    {
        self::assertSame($exempt, new PaywallExemptions()->exempts($route));
    }
}
